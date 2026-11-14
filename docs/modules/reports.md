# Reports

**Status:** ⏳ planned (Phase 5)
**Purpose:** Answer "how is the business doing?" and produce GST returns data.

## Reports
| Group | Reports | Permission |
|---|---|---|
| Sales | Daily/period summary; by product, category, brand, cashier, branch, payment method; hourly | `reports.sales` |
| Inventory | Current stock & value, low stock, dead/slow-moving, stock ledger, expiry | `reports.inventory` |
| Purchases | By supplier, purchase register | `reports.inventory` |
| Financial | Profit & margin, expenses, receivables/payables, register (Z) reports | `reports.financial` |
| GST | GSTR-1 style (B2B, B2C, credit notes, HSN summary), GSTR-3B summary | `reports.financial` |

All reports: date range & branch filters, totals row, export to Excel and PDF.

## Business rules
- Profit = sale taxable value − cost snapshot on the sale line (not today's cost).
- GST figures come only from snapshotted document lines.
