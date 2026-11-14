# Orders

**Status:** 🚧 Phase 3 delivered the sales list (search, dates, cashier filters, totals), invoice view, reprint and returns via the POS. ⏳ The rest is Phase 4.
**Purpose:** Back-office view of all sales and the purchase cycle.

## Features
**Sales**
- Invoice list: filters by date, branch, cashier, customer, payment status, method
- Invoice detail, reprint (thermal/A4/PDF), share link
- Cancel (permission, reason, audited; reverses stock), returns / credit notes
- Record payments on credit sales; due tracking

**Quotations**
- Create, print/share, convert to sale

**Purchases**
- Purchase order → Goods receipt (GRN, updates stock & cost) → supplier bill
- Partial receipts; landed costs (optional)
- Supplier payments; payable tracking
- Purchase returns / debit notes
- "Print labels for this purchase" shortcut

## Business rules
- Document numbers per branch and financial year.
- Payment status: paid / partial / due, derived from payments.

## Permissions
`sales.view`, `sales.cancel`, `quotations.manage`, `purchases.view`, `purchases.manage`, `payments.manage`.
