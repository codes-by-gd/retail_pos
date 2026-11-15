# 02 · Architecture

## Tech stack

| Layer | Choice |
|---|---|
| Backend | **Laravel 12** on PHP 8.4 |
| Frontend | **Vue 3** (`<script setup>`) rendered through **Inertia.js 2** — no separate API needed for screens |
| Styling | Tailwind CSS 3 + `@tailwindcss/forms`, icons from `@heroicons/vue` |
| State | Pinia (used by the POS cart), `@vueuse/core` helpers |
| Database | MySQL 8.4 in production/dev; SQLite in-memory for tests |
| Auth | Laravel Breeze (session login). Self-registration is disabled — admins create users. |
| Permissions | `spatie/laravel-permission` |
| Audit log | `spatie/laravel-activitylog` (v5) |
| Money maths | `brick/math` (no floats for money) |
| Barcodes / PDF | `jsbarcode` (labels, previews); `picqer/php-barcode-generator`, `barryvdh/laravel-dompdf` reserved for server-side invoices |
| Charts | `chart.js` + `vue-chartjs` |
| Tests | Pest |

## Folder layout

```
app/
├─ Http/Controllers/            # App-wide controllers (Dashboard, BranchSwitch, Profile, Auth)
├─ Http/Middleware/HandleInertiaRequests.php   # Props shared with every page
├─ Models/User.php
├─ Modules/<Module>/            # One folder per business module: Settings, Users, Inventory, Contacts, Pos, Sales,
│                               #   Purchases, Reports, Expenses, Notifications
│   ├─ Models/
│   ├─ Services/ or Actions/    # Business logic lives here
│   └─ Http/Controllers, Http/Requests
├─ Rules/                       # Validation rules (e.g. Gstin)
└─ Support/                     # Cross-cutting helpers
    ├─ CurrentBranch.php        # Which branch the user is working in
    ├─ Permissions.php          # Permission catalogue + default roles
    ├─ Gst/IndianStates.php     # GST state codes
    └─ helpers.php              # settings(), current_branch()
resources/js/
├─ Layouts/AppLayout.vue        # Sidebar + topbar (branch switcher, user menu)
├─ navigation.js                # Sidebar menu definition (permission-aware)
├─ Components/                  # Shared UI: DataTable, Pagination, FormField, Modal, Toggle…
├─ composables/usePermissions.js
├─ utils/format.js              # money() in ₹ with Indian digit grouping, dates
└─ Pages/<Module>/…             # One Vue page per screen
docs/                           # You are here
tests/Unit, tests/Feature/<Area>/
```

## Conventions

- **Thin controllers.** Validation in Form Requests; business logic in Service/Action classes (e.g. `CreateSaleAction`, `StockService`). Controllers only orchestrate and return Inertia responses.
- **Authorization by permission, not role.** Routes use `can:<permission>` middleware; Vue uses `usePermissions().can('x')` to hide UI. The Admin role passes every check via `Gate::before`.
- **Branch scoping.** Anything that belongs to a branch (stock, registers, sales, purchases…) is filtered by `current_branch()->id()`. Users only see branches assigned to them (Admins see all active branches).
- **Settings** are read with `settings('group.key')`; defaults live in `SettingsService::DEFAULTS`.
- **Strict models in development.** `Model::shouldBeStrict()` throws on lazy loading and missing attributes, so N+1 queries are caught early. Eager-load relations and don't append accessors globally.
- **Money:** columns are `DECIMAL(15,2)`, quantities `DECIMAL(15,3)`; calculations use `brick/math`.
- **Immutable documents:** a completed sale/purchase is never edited — corrections are made via returns, credit/debit notes or cancellation.
- **Stock:** every change goes through one `StockService`, inside a DB transaction with row locks, and writes a `stock_movements` ledger row.
- **Business-rule errors:** services throw `DomainException` / `InsufficientStockException`; `bootstrap/app.php` turns them into a "back with error" toast (or JSON 422). No try/catch in controllers.
- **Document numbers:** `DocumentNumberService::next('ADJ', $branch)` inside the saving transaction → `ADJ/BR1/26-27/0001`.
- **UI conventions** (dark mode via CSS-variable palettes, `useListFilters`, `useUnsavedGuard`, `DateRangeFilter`, phone cards in `DataTable`, command palette): see [modules/ui.md](modules/ui.md).
- **Alerts:** business code calls `app(Alerts::class)->…` (wrapped in `rescue()`); `Notifier` picks recipients by permission + branch + preferences and de-duplicates by key. Alert failures never break a sale.
- **Scheduled jobs** live in `routes/console.php` (`pos:daily-alerts`, `pos:backup`).
- **Reports:** `app/Modules/Reports` — `ReportRegistry` lists the reports; each returns typed sections that drive the screen and Excel/CSV/PDF exports; `SalesFacts` holds the shared sale/return line queries (also used by the dashboard). See [modules/reports.md](modules/reports.md).
- **POS money logic:** `GstCalculator` ⟷ `Pos/gst.js` must stay identical. Checkout/returns live in `app/Modules/Sales/Services`; registers in `app/Modules/Pos`.
- **Modals:** `Components/Modal.vue` is a teleported overlay, *not* a native `<dialog>` (see D-028). Don't switch back.
- **Item pickers:** use `Components/VariantPicker.vue` (scanner friendly) backed by `variants.search`.
- **List + modal CRUD screens:** use `composables/useCrudModal.js` (see the catalog pages).
- **Multipart forms with arrays** (file upload + nested rows) must pass `queryStringArrayFormat: 'indices'` to Inertia, otherwise rows split apart.
- **Audit:** models with sensitive data use the `LogsActivity` trait; important actions call `activity()->log(...)`.
- **Formatting:** `./vendor/bin/pint` for PHP. Vue files use 4-space indentation.

## Request flow (Inertia)

```
Browser ──► Laravel route (auth + can:permission)
              └─► Controller ─► FormRequest validation ─► Service/Action ─► Models/DB
              └─► Inertia::render('Module/Page', props)
                     + shared props: app, auth.user (+permissions), branch, flash
Browser ◄── Vue page renders inside AppLayout
```
