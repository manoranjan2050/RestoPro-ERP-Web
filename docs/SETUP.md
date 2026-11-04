# 🛠 Setup guide

This guide takes RestoPro ERP from zero to a live site with HTTPS, daily backups and your own owner login.

| Part | What it covers | Time |
|---|---|---|
| [1. Requirements](#1-requirements) | PHP, database, tools | — |
| [2. Run it on your computer](#2-run-it-on-your-computer) | try it, develop, run tests | 10 min |
| [3. Shared hosting (Hostinger / cPanel)](#3-put-it-online-on-shared-hosting-hostinger--cpanel) | the cheap and easy way to go live | 30 min |
| [4. VPS (Nginx)](#4-put-it-online-on-a-vps-nginx) | your own server | 30 min |
| [5. After going live](#5-after-going-live) | first sign-in, settings, backups, staff | 15 min |
| [5b. Thermal printer](#5b-thermal-printer-pos-advanced-mode) | receipt printing from the POS | 10 min |
| [6. Updating](#6-updating-to-a-new-version) | pulling new versions safely | 2 min |
| [7. Troubleshooting](#7-troubleshooting) | common problems | — |

---

## 1. Requirements

| | Minimum | Notes |
|---|---|---|
| PHP | **8.2** (8.3 works) | extensions: `pdo_mysql` or `pdo_sqlite`, `mbstring`, `openssl`, `zip`, `gd`, `fileinfo`, `intl`, `curl`, `sodium` |
| Database | MySQL 8 / MariaDB 10.6+ | SQLite is fine for a single computer or for trying it out |
| Composer | 2.x | installs the PHP packages |
| Node.js | 20+ | **only on the machine that builds the CSS/JS**, not on the server |
| Disk | ~150 MB + your bill photos and backups | |

---

## 2. Run it on your computer

```bash
git clone https://github.com/manoranjan2050/RestoPro-ERP-Web.git
cd RestoPro-ERP-Web
composer install
npm install
npm run build
cp .env.example .env            # Windows (cmd): copy .env.example .env
php artisan key:generate
```

**Database.** The default is SQLite, so just create the empty file:

```bash
touch database/database.sqlite   # Windows (cmd): type nul > database\database.sqlite
```

<details>
<summary>Prefer MySQL locally?</summary>

Create an empty database, then set these in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=restopro
DB_USERNAME=root
DB_PASSWORD=your-password
```
</details>

**Owner account.** In `.env`, fill in the first login (you can sign in with any of username, mobile or email):

```dotenv
ADMIN_NAME="Your Name"
ADMIN_USERNAME=admin
ADMIN_MOBILE=9876543210
ADMIN_EMAIL=
ADMIN_PASSWORD=choose-a-strong-password
```

Then create the tables and the owner, and start the app:

```bash
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder     # optional sample data (refuses to run in production)
php artisan serve
```

Open <http://127.0.0.1:8000>. For live reload while editing views, run `npm run dev` in a second terminal.

**Tests:** `php artisan test` (124 feature tests, using an in-memory database).

---

## 3. Put it online on shared hosting (Hostinger / cPanel)

The layout below keeps the code, `.env`, uploads and backups **outside** the web root. Only `public/` is reachable:

```
~/restopro-app/            ← the Laravel app (code, .env, storage)  — not reachable from the web
~/public_html/erp          ← symlink → ~/restopro-app/public       — what https://erp.example.com serves
```

### 3.1 In your hosting panel (once)

1. **Subdomain.** Create e.g. `erp.example.com`. The panel makes a folder such as `public_html/erp`; the deploy script turns it into a link.
   *Hostinger:* Domains → Subdomains. Addon domains live under `domains/example.com/public_html/…`.
2. **PHP version.** Choose **8.2 or 8.3** for that (sub)domain and check that the extensions above are on.
3. **SSL.** Install the free Let's Encrypt certificate and turn on **Force HTTPS**.
   *Using Cloudflare DNS?* Add an `A` record for `erp` pointing to your server IP. Leave it **DNS only** (grey cloud) until the certificate is issued.
4. **Database.** Create a MySQL database and a user with **all privileges**. Write down the name, user and password. Hostinger adds an account prefix such as `u123456789_`.
5. **SSH access.** Turn on SSH and add your public key (`~/.ssh/id_ed25519.pub`). Note the host, port and username.
   *Hostinger uses port 65002.*

### 3.2 Configure the deploy script (on your computer, once)

```bash
cp deploy/deploy.env.example deploy/deploy.env    # gitignored, never committed
```

```dotenv
DEPLOY_HOST="u123456789@203.0.113.10"
DEPLOY_PORT=65002
DEPLOY_KEY="$HOME/.ssh/id_ed25519"
APP_DIR="restopro-app"
WEB_DIR="public_html/erp"          # Hostinger addon domain: domains/example.com/public_html/erp
```

Check that `ssh -i ~/.ssh/id_ed25519 -p 65002 u123456789@203.0.113.10` logs in without asking for a password.

### 3.3 Create the server `.env` (once)

Copy [`deploy/env.production.example`](../deploy/env.production.example) to the server as `~/restopro-app/.env`, fill in the `<…>` parts, and lock it down:

```bash
ssh -p 65002 u123456789@203.0.113.10 "mkdir -p ~/restopro-app"
scp -P 65002 deploy/env.production.example u123456789@203.0.113.10:restopro-app/.env
ssh -p 65002 u123456789@203.0.113.10 "nano ~/restopro-app/.env && chmod 600 ~/restopro-app/.env"
```

Set at least `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and the `ADMIN_*` owner login. Leave `APP_KEY` empty: the first deploy generates it.

### 3.4 First deploy

The deploy script needs **Git Bash** on Windows, or any terminal on Linux/macOS. Commit your work first; the script refuses to deploy uncommitted changes.

```bash
FIRST=1 ./deploy/deploy.sh
```

It builds the CSS/JS, packs the committed code, uploads it, then on the server:
1. puts the site in maintenance mode
2. replaces the code, keeping `.env` and `storage/`
3. runs `composer install --no-dev`
4. generates `APP_KEY`
5. migrates the database and caches the config
6. links the web folder
7. brings the site back up

Then create the owner account **once**:

```bash
ssh -p 65002 u123456789@203.0.113.10 "cd ~/restopro-app && php artisan db:seed --force"
```

Afterwards, **remove `ADMIN_PASSWORD` from the server `.env`**. Change the password any time later in *Profile*.

> If the server has no global `composer`, put `composer.phar` in your home directory:
> `curl -sS https://getcomposer.org/installer | php -- --install-dir=$HOME`

### 3.5 Cron job, for daily automatic backups (once)

Add a cron job that runs **every minute**. *Hostinger:* Advanced → Cron Jobs. *cPanel:* Cron Jobs.

```
cd /home/<your-user>/restopro-app && php artisan schedule:run >> /dev/null 2>&1
```

The app decides what actually runs; by default that's a backup at 02:00. *Backup & Sync → Schedule* shows the exact line for your server.

### 3.6 Check it

- `https://erp.example.com` redirects to HTTPS and shows the sign-in page
- `https://erp.example.com/.env` returns **404** (if it doesn't, your web root points at the wrong folder)
- Sign in. Add a collection and an expense with a bill photo. Download a PDF report. Press **Back up now**.

---

## 4. Put it online on a VPS (Nginx)

```bash
sudo apt install nginx mariadb-server php8.3-fpm php8.3-{mysql,mbstring,xml,zip,gd,intl,curl,bcmath} composer unzip
sudo mysql -e "CREATE DATABASE restopro; CREATE USER 'restopro'@'localhost' IDENTIFIED BY 'change-me'; GRANT ALL ON restopro.* TO 'restopro'@'localhost';"

sudo git clone https://github.com/manoranjan2050/RestoPro-ERP-Web.git /var/www/restopro
cd /var/www/restopro
sudo cp deploy/env.production.example .env && sudo nano .env      # fill in DB + APP_URL + ADMIN_*
sudo composer install --no-dev --optimize-autoloader
sudo php artisan key:generate && sudo php artisan migrate --seed --force && sudo php artisan optimize
npm ci && npm run build          # or build on your PC and upload public/build
sudo chown -R www-data:www-data storage bootstrap/cache
```

Nginx site (`/etc/nginx/sites-available/restopro`):

```nginx
server {
    listen 80;
    server_name erp.example.com;
    root /var/www/restopro/public;
    index index.php;
    client_max_body_size 64M;               # bill photos + backup uploads

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

Then enable HTTPS with `sudo certbot --nginx -d erp.example.com`, and add the cron line from [3.5](#35-cron-job-for-daily-automatic-backups-once) using `crontab -u www-data -e`.

---

## 5. After going live

1. **Sign in** with the owner login. If you seeded with a temporary password, **change it** in *Profile*.
2. **Settings → Business.** Enter the name, logo, address, phone, GSTIN and FSSAI number. They appear on PDFs, in the menu and on the staff share page.
3. **Settings → Money & year.** Check the currency symbol and the financial-year start month (April by default).
4. **Settings → Users & access.** Add a **Manager** login for whoever enters the daily figures. Managers can't delete records, back up, restore or change settings.
5. **Expenses → Categories** and **Vendors.** Add your suppliers with their **opening balance** (what you owe them today).
6. **Staff.** Add each person with their monthly salary or daily wage and their **opening balance**. Choose whether you owe them that amount or they already hold it as an advance.
7. **Backup & Sync.** Connect Google Drive, Dropbox or a private GitHub repo. Step-by-step: [BACKUP_SETUP.md](BACKUP_SETUP.md).

> **How staff pay is counted.** Monthly salary ÷ days in the month = one day's pay.
> - Unmarked days are counted as worked.
> - **A** (absent) removes one day's pay, **H** (half day) removes half.
> - Daily wage = rate × (present days + ½ × half days).
>
> Each month's closing balance carries forward to the next month.

### Moving from the old "Resto Pro" PHP app

```bash
php artisan restopro:import-old storage/app/private/RESTO_FULL_xxxx.sql --dry-run   # shows totals, writes nothing
php artisan restopro:import-old storage/app/private/RESTO_FULL_xxxx.sql             # imports
```

The importer brings in:
- **Daily sales.** Old "online" becomes **UPI**; pass `--online=online` to change that.
- **Expenses and categories.**
- **Staff, attendance and advances.**

Before you import:
- Take a backup.
- Delete the `.sql` file from the server afterwards.

The command refuses to import into a database that already has sales, expenses or staff.

---

## 5b. Thermal printer (POS, Advanced mode)

The POS prints receipts and kitchen tickets (KOT) through the browser. Any **58 mm or 80 mm thermal receipt printer**
with a Windows driver works: USB, LAN or Bluetooth paired to the PC (Epson TM-series, TVS RP-series, Rugtek, Xprinter…).

1. **Install the printer driver** from the maker's site. In *Printer preferences*, set the paper to 80 mm (or 58 mm)
   with continuous or receipt length, then print a Windows test page.
2. **Settings → Printing** in RestoPro: choose the same paper width, write your footer message, and tick
   *Print the bill automatically after Save bill*.
3. The first time, Chrome's print box appears. Pick the thermal printer, set **Margins: None** and **Scale: 100**, and
   untick *Headers and footers*. Chrome remembers these settings.

**One-click printing, no dialog (recommended at the counter):**

1. Make the thermal printer the **Windows default printer** (Settings → Bluetooth & devices → Printers →
   *Let Windows manage my default printer* off → select it → *Set as default*).
2. Right-click the desktop → New → Shortcut, and paste in (change the address to yours):

   ```
   "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --app=https://erp.example.com/pos
   ```

3. Name it **RestoPro POS** and open the counter from this shortcut. *Save bill* now prints straight away.
   Close every other Chrome window first: the flag only applies when this shortcut starts Chrome.

**Other options:**
- **Reprint**: Orders → open the bill → *Print receipt*.
- **Kitchen tickets**: Settings → Printing → *Also print a KOT*. From a PC both go to the default printer. A separate
  kitchen printer works from the Android app.
- **Phone or tablet only**: the Android app prints to Bluetooth thermal printers directly.
- **Bills to customers**: every bill has a private link (no login) with a PDF download. Use the *WhatsApp* button on
  the bill. If the customer's mobile number is on the bill, it opens their chat with the link ready to send.

---

## 6. Updating to a new version

```bash
git pull                 # get the new version
php artisan test         # optional
./deploy/deploy.sh       # shared hosting: keeps .env, uploads, bills and backups; runs new migrations
```

On a VPS, run this on the server instead:

```bash
git pull && composer install --no-dev -o && php artisan migrate --force && php artisan optimize && npm run build
```

> The app takes a backup on its own schedule, but press **Back up now** before a big update anyway.

---

## 7. Troubleshooting

| Problem | Fix |
|---|---|
| **500 error** right after deploy | `php artisan optimize:clear`, then read `storage/logs/laravel-*.log`. Usually a typo in `.env` or a wrong DB password. |
| Login says **"too many attempts"** | Wait a few minutes. Sign-in is locked briefly after 5 wrong tries. |
| **CSS looks broken** | `public/build` is missing. Run `npm run build` before deploying (`deploy.sh` does this). |
| `/.env` is downloadable | The domain points at `restopro-app/` instead of `restopro-app/public/`. Fix the web root or the symlink. |
| Photo upload fails | Raise `upload_max_filesize` / `post_max_size` in the PHP settings (8M or more for photos; 64M for restoring big backups). |
| Backups don't run at night | Check the cron line (3.5) and run `php artisan schedule:list`. |
| Dropbox or Google "redirect_uri mismatch" | The redirect URL in their console must match the one on *Backup & Sync → Cloud* exactly, including `https://`. |
| Cloud secrets stopped working | `APP_KEY` changed. Enter the cloud keys again. Never change `APP_KEY` on a live site. |
| Forgot the owner password | `php artisan tinker --execute="App\Models\User::where('username','admin')->first()->update(['password'=>'new-password'])"` |
