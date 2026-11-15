# 09 · Changelog

## Phase 6 — Expenses, notifications, audit log, backups (2026-11-15)
- Expenses module: categories, entries with GST included, bill photos, cash paid from a register drawer (shown in the Z-report, locked after the shift closes), filters and category totals.
- New reports: Expenses and Profit & loss (net sales − cost − expenses − stock written off). Dashboard profit card shows net profit after expenses.
- Notifications: top-bar bell, notifications page, per-user preferences with optional email; alerts for low stock, bill cancellations, large discounts, cash short/excess, daily sales summary, supplier bills due, old customer dues and backup failures; scheduled `pos:daily-alerts`.
- Audit log viewer (filters, old → new values); now also logs logins, role permission changes, payments, register close and cash in/out, expenses and backups.
- Database backups: on demand and nightly (`pos:backup`), retention setting, download/delete; gzip mysqldump with `--single-transaction`.
- Performance: indexes on all date-range lookups used by reports and lists; timings documented; production checklist (cron, backups, mail, optimize).
- Fixes: bell dropdown stays on screen on phones; expense bill photos download with a valid file name.
- Demo data: daily/monthly expenses; past alerts marked as read.
- 202 tests (SQLite and MySQL).

## Phase 5 — Dashboard & reports (2026-11-15)
- Reports module with 22 reports in four groups (sales, inventory & purchases, accounts & profit, GST), each filterable by date preset/range, branch or GSTIN and options, with totals, sortable columns, charts and Excel/CSV/PDF export.
- GSTR-1 (B2B, B2C large/small, credit notes, HSN summary with UQC, documents issued) and GSTR-3B summary (outward tax net of credit notes, inter-state B2C, ITC from registered suppliers less debit notes).
- Profit & margin from the cost saved on each bill line; damaged returns stay a loss.
- Dashboard rebuilt: period and branch switch, KPIs with change vs previous period, hourly/daily net sales chart, payment modes, top articles & categories, cash in drawers, money outstanding, recent bills, low stock.
- Demo data now includes 45 days of trading history (shifts, bills, returns, restock purchases, receipts) created through the real services.
- Low-stock rule unified: the reorder report and dashboard use the Stock page rule (out-of-stock items without a reorder level are included).
- New dependency: `openspout/openspout` (xlsx export).
- 187 tests (SQLite and MySQL).

