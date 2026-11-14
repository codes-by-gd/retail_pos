# 09 · Changelog

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
