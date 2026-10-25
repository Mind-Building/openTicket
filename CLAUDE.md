# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
php vendor/bin/phpunit

# Run specific test file
php vendor/bin/phpunit tests/Unit/Services/LoggerServiceTest.php --testdox

# Bring an installation's database up to date (pending migrations; --check = only show)
php database/update_database.php

# Cleanup expired reservations (also via cron every 5 min)
php app/scripts/cleanup_expired_reservations.php

# Initialize DB (after creating empty database)
mysql -u root -p ticket < database/schema.sql
php database/seed_users.php
php database/seed_config.php

# Deploy to customer installation
deploy_to_customer.bat
```

## Architecture

This is a PHP 8.1+ theater ticketing system running on Apache/XAMPP with MySQL. There is no framework — it uses a custom MVC-like structure with PSR-4 autoloading via Composer.

### Entry Point & Routing

All requests hit the root `.htaccess`, which rewrites to `public/`. There is no router — each page is a standalone PHP file. `app/bootstrap.php` is included at the top of every page and handles:

- PSR-4 autoloading for the `App\` namespace (maps to `app/` directory, with lowercase subdirs)
- Loading `app/helpers/functions.php` (global logging helpers and HMAC token generators)
- Loading `.env` file into `$_ENV`
- Loading Composer autoloader and PDF libraries

### Namespace Structure

| Namespace        | Directory       |
| ---------------- | --------------- |
| `App\Core\*`     | `app/core/`     |
| `App\Models\*`   | `app/models/`   |
| `App\Services\*` | `app/services/` |
| `App\Helpers\*`  | `app/helpers/`  |

### Key Core Classes

- **`App\Core\Database`** — PDO singleton. Use `Database::getInstance()->query($sql, $params)`. Always uses prepared statements.
- **`App\Core\Config`** — Reads from `configurations` DB table (lazy-loaded, cached). Use `Config::get('key', $default)`.
- **`App\Core\Auth`** — Session-based auth with roles (`admin`, `presales`). Presales also supports cookie-based device tokens for shared terminals. Guard pages with `Auth::requireAdmin()` or `Auth::requirePresales()`.
- **`App\Helpers\UrlHelper`** — All URLs must go through `UrlHelper::url('path')` or `UrlHelper::absoluteUrl('path')` so multi-customer deployments work correctly. Never hardcode paths.

### Three User Roles

1. **Admin** (`public/admin/`) — Full system management
2. **Presales** (`public/presales/`) — Point-of-sale for ticket staff
3. **Customer** (`public/`) — Public-facing booking flow

### Public Booking Flow

`public/index.php` (event list) → `public/event.php` (date selection) → `public/seatplan.php` (interactive seat selection) → `public/cart.php` → `public/checkout.php` → PayPal or direct → `public/checkout_success.php`

Cart state is stored in PHP session. Seats are soft-reserved during checkout, released by cron or `api/cart_clear_expired.php`. The timeout is `Reservation::CART_LIFETIME_MINUTES` (20) — one constant driving both the seat block and the cleanup, so a seat is blocked for exactly as long as its cart row exists. The clock restarts on customer activity (checkout entry, PayPal order creation, and the `cart-heartbeat.js` keep-alive); never add a code path that refreshes it without checking whether another session took the seat over (`Reservation::touchCartItems()`).

### API Endpoints

`public/api/*.php` — Stateless JSON endpoints called via AJAX. API requests suppress error display and never send HTML. Each file is self-contained and returns JSON.

### Security Pattern (HMAC Tokens)

Public download links for tickets, seatplan views, and vouchers are protected by HMAC tokens bound to the exact set of IDs:

```php
hash('sha256', 'ticket:' . implode(',', sorted_ids) . ':' . APP_SECRET)
```

Helpers: `generateTicketDownloadToken()`, `generateSeatplanViewToken()`, `generateVoucherDownloadToken()` — all in `app/helpers/functions.php`. `APP_SECRET` must be set in `.env`.

### Configuration

`.env` file in project root (not in Git). Key variables:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `APP_SECRET` — required for all HMAC tokens
- `APP_ENV` — `development` or `production`
- `APP_BASE_PATH` — override URL base path (e.g., for shared hosting)

System settings (company name, PayPal credentials, email templates, etc.) are stored in the `configurations` DB table and managed via `public/admin/settings.php`.

### Logging

Uses Monolog via `App\Services\LoggerService`. Three separate log files in `logging/`: `customer.log`, `admin.log`, `presales.log`. Use the global helper functions (e.g., `logAdminLogin()`, `logPresalesSale()`) rather than calling `LoggerService` directly.

### Multi-Customer Deployment

`ticket2.0/` is the master development installation. Customer installations (e.g., `ticket_kunde_a/`, `ticket_kunde_b/`) are separate copies deployed via `deploy_to_customer.bat`. Each customer has their own `.env` and database. Code changes are developed in master, then deployed to customers.

### Database Migrations

Schema changes after the initial `schema.sql` live in `database/migrate_*.sql` (idempotent: `ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`). Every migration file must also get an entry in `App\Services\DatabaseMigrator::migrations()` with a schema check — that is what `php database/update_database.php` and the admin page *Datenbank-Update* run on each customer installation. Also add the change to `schema.sql`. Code must keep working until the migration has run (check for the column, as `Reservation::hasFnCardNumberColumn()` does).

### .htaccess

`RewriteBase /ticket2.0/` is set for local XAMPP development. Change to `RewriteBase /` for production root-domain deployments.

## Code Standards

- PHP 8.1+ syntax (match expressions, named arguments, str_starts_with, etc.)
- All DB queries use prepared statements via `Database::getInstance()->query($sql, [':param' => $value])`
- UI text in German, code/DB identifiers in English
- Bootstrap 5 for all frontend components
- `htmlspecialchars()` all output to prevent XSS

## Documentation to Update

When adding features, update these files:

- `docs/CHANGELOG.md` — version history
- `docs/ADMIN_HANDBUCH.md` — Admin documentation
- `docs/VORVERKAUF_HANDBUCH.md` — Presales documentation
