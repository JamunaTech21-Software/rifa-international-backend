# Deploying the RIFA backend (cPanel)

```
Visitor ──► rifaint.com (Vercel, customer site) ──┐  /api/* + secret header
Admin   ──► admin.rifaint.com (Vercel, admin app) ┴──► api.rifaint.com (cPanel: PHP 8.3 + MySQL) ──► Cloudinary, SMTP
```

The PHP code lives **outside** the web root in `~/rifa_backend`; the API subdomain's document root only contains
`index.php`, `.htaccess` and `.user.ini` from `deploy/api/`.

---

## 1. One-time cPanel setup

1. **Subdomain** — *Domains → Create a New Domain*: `api.rifaint.com`, document root `/home/<user>/api.rifaint.com`
   (a folder directly in the home directory). Wait for **AutoSSL** to issue the certificate (*SSL/TLS Status*).
2. **PHP** — *MultiPHP Manager*: set `api.rifaint.com` to **PHP 8.3**. *Select PHP Version → Extensions*: enable
   `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`, `json`, `sodium` (argon2 password hashing; bcrypt is used if missing).
3. **Database** — *MySQL Databases*: create `<user>_rifa_prod` and user `<user>_rifa` with a strong password,
   add the user to the database with **ALL PRIVILEGES** (on this database only).
4. **Terminal/SSH** — check whether *Terminal* is available. If not, build `vendor/` locally (step 2.2).

## 2. Upload the code

1. Upload the backend repository (without `.env`, `vendor/`, `storage/logs/*`) to `/home/<user>/rifa_backend`.
2. Install dependencies — on the server (Terminal):
   ```bash
   cd ~/rifa_backend && composer install --no-dev --optimize-autoloader
   ```
   or locally (Composer is pinned to PHP 8.3, so this matches the server), then upload `vendor/`:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Copy the three files from `deploy/api/` into the subdomain document root:
   ```bash
   cp ~/rifa_backend/deploy/api/index.php ~/rifa_backend/deploy/api/.htaccess ~/rifa_backend/deploy/api/.user.ini ~/api.rifaint.com/
   ```
4. Make storage writable:
   ```bash
   chmod 755 ~/rifa_backend/storage/logs && chmod 700 ~/rifa_backend/storage/sessions
   ```

## 3. Configure

1. ```bash
   cp ~/rifa_backend/deploy/env.production.example ~/rifa_backend/.env && chmod 600 ~/rifa_backend/.env
   ```
2. Fill in every value. Generate the proxy secret with `openssl rand -hex 32` — the **same value** goes into both
   Vercel projects (see `docs/vercel-proxy.md`).
3. Check — must print no `ERROR` lines (until it does, the API answers `503 server_misconfigured`):
   ```bash
   php ~/rifa_backend/bin/check-config.php
   ```

## 4. Database

```bash
cd ~/rifa_backend
php database/migrate.php
php database/seed.php
php database/seeds/seed_admin.php --name="Md. Samin Israk" --email=admin@example.com   # prompts for the password
```

## 5. Cron and backups

- *Cron Jobs* → daily at 03:30:
  ```
  30 3 * * * /usr/local/bin/php /home/<user>/rifa_backend/bin/cleanup.php >/dev/null 2>&1
  ```
  (use the PHP 8.3 path shown in *MultiPHP Manager* if different, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`).
- *Backup* (or JetBackup): make sure **daily database backups** are on. Download one and keep it off the server
  before every migration.

## 6. Connect Vercel

Follow `docs/vercel-proxy.md` for both Vercel projects (env vars + `middleware.js`), then redeploy them with
`VITE_API_MOCK=false`.

## 7. Smoke test

| Check | Expected |
|---|---|
| `curl -s https://api.rifaint.com/api/v1/health` | `{"data":{"status":"ok","database":"ok",…}}` |
| `curl -sI http://api.rifaint.com/api/v1/health` | `301` to `https://…` |
| `curl -sI https://api.rifaint.com/.env` | `403` |
| `https://rifaint.com/api/v1/health` (through Vercel) | same JSON as above |
| Submit the Contact form on the site | success message; office email arrives; admin inbox shows it with **your** IP |
| Submit an application with 2 files | success page with a reference number; documents open from the admin panel |
| Admin login on `admin.rifaint.com`, open the dashboard | works; logout works |
| `php bin/mail-test.php --to=you@example.com` | email arrives |

## Updating

1. Back up the database (download it).
2. Upload the changed files to `~/rifa_backend` (never overwrite `.env`).
3. `composer install --no-dev --optimize-autoloader` if `composer.lock` changed.
4. `php database/migrate.php` (only runs new migrations).
5. `php bin/check-config.php`, then the smoke test.

Keep a copy of the previous `~/rifa_backend` (e.g. `rifa_backend_prev`) until the new version is verified;
to roll back, swap the folders back and restore the database backup if a migration ran.

## Troubleshooting

| Symptom | Where to look |
|---|---|
| `503 server_misconfigured` | `php bin/check-config.php` |
| `500 server_error` | `storage/logs/app-YYYY-MM-DD.log` (search the `request_id` from the response) and `storage/logs/php-errors.log` |
| `413 payload_too_large` on Apply | PHP limits: `.user.ini` / `.htaccess` in the document root (`upload_max_filesize 6M`, `post_max_size 13M`) |
| Uploads fail only through Vercel | Vercel request size limit — see the staging checks in `docs/vercel-proxy.md` |
| Emails not arriving | `php bin/mail-test.php --to=…`, then `storage/logs` ("Email failed") |
| Everyone gets `429` quickly | `TRUSTED_PROXY_SECRET` differs between Vercel and `.env` (all visitors look like one IP) |
| Admin logged out on every request | `SESSION_SECURE=true` needs HTTPS end to end; check `storage/sessions` is writable |
