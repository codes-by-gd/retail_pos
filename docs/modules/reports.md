# Reports

**Status:** ✅ done (Phase 5, 2026-11-15; expenses and profit & loss added in Phase 6)
**Purpose:** Answer "how is the business doing?", run the shop day to day (reorder list, dead stock, dues, cash differences) and produce GST returns data.

## Screens
| Screen | Route | Page |
|---|---|---|
| Reports list (grouped, only what the user may open) | `reports.index` | `Pages/Reports/Index.vue` |
| Any report | `reports.show` `/reports/{key}?from=&to=&branch=&gstin=&…` | `Pages/Reports/Show.vue` |
| Export | `reports.export` `/reports/{key}/export?format=xlsx\|csv\|pdf&…` | `ReportExporter` |

Every report has: date presets (today, yesterday, this week/month/quarter, last month, this/last financial year, custom), a branch picker (a branch, or **All branches** when the user has more than one), its own options (group by, attribute, days…), a totals row, sortable columns, a chart where it helps, and **Excel (.xlsx, one sheet per section), CSV and PDF** exports. The screen shows up to 1,000 rows per table (totals always cover everything); exports contain all rows.

## Reports
| Group | Report (`key`) | What it shows | Permission |
|---|---|---|---|
| Sales | Sales summary (`sales-summary`) | Per day or month: bills, pieces, gross, discounts, taxable, GST, bill total, returns, **net sales**, average bill; chart | `reports.sales` |
| | Sales by item (`sales-by-item`) | Per article or per size/colour SKU: sold, returned, net qty, taxable, GST, net sales, share % | `reports.sales` |
| | Sales by category / brand / size & colour / cashier / customer / branch | Same columns per dimension; size/colour lets you pick the attribute (Size, Colour, Waist…) | `reports.sales` |
| | Hourly sales (`sales-by-hour`) | Busy hours; chart | `reports.sales` |
| | Loyalty points (`loyalty`) | Points earned, used, taken back, expired, adjusted per customer; balance and value now | `reports.sales` |
| | Collections by payment mode (`payment-modes`) | At billing (cash net of change), received later, refunds, paid to suppliers, net | `reports.sales` |
| Inventory & purchases | Stock valuation (`stock-valuation`) | Now: qty, avg cost, value at cost / selling price / MRP — by item, article, category or brand | `reports.inventory` |
| | Low stock / reorder list (`low-stock`) | At or below reorder level (same rule as the Stock page), suggested order qty & cost | `reports.inventory` |
| | Slow-moving & dead stock (`slow-moving`) | In stock, not sold for 30–365 days (and on hand at least that long), value, last sold/received | `reports.inventory` |
| | Stock movement summary (`stock-movement`) | Opening → purchased, returned to supplier, sold, customer returns, transfers, adjustments → closing | `reports.inventory` |
| | Purchase register (`purchase-register`) | Supplier bills with GST split, freight, paid/due; debit notes | `reports.inventory` |
| | Purchases by supplier (`purchases-by-supplier`) | Bills, value, debit notes, net, still due | `reports.inventory` |
| Accounts & profit | Profit & margin (`profit`) | Net sales excl. GST, cost, gross profit, margin % — by article, item, category, brand, day, month, cashier, branch | `reports.financial` |
| | Customer receivables (`receivables`) | Ledger balance, unpaid bills aged 0–30 / 31–60 / 61–90 / 90+, opening/on-account part, last payment | `reports.financial` |
| | Supplier payables (`payables`) | Same for suppliers + overdue by payment terms | `reports.financial` |
| | Profit & loss (`profit-loss`) | Net sales − cost of goods = gross profit; − expenses (excl. GST) ± stock adjustments at cost = net profit, with % of sales | `reports.financial` |
| | Expenses (`expenses`) | By category, day, month or each entry; GST included and excl. GST | `reports.financial` |
| | Register shifts (`register-shifts`) | Opening float, expected vs counted cash, difference, closing note | `reports.financial` |
| GST | GSTR-1 (`gstr-1`) | 4A B2B, 5 B2C (large), 7 B2C (small, net of credit notes), 9B CDNR / CDNUR, 12 HSN summary (B2B & B2C tabs, UQC), 13 documents issued | `reports.financial` |
| | GSTR-3B summary (`gstr-3b`) | 3.1 outward supplies net of credit notes, 3.2 inter-state to unregistered by state, 4 ITC (less debit notes), indicative net tax | `reports.financial` |

## Business rules
- **Period rule:** a bill counts on its bill date, a return on its credit-note date (same as GST). "Net" = sold − returned in the period. Cancelled bills are excluded everywhere except the GSTR-1 document count.
- **Profit** = sales excluding GST − cost; cost = quantity × the weighted-average cost saved on the bill line at sale time (not today's cost). A restocked return gives its cost back; a damaged return (not restocked) stays a loss.
- **GST figures** come only from the saved bill / credit-note / purchase lines. GST reports pick a **GST registration** (GSTIN): all of the user's branches using that GSTIN (branch GSTIN, else the business GSTIN).
- **GSTR-1 tables:** buyer GSTIN → B2B; no GSTIN + inter-state + bill above ₹1,00,000 → B2C large; everything else → B2C small (per place of supply and rate). Credit notes follow the original bill: B2B → CDNR, B2C large → CDNUR, B2C small → netted in table 7. HSN quantities use the unit's UQC (pcs → PCS, mtr → MTR, pair → PRS, set → SET…).
- **ITC (3B):** only from bills of suppliers with a GSTIN, in the month goods were received; debit notes reduce it. The "tax payable" row is indicative (no set-off order).
- **Receivables/payables** use the contact ledger (business-wide, so no branch filter); bills only explain the aged part.
- Users only ever see branches they are assigned to (Admins: all active branches) — enforced server-side in `FilterResolver`.

## Code
`app/Modules/Reports/`
- `ReportRegistry` — the list of reports (add a report here to make it appear)
- `Reports/Report` (base: key, title, group, permission, scope, options, chart, `build()` → sections) and the reports; `DimensionSalesReport` is configured per dimension (also the profit report)
- `Support/` — `Section` (columns, rows, totals), `ReportFilters`, `Sql` (date/hour expressions for MySQL & SQLite)
- `Services/` — `SalesFacts` (sale/return line queries + `byDimension()`), `FilterResolver`, `ReportExporter` (openspout xlsx, CSV with BOM, dompdf), `DashboardService`
- `Http/Controllers/ReportController`; PDF view `resources/views/reports/pdf.blade.php`
- Frontend: `Components/{ReportTable, BarChart, BarList}.vue`, `Pages/Reports/*`
- Tests: `tests/Feature/Reports/ReportsTest.php`

## Adding a report
1. Create a class extending `Report` (or configure another `DimensionSalesReport`), return `Section`s from `build()`.
2. Register it in `ReportRegistry::all()`.
3. Columns are typed (`money`, `qty`, `int`, `percent`, `date`, `datetime`, `text`); mark summable ones `total: true`. Screen, Excel, CSV and PDF then work automatically.
4. Compare DATE columns with `$f->dateRange()` and timestamps with `[$f->start(), $f->end()]`.
