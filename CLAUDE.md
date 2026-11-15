# Retail POS — guidance for Claude

Laravel 12 + Vue 3 (Inertia) + Tailwind POS for a **clothing store** (one business, multiple branches), Indian GST. Think apparel: size × colour variants from attributes, article/style numbers, price-based garment GST (`TaxRate::rateFor()`), garment tags. Full docs in `docs/` — read `docs/02-architecture.md` before larger changes.

## Conventions
- Business modules live in `app/Modules/<Module>/{Models,Services,Actions,Http/Controllers,Http/Requests}`; pages in `resources/js/Pages/<Module>/`.
- Thin controllers; validation in Form Requests; logic in Services/Actions.
- Authorize with permissions (`can:perm` middleware, `usePermissions().can()` in Vue), never role names. Catalogue: `app/Support/Permissions.php` → re-run `RolesAndPermissionsSeeder` after changes.
- Branch-owned data is scoped with `current_branch()->id()`. Settings via `settings('group.key')`.
- `Model::shouldBeStrict()` is on: eager-load relations; don't add global `$appends`.
- Money `DECIMAL(15,2)` + brick/math; stock changes only via `StockService`; completed sales are immutable.
- Reuse components in `resources/js/Components` (DataTable, Pagination, FormField, SelectInput, Toggle, Card, PageHeader, ConfirmModal, VariantPicker, BarcodeSvg…) and `composables/useCrudModal.js`. Add new menu items in `resources/js/navigation.js`.
- Services throw `DomainException` for business-rule errors (rendered as a toast globally). Document numbers via `DocumentNumberService`.
- Multipart Inertia forms with nested arrays need `queryStringArrayFormat: 'indices'`.
- GST math: `app/Support/Gst/GstCalculator.php` and `resources/js/Pos/gst.js` must stay identical. `Modal.vue` must not use native `<dialog>` (breaks scanners).
- Laravel `validate()`/`validated()` silently drops array keys that have no rule once any nested rule exists — give every key you need a rule.
- Walk-in customer: never has a state/GSTIN; sales to it are always intra-state.
- Exchange credit notes are vouchers: no ledger entry when issued; they credit whoever spends them.
- Responsive grids need an explicit `grid-cols-1` (otherwise wide content stretches the page on phones).
- Alerts: call `app(Alerts::class)->…` wrapped in `rescue()`; new alert types go in `NotificationTypes` (permissions decide recipients). Scheduled jobs live in `routes/console.php`.
- Reports: add to `ReportRegistry`, return typed `Section`s (screen + Excel/CSV/PDF for free). Compare DATE columns with `$f->dateRange()` — SQLite stores date casts as `Y-m-d 00:00:00`, so `BETWEEN 'd' AND 'd'` misses them. Bills count on bill date, returns on credit-note date.
- Run tests on MySQL too before committing a phase (see docs/03-getting-started.md). Commit locally per phase; never push.

## Before finishing a change
- `php artisan test`, `./vendor/bin/pint`, `npm run build` must pass.
- **Keep `docs/` current:** update the module page in `docs/modules/`, `07-roadmap.md`, `09-changelog.md`, and `04-database.md` / `05-roles-and-permissions.md` / `08-decisions.md` when relevant.
