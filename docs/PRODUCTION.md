# Production setup

A checklist for putting one installation (one company, one country) on a server. Work through it
top to bottom before the first real customer logs in.

## 1. `.env`

| Key | Production value | Why |
|---|---|---|
| `APP_ENV` | `production` | Turns off development behaviour. |
| `APP_DEBUG` | `false` | With `true`, an error page shows code, paths and `.env` values to anyone. |
| `APP_KEY` | set once with `php artisan key:generate` | Encrypts router, gateway, SMS and 2FA secrets. **Back it up**: without it those secrets can't be read. Never change it on a running installation. |
| `APP_URL` | `https://your-domain` | Links in e-mails, payment callbacks and webhooks. |
| `SESSION_SECURE_COOKIE` | `true` | The session cookie is only sent over HTTPS. |
| `SESSION_DRIVER` | `file` or `redis` | `redis` when more than one web server. |
| `LOG_LEVEL` | `warning` | `debug` fills the disk. |
| `QUEUE_CONNECTION` | `redis` (own server) or `database` (shared hosting) | See section 3. |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD` | set before the first `db:seed` | The seed's first Superadmin. In production a missing password gets a random one, printed once. |
| `MAIL_*` | your SMTP server | Invoices, reminders, password e-mails. |
| `RADIUS_DB_*` | only when FreeRADIUS uses its own database | See `RADIUS_SETUP.md`. |

After changing `.env`: `php artisan config:cache && php artisan route:cache && php artisan view:cache`.

## 2. Install and update

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force          # first install only
php artisan storage:link
```

The web server's document root is `public/`. The PHP user needs write access to `storage/`,
`bootstrap/cache/` and `public/uploads/` only.

## 3. Scheduler and queues

One cron entry runs everything that is time-based (invoices, suspensions, reminders, session logs,
backups):

```cron
* * * * * cd /path/to/isp306 && php artisan schedule:run >> /dev/null 2>&1
```

Router pushes and SMS go through queues. Pick one:

- **Own server:** `QUEUE_CONNECTION=redis` and keep `php artisan horizon` running under Supervisor or
  systemd. Dashboard at `/horizon` (needs the `queueMonitor` permission).
- **Shared hosting:** `QUEUE_CONNECTION=database`, `ISP_QUEUE_IN_SCHEDULER=true`. The cron entry works
  the queue every minute.

After every update: `php artisan horizon:terminate` (Horizon restarts with the new code) or
`php artisan queue:restart`.

## 4. HTTPS and the web server

- HTTPS only; redirect port 80 to 443. Payment gateways refuse plain-HTTP callbacks.
- Uploaded files are data, never code. Apache reads `public/uploads/.htaccess`. On nginx add:

```nginx
location ^~ /uploads/ {
    location ~ \.(php|phtml|phar)$ { deny all; }
}
```

- Ticket attachments and KYC documents are on the private disk (`storage/app`), never under `public/`.

## 5. Network

- MikroTik routers: RouterOS v7 REST API over HTTPS where possible; an API user with only the rights
  it needs.
- RADIUS: see `RADIUS_SETUP.md`. Allow CoA/Disconnect (UDP 3799) from this server to each NAS.

## 6. Security settings in the app

- ISP → Settings → Login security: require two-factor login for at least Superadmin and admin.
- Change the seeded admin password at the first login (My profile).
- Give staff roles only the permissions they need; admin and Superadmin pass every check.

## 7. Backups

- `php artisan isp:backup` writes one archive to `storage/app/backups`: the database (and a separate
  FreeRADIUS database), `public/uploads/` and `storage/app/`. The scheduler runs it daily at
  `ISP_BACKUP_AT` (02:30) and keeps the newest `ISP_BACKUP_KEEP` (14). It needs `mysqldump` /
  `mariadb-dump` on the server.
- ISP → Backups (permission `backup`) takes one now and downloads them; every download is audited.
- **Copy the archives off the server** (another machine, cloud storage). A backup on the same disk
  dies with the disk.
- Keep `.env` (above all `APP_KEY`) somewhere other than the server: without it, the encrypted
  secrets in a restored database can't be read.
- Restore: `php artisan isp:restore <file>` (asks first; `--database-only`, `--files-only`), then
  `php artisan migrate --force` if the backup is from an older version. Rehearse it on another
  machine before you rely on it.

## 8. Before go-live

- [ ] `APP_DEBUG=false`, HTTPS working, `SESSION_SECURE_COOKIE=true`
- [ ] Cron entry running (`php artisan schedule:list` shows the jobs)
- [ ] Queue worker or `ISP_QUEUE_IN_SCHEDULER` set, and a test SMS goes out
- [ ] Seeded admin password changed, 2FA policy on
- [ ] A backup taken and restored once on another machine
- [ ] `php artisan isp:ledger-check` reports every ledger balanced
