# Notifications

**Status:** ✅ done (Phase 6, 2026-11-15) · near-expiry alerts ⏳ when batch/expiry tracking is built
**Purpose:** Tell the right person when something needs attention.

## Alerts
| Type | When | Who (any of these permissions; branch alerts only to users of that branch, Admins always) |
|---|---|---|
| Low / out of stock | A sale, transfer or adjustment takes an item **to or below its reorder level** (once; again only after the alert is read and stock falls again) | `products.manage`, `purchases.manage` |
| Bill cancelled | Immediately | `sales.cancel` (not the person who cancelled) |
| Large discount | Bill discount ≥ *Settings → Alerts* % (default 20 %) | `reports.financial` (not the cashier) |
| Cash short / excess | Register closed with a difference ≥ setting (default ₹100) | `reports.financial` |
| Daily sales summary | 21:45: bills, net sales, cash differences, registers still open — per branch | `reports.sales` |
| Supplier bills due | 21:45: overdue or due within 3 days (payment terms) | `payments.manage` |
| Customer dues | 21:45: bills unpaid > 30 days | `payments.manage` |
| Backup problems | Nightly backup failed | `settings.manage` |

## Features
- ✅ Bell in the top bar: unread badge (refreshed every minute while the tab is visible), latest 8, mark all read, click → opens the bill / stock / report
- ✅ Notifications page: all / unread, mark read/unread, clear read
- ✅ Per-user preferences: switch each alert type off, or also receive it by email
- ✅ Email channel when *Settings → Alerts → Also send by email* is on and `MAIL_*` is configured
- ✅ De-duplication: one unread alert per subject (e.g. one per item and branch)
- ✅ Alerts can never break the action that caused them (errors are logged)

## Scheduler
`routes/console.php`: `pos:daily-alerts` (21:45) and `pos:backup` (23:30). Needs cron on the server — see [Getting started](../03-getting-started.md#production).

## Code
`app/Modules/Notifications/` — `NotificationTypes` (catalogue), `Notifications/AlertNotification`, `Services/Notifier` (recipients, de-dup, send), `Services/Alerts` (the business events + daily checks), `Http/Controllers/NotificationController`; `Components/NotificationBell.vue`, `Pages/Notifications/Index.vue`. Hooks: `StockService::move`, `CheckoutService`, `SaleCancelService`, `RegisterService::close`.
