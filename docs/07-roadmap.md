# 07 · Roadmap

Each phase ends with working, tested features (`php artisan test`, `npm run build` and Pint all pass) before the next begins.

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation: project setup, layout, auth, roles & permissions, users, branches, settings | ✅ Done (2026-11-14) |
| 1 | Catalog & inventory: categories, brands, units, GST rates, products & variants, barcodes, stock ledger, adjustments, transfers, label printing, CSV import/export | ✅ Done (2026-11-15) |
| 1.1 | Clothing-store adaptation: size/colour variants, price-based garment GST, article no. & fabric, apparel masters & demo data | ✅ Done (2026-11-15) |
| 2 | Contacts: customers, suppliers, groups, addresses, ledgers | ✅ Done (2026-11-16) |
| 3 | ✅ Done (2026-11-17) — **POS billing panel**: registers & sessions, fast search/scan, cart, GST calculator, split payments, hold/recall, receipts, returns, keyboard shortcuts, PIN switch | ⏳ |
| 4 | ✅ Done (2026-11-18) — Orders: sales list/cancel/reprint, quotations, purchase orders → GRN → bill, supplier payments, purchase returns, dues | ⏳ |
| 5 | ✅ Done (2026-11-15) — Dashboard KPIs & charts; sales, profit, stock, register, outstanding and GST reports; Excel/PDF export | ⏳ |
| 6 | ✅ Done (2026-11-15) — Notifications, expenses, audit log viewer, backups, performance pass | ⏳ |
| 7 | ✅ Done (2026-11-16) — Logo, loyalty points, dark mode, command palette, mobile & POS UX, dashboard and list polish | ⏳ |

## Phase 0 checklist
- [x] Laravel 12 + Breeze (Vue/Inertia) + Pest, Tailwind, Pinia, heroicons
- [x] Packages: spatie permission & activitylog, brick/math, barcode generator, dompdf
- [x] App layout: sidebar (permission-aware, upcoming modules marked "Soon"), topbar with branch switcher and user menu, toast messages
- [x] Shared components: DataTable, Pagination, SearchInput, FormField, SelectInput, Toggle, Badge, Card, PageHeader, ConfirmModal
- [x] Auth: login only (no self-registration), inactive users blocked, last-login tracking
- [x] Roles & permissions: catalogue, default roles, role editor with grouped permissions
- [x] Users: list with filters, create/edit, role + branch assignment, POS PIN
- [x] Branches: CRUD with GST state & GSTIN validation, current-branch switching
- [x] Business settings: profile, GSTIN, financial year, invoice, tax and POS preferences
- [x] Tests: 60 passing
- [x] Switch local database from SQLite to MySQL (done 2026-11-16; suite also passes on MySQL)

## Phase 1 checklist
- [x] Catalog masters: categories (nested), brands, units, GST rates — with in-use protection
- [x] Products & variants: SKU/EAN-13 auto-generation, multiple barcodes, MRP rule, image, opening stock
- [x] `StockService` (locking, weighted average cost, ledger) and `DocumentNumberService` (per branch & FY)
- [x] Stock on hand, stock ledger, adjustments (incl. stock-count mode), two-step branch transfers
- [x] Item search API + `VariantPicker` (scanner friendly) — reused by the POS in Phase 3
- [x] Barcode label printing (A4 sheets & thermal rolls)
- [x] CSV import/export
- [x] Dashboard low-stock KPI is live
- [x] Tests: 99 passing; every page also checked in headless Chrome for JS errors
- Moved to later: batch/expiry tracking, multiple product images, .xlsx import

## Phase 1.1 checklist — clothing-store adaptation (2026-11-15)
- [x] Size/colour attributes (Colour, Size, Shirt Size, Waist, Kids Age) with ordered values; "Sizes & Colours" catalog screen
- [x] Product form builds the Colour × Size matrix; new values can be typed in place; default prices applied to all rows; combinations can be excluded
- [x] Article / style no. and fabric on products; searchable everywhere
- [x] Price-based GST (Garments: 5% ≤ ₹2,500 per piece, 18% above) — `TaxRate::rateFor()` ready for billing
- [x] Clothing units (pcs, set, pair, mtr, dzn, pack, box) and demo catalog (Men/Women/Kids/Accessories/Fabrics, apparel brands)
- [x] Stock matrix on the product page; garment tags (brand, size/colour, article no.) and hang-tag preset
- [x] CSV import groups rows by article no. into one product, with Colour/Size/… columns
- [x] Tests: 106 passing; product form verified in headless Chrome

## Phase 2 checklist
- [x] Customers & suppliers in one `contacts` table (customer / supplier / both) with CUS-/SUP- codes
- [x] Phone normalisation + uniqueness (counter lookup key); GSTIN validation fills the state
- [x] Customer groups (retail/wholesale price, member discount); credit limit; supplier payment terms
- [x] Birthday / anniversary fields and "next 30 days" filter for offers
- [x] Append-only contact ledger with opening balances and account statement (running balance, brought forward, print)
- [x] Built-in walk-in customer
- [x] CSV import/export; JSON search + quick-add for the POS
- [x] Tests: 121 passing; screens checked in headless Chrome
- Later: loyalty points, birthday WhatsApp/SMS, multiple shipping addresses

