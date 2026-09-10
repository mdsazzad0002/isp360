# Business Management Software

A Laravel + Inertia (Vue 3) business management system covering sales, purchases, accounts, HR, inventory, and reporting — with built-in license verification, automatic updates, and database backup.

## Tech Stack

- **Backend:** Laravel 11 (PHP 8.2+)
- **Frontend:** Vue 3 + Inertia.js
- **Styling:** Tailwind CSS 4
- **Build tool:** Vite
- **Database:** MySQL

## Features

- **Sales & Purchases** — sale/purchase entry, returns, quotations, and record listings
- **Accounts** — expenses, income, receive/payment, bank transactions, account heads, investments
- **HR** — employee management, salary generation
- **Control Panel** — products, customers, suppliers, users & access, company profile
- **Dashboard & Reports** — daily reports, profit/loss, current stock, ledgers
- **Licensing** — license key activation, periodic verification, and a gated verification-required screen when the license is invalid
- **Auto Update** — scheduled update checks against the provider, with automatic download & apply
- **Backup** — automatic/manual database backups uploaded to the provider, with backup history tracking

## Requirements

- PHP >= 8.2
- Composer
- Node.js & npm
- MySQL
- `mysqldump` on PATH (required for the backup feature)

## Getting Started

```bash
# install PHP dependencies
composer install

# install JS dependencies
npm install

# copy environment file and generate app key
cp .env.example .env
php artisan key:generate

# configure DB_* and LICENSE_* values in .env, then run migrations
php artisan migrate

# build frontend assets
npm run build
```

For local development, run the dev server instead of a full build:

```bash
npm run dev
php artisan serve
```

## Licensing & Updates

License, update, and backup behavior is configured in [`config/license.php`](config/license.php) via these environment variables:

| Variable | Purpose |
| --- | --- |
| `LICENSE_PROVIDER_URL` | Base URL of the license/update/backup server |
| `LICENSE_SOFTWARE_SLUG` | Product slug identifying this software to the provider |
| `LICENSE_KEY` | Default license key (can also be set from the Subscription page) |
| `LICENSE_VERIFY_CACHE_MINUTES` | How long a successful verification is trusted |
| `LICENSE_UPDATE_CHECK_INTERVAL` | Scheduler interval for update checks |

Scheduled tasks (see [`app/Console/Kernel.php`](app/Console/Kernel.php)) require the Laravel scheduler to be running:

```bash
* * * * * php /path-to-project/artisan schedule:run >> /dev/null 2>&1
```

This drives:
- `license:check` — every 30 minutes
- `update:check` — hourly
- `backup:run` — daily at 02:00

## License

Proprietary — © BD Soft Technology. All rights reserved.
