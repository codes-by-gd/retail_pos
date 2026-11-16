# 11 · Production readiness — audit (2026-11-16)

**Verdict: ready for production** for a single clothing business with one or more branches, when deployed as described in [10 · Deployment](10-deployment.md) (HTTPS, cron, Reverb under Supervisor, off-site backups). The known limitations below are product scope, not stability problems.

## What was checked

| Area | Result |
|---|---|
| Automated tests | **220 passing on SQLite and MySQL** (2,136 assertions): GST maths, checkout, returns, cancellations, payments, purchasing, reports (reconciled to the paisa), loyalty, notifications, permissions, backups, installer |
| Concurrency | 17 simultaneous checkouts for an item with 5 in stock (8 PHP workers, MySQL): exactly 5 sold, 12 refused "not enough stock", 5 unique consecutive invoice numbers, stock 0 — row locks hold |
| Authorization | Every route has a permission middleware or an explicit check; record pages (bills, credit notes, purchases, POs, quotations, transfers, adjustments, shifts, payments, expenses) are limited to the user's branches; private WebSocket channels only for their owner |
| Injection / XSS | Raw SQL fragments only from whitelisted values; no user HTML rendered (`v-html` only for Laravel's pagination labels); SVG uploads blocked |
| Dependencies | `composer audit`: 0 advisories · `npm audit`: 0 vulnerabilities |
| Configuration | No `env()` outside config (safe with `config:cache`); `php artisan optimize` caches config/routes/events/views cleanly |
| Database | All migrations run **and roll back** on MySQL (full up → reset → up cycle) |
| Performance | All 24 reports over a financial year < 30 ms, dashboard ≈ 130 ms on demo data; date-range indexes in place |
| Browser | 15 main pages × light/dark × 1366/1024/390 px with no JS errors or horizontal scrolling; POS, real-time alerts and sounds verified in Chrome |
| Failure handling | Alerts/pushes never break a sale (deferred, short timeouts, pause when Reverb is down); backup failures raise an alert; business-rule errors show as messages, production errors as a friendly page |

## Fixed during this audit
- **No safe way to set up a live shop** — `db:seed` created demo users with the password `password`. Added `php artisan pos:install` (first branch + Admin, no demo data), `ProductionSeeder`, and the demo seeder now refuses to run in production.
- **Rollback failed on MySQL** — the Phase 6 index migration couldn't be reversed (MySQL used the new composite indexes for foreign keys) and the activity-log migration had no `down()`. Both fixed; full reset verified.
- **SVG product images were accepted** (a stored-XSS vector on the same origin) — images are now JPG/PNG/WebP only.
- **Dashboard ignored `dashboard.view`** — a custom role without it could still see sales figures; such users now go to the POS (or their notifications).
- **Security headers** added (`nosniff`, `SAMEORIGIN` framing, referrer and permissions policy, HSTS over HTTPS); trusted proxies configurable (`TRUSTED_PROXIES`).
- **Password policy** in production: letters + numbers, and not in known data breaches.
- **Production error pages** (403/404/500/503) inside the app instead of raw HTML; "page expired" becomes a message.

## Known limitations (decide if they matter for you)
| Limitation | Impact | Mitigation |
|---|---|---|
| **Online only** — no offline billing | If a counter can't reach the server, it can't bill | Host the server **in the shop on the LAN** (internet outages don't matter), UPS for server and router; or a 4G backup link |
| **No GST e-invoice (IRN/QR) or e-way bill** | Required only above ₹5 crore aggregate turnover / for goods movement > ₹50,000 | Generate IRN on the GST portal / via your accountant until integrated |
| **GSTR files are data, not JSON uploads** | GSTR-1/3B are shown and exported (Excel/CSV/PDF) for entry or your CA's tool | Hand the Excel to your CA / offline tool |
| **No accounting export (Tally)** | Day books must be entered separately | Use the reports' Excel exports |
| **Browser printing** | First print may show the print dialog | Chrome `--kiosk-printing` + default thermal printer |
| **Backups stay on the server unless copied** | Disk loss = data loss | Daily `rsync`/`rclone` off-site (see deployment §6) |
| Loyalty points are a payment, not a discount | GST charged on full bill value (conservative) | By design (D-042) |
| Points used are not restored on returns | Refund goes by the chosen method | By design (documented) |
| No automated browser (e2e) tests in the repo | UI regressions are caught by manual / scripted checks | Keep running the Pest suite on MySQL before each release |
| Email needs an SMTP account | No forgot-password / welcome emails / email alerts until set | Set it under *Administration → Email* (test button); meanwhile admins set passwords under *Users* |

## Before go-live
Follow the go-live checklist in [10 · Deployment §12](10-deployment.md#12-go-live-checklist) — especially: confirm GST rates with your accountant, enter opening stock and balances, test printers and scanners, and test one backup restore.