## Phase 3 checklist
- [x] Registers, shifts (open/join/close), cash in/out, Z-report with cash reconciliation
- [x] GST calculator (server) + exact JS mirror (fuzz-tested on 2,000 carts); price-based garment GST per piece after discounts
- [x] Full-screen billing: scan/search with size-colour chips, cart editing, customer lookup/quick-add, group pricing & discounts, bill discount
- [x] Split payments: cash with change, card, UPI, on account (credit limit / store credit), exchange credit
- [x] Hold/recall, PIN cashier switch, keyboard shortcuts, scanner-safe focus handling
- [x] Returns & exchanges with credit notes (cash, card, UPI, store credit, exchange); damaged items not restocked
- [x] GST invoice numbers ≤ 16 chars; thermal 80/58 mm receipts, A4 tax invoice with HSN summary & amount in words, credit notes
- [x] Sales list and invoice view with reprint
- [x] Tests: 159 passing on SQLite **and** MySQL; full cashier flow verified in headless Chrome

## Phase 4 checklist
- [x] Sales list filters (payment status, cancelled), dues per bill, cancel bill in the open shift (stock, ledger, exchange vouchers reversed)
- [x] Customer receipts & supplier payments (cash through a drawer or not), oldest-first allocation, printable receipts/vouchers, payments list
- [x] Quotations with live GST, statuses, A4 print, convert to a POS bill (even when the register isn't open yet)
- [x] Purchase orders (draft/placed/partly received/received/cancelled) with print
- [x] Goods receipt + supplier bill: GST incl. IGST, freight → landed cost, weighted-average stock, duplicate bill check, partial PO receipt, payment now, print tags per piece
- [x] Purchase returns with debit notes
- [x] Fix: exchange credit is a voucher in the ledger (named customers using another customer's credit note)
- [x] Tests: 180 passing on SQLite and MySQL; PO → receipt → debit note → payment, quotation → POS, customer payment → drawer, cancel flows verified in Chrome; layout sweep clean at 3 screen sizes

## Phase 5 checklist
- [x] Report framework: typed sections with totals → screen, Excel (.xlsx, sheet per section), CSV, PDF; date presets incl. financial year; branch / "all branches" / GSTIN scope limited to the user's branches
- [x] Sales: summary by day/month, by item (article or SKU), category, brand, size/colour, cashier, customer, branch, hour; collections by payment mode
- [x] Inventory: stock valuation, reorder list, slow-moving/dead stock, stock movement summary, purchase register, purchases by supplier
- [x] Accounts: profit & margin (8 groupings), receivables & payables with ageing, register shift cash differences
- [x] GST: GSTR-1 (B2B, B2CL, B2CS, CDNR, CDNUR, HSN with UQC, documents issued) and GSTR-3B summary with ITC
- [x] Dashboard: period & branch switch, KPIs vs previous period, net sales chart, payment modes, top articles/categories, drawers, dues, recent bills, low stock
- [x] Demo trading history seeder (45 days, ~900 bills through the real services)
- [x] Tests: 187 passing on SQLite and MySQL; every report page checked in Chrome at 3 screen sizes for Admin, Stock Keeper and Cashier

## Phase 6 checklist
- [x] Expenses: categories, branch entries with GST included, bill photo, cash from a register drawer (Z-report), lock after shift close; Expenses and Profit & loss reports; dashboard net profit
- [x] Notifications: bell with unread count, notifications page, per-user preferences (in-app / email), alerts for low stock, cancellations, large discounts, cash differences, daily summary, supplier & customer dues, backup failures; de-duplication; scheduled `pos:daily-alerts`
- [x] Audit log viewer with filters and old → new changes; logging added for logins, role permission changes, payments, register close and cash in/out, expenses, backups
- [x] Backups: gzip mysqldump (no table locks) on demand and nightly (`pos:backup`), retention, download/delete, restore instructions
- [x] Performance: indexes for every date-range query; reports < 30 ms and dashboard ≈ 130 ms on the demo data; production checklist in Getting started
- [x] Tests: 202 passing on SQLite and MySQL; new pages checked in Chrome at 3 screen sizes for Admin, Manager, Cashier and Stock Keeper

## Phase 7 checklist — logo, loyalty, UI/UX (2026-11-16)
- [x] Shop logo on receipts, invoices, prints, PDF reports, app header and login
- [x] Loyalty points: earn per ₹, redeem as payment with min/max rules, oldest-first use, expiry job, returns/cancellations, manual adjust (`loyalty.adjust`), receipt lines, customer page, report
- [x] Dark mode (light/dark/system) for every screen; collapsible sidebar; Ctrl+K command palette with global search
- [x] Phone card layout for lists, remembered list filters, shared date presets, unsaved-changes warning, sticky report headers
- [x] Dashboard quick actions, deferred secondary panels, auto-refresh, remembered period
- [x] POS quick picks, tablet/phone layout with bottom pay bar, shortcuts panel, clearer sale-complete screen
- [x] Tests: 210 passing on SQLite and MySQL; 15 pages × light/dark × 3 widths checked in Chrome with no errors or sideways scrolling
- Skipped by request: WhatsApp invoices
- Follow-up (2026-11-16): real-time notifications (Reverb), POS sounds with on/off, loyalty on/off verified

## Production readiness (2026-11-16)
- [x] Audit (security, authorization, concurrency, migrations, caches, dependencies) — see [11 · Production readiness](11-production-readiness.md)
- [x] Installer `pos:install`, security headers, error pages, deployment guide [10 · Deployment](10-deployment.md)

## Email (2026-11-16)
- [x] SMTP settings screen with test email; forgot password; welcome/set-password emails; admin "send reset email"; branded templates

## Later / optional
Loyalty points · gift vouchers · offline PWA · GST e-invoice (IRN) & e-way bill · WhatsApp/SMS receipts · direct ESC/POS printing · mobile app via API · batch & expiry UI.
