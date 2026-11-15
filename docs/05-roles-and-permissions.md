# 05 · Roles & permissions

## How it works
- Every action is guarded by a **permission** (e.g. `products.manage`). Roles are just named bundles of permissions.
- Each user has **one role** and one or more **branches**.
- **Admin** is special: it always passes every permission check (`Gate::before` in `AppServiceProvider`) and can access every active branch. It cannot be edited or deleted, and at least one active Admin must always exist.
- Only Admins can create, edit or promote other Admins.

The catalogue lives in [`app/Support/Permissions.php`](../app/Support/Permissions.php) — the single source of truth.

## Permission catalogue

| Module | Permission | Meaning |
|---|---|---|
| Dashboard | `dashboard.view` | View dashboard |
| POS | `pos.access` | Use the POS billing screen |
| | `pos.discount` | Give discounts |
| | `pos.price_override` | Change selling price on a bill |
| | `pos.returns` | Process returns / exchanges |
| | `pos.register.manage` | Open / close cash registers |
| Inventory | `products.view` · `products.manage` · `products.delete` | Products; `products.view` also opens Stock on hand and the stock ledger |
| | `catalog.manage` | Categories, brands, units & taxes |
| | `stock.adjust` · `stock.transfer` | Stock adjustments / transfers |
| | `labels.print` | Print barcode labels |
| Orders | `sales.view` · `sales.cancel` | Sales |
| | `quotations.manage` | Quotations |
| | `purchases.view` · `purchases.manage` | Purchases |
| | `payments.manage` | Record payments |
| Contacts | `customers.view` · `customers.manage` | Customers |
| | `suppliers.view` · `suppliers.manage` | Suppliers |
| Expenses | `expenses.manage` | Expenses |
| Reports | `reports.sales` · `reports.inventory` · `reports.financial` | Report groups: sales; inventory & purchases; financial = profit, dues, register shifts & GST. Any one opens the Reports menu (gate `reports.any`). Dashboard profit needs `reports.financial` |
| Users | `users.view` · `users.manage` · `roles.manage` | Users and roles |
| Settings | `settings.manage` | Business settings, branches, registers, alert limits, **database backups** |
| | `activity.view` | Audit log (who did what; logins, permission changes, cancellations, cash, payments, expenses, price edits) |

Alerts follow permissions too: see the table in [Notifications](modules/notifications.md#alerts). Everyone can open their own notifications and preferences.

Contacts: a "both" contact needs `customers.manage` **and** `suppliers.manage` to create or edit. Contact search: `customers.view`/`pos.access` (customers), `suppliers.view`/`purchases.manage` (suppliers); quick-add needs `customers.manage`.

The JSON item search (`variants.search`) is allowed for any of `products.view`, `stock.adjust`, `stock.transfer`, `labels.print`, `pos.access`.

Phase 4: cancelling bills needs `sales.cancel`; converting a quotation to a POS bill needs `quotations.manage` **and** `pos.access`; recording payments (also "payment now" on a purchase) needs `payments.manage`.

## Default roles

| Role | Permissions |
|---|---|
| Admin | Everything (implicit) |
| Manager | Everything except `roles.manage` and `settings.manage` |
| Cashier | `dashboard.view`, `pos.access`, `pos.discount`, `pos.returns`, `pos.register.manage`, `products.view`, `sales.view`, `customers.view`, `customers.manage` |
| Stock Keeper | `dashboard.view`, `products.*`, `catalog.manage`, `stock.*`, `labels.print`, `purchases.*`, `suppliers.*`, `reports.inventory` |

Default roles are filled only when first created, so changes made in the UI are never overwritten by re-seeding.

## Adding a permission
1. Add it to `Permissions::GROUPS` (and to `DEFAULT_ROLES` if a default role should get it).
2. Run `php artisan db:seed --class=RolesAndPermissionsSeeder`.
3. Protect the route with `->middleware('can:the.permission')` and hide UI with `can('the.permission')`.
4. Update the table above.
