# Notifications

**Status:** ⏳ planned (Phase 6) — bell icon placeholder exists in the top bar
**Purpose:** Tell the right person when something needs attention.

## Triggers
| Event | Who | When |
|---|---|---|
| Low / out of stock | Stock keeper, Manager | After a sale/adjustment crosses the reorder level; daily digest |
| Near-expiry batches | Stock keeper | Daily |
| Large discount / bill cancelled | Manager | Immediately |
| Register closed with cash difference | Manager | Immediately |
| Customer payment overdue | Manager | Daily |
| Daily sales summary | Admin | End of day |

## Features
- In-app bell with unread count, mark as read
- Optional email
- Per-user preferences
- Scheduled checks via `php artisan schedule:run` (cron)
