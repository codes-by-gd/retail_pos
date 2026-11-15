# Notifications

**Status:** ✅ done (Phase 6, 2026-11-15; real time 2026-11-16) · near-expiry alerts ⏳ when batch/expiry tracking is built
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

## Real time (2026-11-16)
- Alerts are pushed over **WebSockets (Laravel Reverb, self-hosted)** to the user's private channel `App.Models.User.{id}` (event `.alert`); `routes/channels.php` lets users join only their own channel.
- In the browser: the bell badge and list update instantly, a **pop-up card** appears (auto-hides after 7 s, click opens the item), an optional **chime**, and optional **desktop notifications** while the tab is in the background. A green dot on the bell = live.
- **On this device** card (Notifications page): live status, sound on/off, desktop notifications on/off, **Send me a test alert**.
- Pushes are sent **after the response** (`defer`) with 1–3 s timeouts, so a sale never waits; if the WebSocket server is down, pushes pause for 60 s, the alert is still stored, and the bell falls back to polling every minute (every 5 minutes as a safety net when live).
- Running it: `php artisan reverb:start` (included in `composer dev`); production setup in [Getting started](../03-getting-started.md#production).

## Features
- ✅ Bell in the top bar: unread badge (live; polling fallback), latest 8, mark all read, click → opens the bill / stock / report
- ✅ Notifications page: all / unread, mark read/unread, clear read
- ✅ Per-user preferences: switch each alert type off, or also receive it by email
- ✅ Email channel when *Settings → Alerts → Also send by email* is on and `MAIL_*` is configured
- ✅ De-duplication: one unread alert per subject (e.g. one per item and branch)
- ✅ Alerts can never break the action that caused them (errors are logged)

## Scheduler
`routes/console.php`: `pos:daily-alerts` (21:45) and `pos:backup` (23:30). Needs cron on the server — see [Getting started](../03-getting-started.md#production).

## Code
`app/Modules/Notifications/` — `NotificationTypes` (catalogue), `Notifications/AlertNotification`, `Services/Notifier` (recipients, de-dup, send), `Services/Alerts` (the business events + daily checks), `Events/AlertPushed` (broadcast), `Http/Controllers/NotificationController`; `resources/js/echo.js` (Echo client), `utils/sounds.js`, `utils/desktopAlerts.js`; `Components/NotificationBell.vue`, `Pages/Notifications/Index.vue`. Hooks: `StockService::move`, `CheckoutService`, `SaleCancelService`, `RegisterService::close`.