## Phase 4 — Orders, purchasing & payments (2026-11-18)
- Sales: payment/cancel filters, due amounts, cancel bill (same open shift, nothing returned or paid later) with full reversal; payments received later shown on the invoice.
- Payments: receive from customers / pay suppliers (cash, UPI, card, bank, cheque), optional register drawer, oldest-bill-first allocation, RCPT/PAY numbering, A4 receipts, payments list; unpaid bills on contact pages.
- Quotations: create/edit with live GST, statuses, validity, A4 print, convert to a POS bill.
- Purchasing: purchase orders, goods receipt with supplier bill (GST/IGST, freight → landed cost, weighted-average stock, optional cost-price update, part payment), partial PO receiving, debit notes, print tags per piece received.
- Ledger fix: exchange credit notes are vouchers — no account entry at return; they credit whoever spends them (fixes named customers using a walk-in's credit note).
- UX: POS opens a converted quotation even if the register had to be opened first; phone layouts fixed for responsive grids (explicit single column) and wide tables.
- 180 tests (SQLite and MySQL).

## Bug-fix pass before Phase 4 (2026-11-17)
Reported by the user:
- **Held bill lost its customer on recall** — `validated()` drops payload keys without rules when nested rules exist; customer, bill discount, note and total were discarded. All payload keys now validated; pending exchange credits are parked with the bill; customer balance/credit/group refreshed on recall.
- **Payment dialog: UPI/card row overflowed** and hid the remove button — rows are now two-line cards; dialog focuses itself so Enter completes the sale immediately.

Found in the audit (automated layout sweep at 1366/1024/390 px, scripted flows, before/after data snapshots of every edit form):
- Walk-in customer could be saved with a state (contact form pre-filled the branch state), which would bill walk-in sales as IGST at other-state branches — walk-in is now always intra-state; form only pre-fills state for new contacts.
- Branches with sales/stock/register history could be deleted — now blocked (deactivate instead).
- Dashboard showed ₹0 "today's sales" although sales exist — today's sales, bills and average bill are now live (links respect permissions; local date, not UTC).
- Stock ledger now links sale and return movements to their invoice.
- "On account" said "No credit available" when the bill was already fully paid.
- Phone layouts: modals sized to the screen and clip instead of scrolling sideways; card headers wrap; contact statement fits.
- Flaky user-search test made deterministic; held-cart test tolerant of MySQL JSON key order.
- 164 tests passing on SQLite and MySQL; layout sweep, cashier flow, held-bill flow and form round-trips all clean.

## Phase 3 — POS billing (2026-11-17)
- Registers & shifts: opening float, join an open shift, cash in/out, close with cash count, printable Z-report.
- `GstCalculator` (server) and `Pos/gst.js` (browser) — identical results to the paisa; price-based garment GST decided per piece after discounts; CGST/SGST vs IGST from customer state; rupee round-off.
- Billing screen: barcode scan, grouped size/colour search, cart editing with permission-checked price override and discounts, customer lookup & quick add, automatic group pricing/discount, bill discount, split payments (cash/card/UPI/on account/exchange credit), hold & recall, PIN cashier switch, keyboard shortcuts.
- Returns & exchanges with credit notes; exchange credit applied to the next bill; store credit on account; cash/card/UPI refunds.
- GST-compliant invoice numbers (≤16 chars: `BR1/2627/00001`), credit notes `CNBR1/2627/00001`.
- Printing: 80/58 mm receipts, A4 tax invoice with HSN summary and amount in words, credit notes.
- Sales list and invoice view; dashboard roadmap updated.
- Cashier role now includes `pos.returns`. Settings: invoice prefix optional (≤4), branch code ≤6, accept card/UPI, print after sale.
- Fixes found while testing: cumulative rounding so piece-by-piece returns never over-refund; `lines` is a MySQL reserved word; the shared Modal no longer uses `<dialog>` (Chrome kept keyboard input from the scanner field after a dialog closed).
- 159 automated tests (SQLite and MySQL).

## Phase 2 — Customers & suppliers (2026-11-16)
- Contacts module: customers, suppliers and "both", with codes (CUS-00001 / SUP-00001) and a built-in Walk-in Customer.
- Indian phone normalisation; unique phone required for customers; GSTIN validation fills and checks the state.
- Customer groups with retail/wholesale pricing and member discount; credit limit; supplier payment terms.
- Birthday and anniversary, with an "upcoming in 30 days" filter for clothing-store offers.
- Contact ledger (append-only, Dr/Cr) with opening balances and a printable account statement.
- CSV import/export for customers and suppliers; JSON search and quick-add endpoints for the POS.
- Demo customers (incl. Gold Members, an out-of-state B2B boutique) and apparel suppliers with payables.
- 121 automated tests.

## Phase 1.1 — Clothing-store adaptation (2026-11-15)
- The system is now explicitly for a clothing store; Phase 0/1 data and screens adapted.
- Variants are built from **attributes** (Colour, Size, Shirt Size, Waist, Kids Age) — new "Sizes & Colours" screen; renaming a value renames the variants using it; values in use are protected.
- Product form: choose what the product varies by, tick values (or type new ones), get every combination with default prices; exclude combinations; warning before removing existing variants.
- Products have **article / style no.** and **fabric**; searchable in products, stock and the item search.
- **Price-based GST**: tax rates can switch to a higher % above a per-piece price limit; seeded *Garments* (5% ≤ ₹2,500, 18% above) plus GST 0/5/18%. 12% and 28% slabs no longer seeded.
- Units: pcs, set, pair, mtr (decimal), dzn, pack, box. Business default name "My Clothing Store".
- Demo catalog: 15 apparel products / 73 variants across Men, Women, Kids, Accessories, Fabrics (incl. fabric by the metre and an alteration service).
- Product page: colour × size **stock matrix** per branch.
- Labels: brand, size/colour, article no. fields; 50 × 75 mm hang-tag preset.
- CSV import/export: attribute columns; rows grouped by article no. into one product; `gst` column takes a rate name or %.
- 106 automated tests.

## Phase 1 — Catalog & inventory (2026-11-15)
- Catalog setup: nested categories, brands, units (decimal flag), GST rates; default slabs and units seeded.
- Products with variants, auto SKU, multiple barcodes with auto in-store EAN-13, image upload, MRP check, opening stock.
- `StockService` with row locking, weighted-average costing and an append-only stock ledger.
- Gap-free document numbers per branch and financial year (`DocumentNumberService`, `FinancialYear`).
- Stock on hand (value at cost / retail), stock ledger, adjustments with stock-count mode, branch-to-branch transfers (send → receive / cancel).
- Barcode label printing for A4 label sheets and thermal rolls.
- Product CSV import (with per-row errors) and export.
- Scanner-friendly item search (`VariantPicker`) and JSON search endpoint.
- Business-rule errors (e.g. insufficient stock) now show as a toast instead of an error page.
- Dashboard: live low/out-of-stock count. Demo catalog of 13 products with stock in both branches.
- Fixes: current branch is re-resolved when the session branch changes; CSV handling for PHP 8.4.
- 99 automated tests.

## Phase 0 — Foundation (2026-11-14)
- Project created: Laravel 12.72, Breeze (Vue + Inertia), Pest, Tailwind, Pinia, heroicons.
- Installed spatie/laravel-permission, spatie/laravel-activitylog, brick/math, picqer barcode generator, dompdf, chart.js, jsbarcode.
- Timezone `Asia/Kolkata`; app name "Retail POS".
- Users: phone, hashed POS PIN, active flag, last login; inactive users cannot log in; registration and self-delete removed.
- Roles & permissions catalogue with Admin / Manager / Cashier / Stock Keeper defaults; role editor UI.
- User management with role and branch assignment.
- Branches with GST state and validated GSTIN; branch switcher in the top bar.
- Business settings: profile, GSTIN, financial year, invoice text, tax and POS preferences.
- New app layout and shared UI components.
- 60 automated tests.
