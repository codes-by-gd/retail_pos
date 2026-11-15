# Dashboard

**Status:** ✅ done (Phase 5, 2026-11-15)
**Purpose:** The day (or week, month) at a glance for the current branch — or all branches for owners and managers.

## Features
- ✅ Period: Today · Yesterday · Last 7 days · Last 30 days · This month; **This branch / All branches** (when the user has more than one branch)
- ✅ KPI cards with change vs the previous period of the same length: **net sales** (bills incl. GST − returns), bills, average bill, pieces sold (net of returns), **gross profit & margin** and net profit after expenses (only with `reports.financial`)
- ✅ Net sales chart — by hour for one day, by day otherwise (tooltip: date, amount, bills)
- ✅ Payment modes (cash after change, UPI, card, on account, exchange) with share %
- ✅ Top 5 articles and categories by net sales
- ✅ Cash in drawers: expected cash in every open register (`pos.register.manage`)
- ✅ Money outstanding: customers owe us / we owe suppliers, top debtors (`customers.view` / `suppliers.view`), link to the ageing report
- ✅ Recent bills (`sales.view`) and low-stock items (`products.view`) with links to the stock page and reorder report

## Business rules
- Sales figures are the same as the Sales summary report: bills on their bill date, returns subtracted on the credit-note date, cancelled bills excluded.
- Profit uses the cost saved on each bill line (see [Reports](reports.md#business-rules)).
- Branch scope is always limited to the user's assigned branches.

## Permissions
`dashboard.view`. Each card follows the permission of the data it shows (above).

## Code
`app/Http/Controllers/DashboardController` → `app/Modules/Reports/Services/DashboardService`; page `resources/js/Pages/Dashboard.vue` (uses `BarChart`, `BarList`).
