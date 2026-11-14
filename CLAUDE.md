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

## Before finishing a change
- `php artisan test`, `./vendor/bin/pint`, `npm run build` must pass.
- **Keep `docs/` current:** update the module page in `docs/modules/`, `07-roadmap.md`, `09-changelog.md`, and `04-database.md` / `05-roles-and-permissions.md` / `08-decisions.md` when relevant.
