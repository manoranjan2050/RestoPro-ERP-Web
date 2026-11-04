#!/usr/bin/env bash
# Deploy RestoPro ERP to shared hosting over SSH (tested on Hostinger; works on any host with PHP 8.2+, SSH and Composer).
#
#   ~/$APP_DIR/            Laravel app (code, .env, storage) — NOT web reachable
#   ~/$WEB_DIR             symlink → ~/$APP_DIR/public   (the folder your (sub)domain serves)
#
# One-time: copy deploy/deploy.env.example to deploy/deploy.env (gitignored) and fill in your server.
# Usage (from the project root, Git Bash / Linux / macOS):
#   ./deploy/deploy.sh             # deploy current HEAD (commit first!)
#   FIRST=1 ./deploy/deploy.sh     # first time: also links the web folder to the app
#
# The server .env is created by hand once (see deploy/env.production.example) and is never overwritten.
set -euo pipefail

cd "$(dirname "$0")/.."
[ -f deploy/deploy.env ] && . deploy/deploy.env
: "${DEPLOY_HOST:?Set DEPLOY_HOST (user@host) in deploy/deploy.env}"
DEPLOY_PORT="${DEPLOY_PORT:-22}"
DEPLOY_KEY="${DEPLOY_KEY:-$HOME/.ssh/id_ed25519}"
APP_DIR="${APP_DIR:-restopro-app}"
: "${WEB_DIR:?Set WEB_DIR (web folder relative to the home directory, e.g. public_html/erp) in deploy/deploy.env}"
HOST="$DEPLOY_HOST"; PORT="$DEPLOY_PORT"; KEY="$DEPLOY_KEY"
SSH=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes "$HOST")

[ -z "$(git status --porcelain)" ] || { echo "✗ Uncommitted changes — commit before deploying."; exit 1; }

echo "→ Building front-end assets"
npm run build >/dev/null

echo "→ Packing $(git rev-parse --short HEAD)"
PKG="$(mktemp -d)/restopro.tar"
git archive --format=tar -o "$PKG" HEAD
tar -rf "$PKG" public/build
gzip -f "$PKG"

echo "→ Uploading"
scp -i "$KEY" -P "$PORT" -q "$PKG.gz" "$HOST:restopro-release.tar.gz"

echo "→ Installing on server"
"${SSH[@]}" bash -s -- "$APP_DIR" "$WEB_DIR" "${FIRST:-0}" <<'REMOTE'
set -euo pipefail
APP="$HOME/$1"; WEB="$HOME/$2"; FIRST="$3"
mkdir -p "$APP"
cd "$APP"
[ -f artisan ] && php artisan down --retry=30 >/dev/null 2>&1 || true

# Replace code but keep .env and storage/ (uploads, backups, logs).
find . -mindepth 1 -maxdepth 1 ! -name .env ! -name storage -exec rm -rf {} +
tar -xzf "$HOME/restopro-release.tar.gz" --exclude='storage/*' && rm -f "$HOME/restopro-release.tar.gz"
mkdir -p storage/app/public/{avatars,staff,logos} storage/app/private/{bills,backups} \
         storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chmod -R u+rwX,go-w storage bootstrap/cache
chmod 600 .env 2>/dev/null || true

[ -f .env ] || { echo "✗ $APP/.env missing — create it from deploy/env.production.example first."; exit 1; }

# Packages first — artisan can't run without vendor/.
if [ -f "$HOME/composer.phar" ]; then COMPOSER=(php "$HOME/composer.phar"); else COMPOSER=(composer); fi
"${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1 | tail -2
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear >/dev/null
php artisan optimize >/dev/null

if [ "$FIRST" = "1" ] || [ ! -L "$WEB" ]; then
  if [ -e "$WEB" ] && [ ! -L "$WEB" ]; then mv "$WEB" "$WEB.bak.$(date +%s)"; fi
  ln -sfn "$APP/public" "$WEB"
  echo "  linked $WEB → $APP/public"
fi

php artisan up >/dev/null
echo "✓ Deployed $(date '+%d %b %Y %H:%M')"
REMOTE
