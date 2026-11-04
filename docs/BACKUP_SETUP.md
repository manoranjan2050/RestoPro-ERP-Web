# Backup & Sync — setup guide

Backups work on their own (saved on the server). Connecting a cloud is optional but strongly recommended:
if the server is lost, the cloud copy is what you restore from. You can connect one, two or all three.

All settings live in **Backup & Sync → Cloud**. Secrets are stored encrypted with the server's `APP_KEY`
(if `APP_KEY` ever changes, enter them again).

> Do the cloud setup **after** the app is live on your domain, because Dropbox and Google need the
> exact live web address as the "redirect URI". The page shows the exact URL to copy.

---

## 1. Automatic daily backup (needed once, on the server)

hPanel → **Advanced → Cron Jobs** → add, **every minute**:

```
cd /home/<you>/restopro-app && php artisan schedule:run >> /dev/null 2>&1
```

(The Schedule tab shows the exact line for the server it is running on.) Then choose the time and how many
copies to keep in **Backup & Sync → Schedule**. Default: daily 02:00, keep 14 on the server, 30 in each cloud.

---

## 2. Dropbox (easiest)

1. Go to <https://www.dropbox.com/developers/apps> → **Create app**.
2. Choose **Scoped access** → **App folder** → name it e.g. `RestoPro Backups`.
3. **Permissions** tab → tick `files.content.write`, `files.content.read`, `files.metadata.read` → **Submit**.
4. **Settings** tab → *OAuth 2 → Redirect URIs* → add the URL shown on the Cloud tab
   (`https://<your-domain>/backup/callback/dropbox`).
5. Copy the **App key** into the app → **Save** → **Connect Dropbox** → Allow.
6. Press **Test connection**. Backups appear in *Dropbox → Apps → RestoPro Backups*.

No app secret is needed (the app uses PKCE).

## 3. Google Drive

1. <https://console.cloud.google.com> → create a project (e.g. `restopro-backup`).
2. **APIs & Services → Library** → enable **Google Drive API**.
3. **OAuth consent screen** → External → fill the app name and your email →
   add your Gmail under **Test users** (or publish the app so the connection doesn't expire after 7 days).
4. **Credentials → Create credentials → OAuth client ID → Web application** →
   *Authorized redirect URIs*: the URL shown on the Cloud tab (`https://<your-domain>/backup/callback/gdrive`).
5. Copy **Client ID** and **Client secret** into the app → **Save** → **Connect Google Drive** → Allow.
6. A folder **RestoPro Backups** is created in your Drive. The app can only see files it created (`drive.file` scope).

> While the consent screen is in "Testing", Google expires the connection after 7 days —
> publish the app (no verification is needed for personal use of `drive.file`) or reconnect weekly.

## 4. GitHub (private repository)

1. Create a **private** repository, e.g. `your-name/restopro-backups`, with a README (so `main` exists).
2. GitHub → Settings → Developer settings → **Personal access tokens → Fine-grained tokens** →
   *Repository access*: only that repo → *Permissions*: **Contents: Read and write** → Generate.
3. Paste the repository (`owner/name`) and token into the app → **Save** → **Test connection**.

The app **refuses public repositories** (backups contain password hashes and bill photos).
GitHub allows files up to 100 MB; if backups grow beyond that, use Dropbox or Google Drive.

---

## Restoring

* **From this server:** Backup & Sync → *Restore* on any backup.
* **From a cloud copy:** download the `.zip` from Dropbox / Drive / GitHub → *Restore from a backup file*.

You see what's inside first, then type `RESTORE`. A **pre-restore safety copy** of the current data is saved
automatically, so a wrong restore can be undone by restoring that copy. Everyone signs in again afterwards
(user accounts are restored too).

Backups made on the PC (SQLite) restore fine on the server (MySQL) and back — the format is database-neutral.
