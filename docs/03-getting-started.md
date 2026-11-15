# 03 · Getting started

## Requirements
PHP 8.4 (extensions: pdo_mysql, bcmath, gd, intl, mbstring, zip), Composer 2, Node 22, MySQL 8.4.

## First-time setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# configure the database in .env (see below), then:
php artisan migrate --seed
php artisan storage:link   # product images
npm run build
```

## Running locally

```bash
php artisan serve        # http://localhost:8000
npm run dev              # Vite with hot reload (keep running while editing Vue files)
```

## Demo logins (from the seeder)

All demo users have password **`password`** and POS PIN **`1234`**.

| Email | Role | Branches |
|---|---|---|
| admin@pos.test | Admin | all |
| manager@pos.test | Manager | Main Store, City Mall Outlet |
| cashier@pos.test | Cashier | Main Store |
| stock@pos.test | Stock Keeper | Main Store |

Demo registers: Main Store has Counter 1 and Counter 2, City Mall Outlet has Counter 1. Log in as `cashier@pos.test`, open **POS Billing**, open Counter 1 with a float and start scanning (barcodes are on the product pages and labels).

Demo contacts: customers (some with birthdays coming up, a Gold Members group, an out-of-state boutique on wholesale pricing), suppliers with opening payables, and the built-in Walk-in Customer.

The seeder also creates GST rates (incl. the price-based *Garments* rate), clothing units, size/colour lists and a demo apparel catalog (15 products / 73 size-colour variants across Men, Women, Kids, Accessories and Fabrics) with stock in both branches, plus **45 days of trading history** (register shifts, ~900 bills, returns, fortnightly restock purchases, customer receipts) so the dashboard and reports have data. The history is created through the real services with the clock moved back (`DemoSalesSeeder`); a fresh seed takes about 1–2 minutes.

## Database
The project uses **MySQL 8.4**, database `pos` (utf8mb4). Configure `.env` (not committed):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pos
DB_USERNAME=root
DB_PASSWORD=<password>
```

```sql
CREATE DATABASE pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE pos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- optional, for MySQL test runs
```

Then `php artisan migrate:fresh --seed`.

Tests normally run on in-memory SQLite (fast, see `phpunit.xml`). Before a release, also run them on MySQL:

```bash
DB_CONNECTION=mysql DB_DATABASE=pos_test DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD=<password> php artisan test
```

## Everyday commands

| Command | Purpose |
|---|---|
| `php artisan test` | Run the Pest test suite (uses in-memory SQLite) |
| `./vendor/bin/pint` | Format PHP code |
| `npm run build` | Production build of the frontend |
| `php artisan db:seed --class=RolesAndPermissionsSeeder` | Sync new permissions into the database (safe to re-run) |
| `php artisan migrate:fresh --seed` | Rebuild the database with demo data (**deletes all data**) |
