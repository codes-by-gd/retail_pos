# Expenses

**Status:** ✅ done (Phase 6, 2026-11-15)
**Purpose:** Record shop running costs so profit and cash reports are correct.

## Screens
| Screen | Route | Page |
|---|---|---|
| Expenses list + add/edit | `expenses.index` | `Pages/Expenses/Index.vue` |
| Categories | `expense-categories.index` | `Pages/Expenses/Categories.vue` |
| Bill photo | `expenses.attachment` | (private file, opened in a new tab) |
| Reports | `reports.show` `expenses`, `profit-loss` | see [Reports](reports.md) |

## Features
- ✅ Categories (seeded: rent, salaries & wages, electricity, staff tea, transport & courier, packaging, alterations, maintenance, marketing, internet & phone, bank & card charges, other); deactivate or delete when unused
- ✅ Expense entry per branch: date, category, amount, **GST included** (kept out of profit), paid to, mode (cash, UPI, card, bank, cheque), reference, note, **bill photo** (JPG/PNG/PDF ≤ 4 MB, stored privately)
- ✅ Numbering `EXP/BR1/26-27/0001`
- ✅ **Cash from a register drawer**: reduces that shift's expected cash and shows as "− Expenses paid" in the Z-report
- ✅ List with date / category / mode / search filters, totals by category
- ✅ Expenses report (by category, day, month or each entry) and **Profit & loss** (net sales − cost of goods − expenses − stock written off = net profit)
- ✅ Every create / change / delete is in the audit log

## Business rules
- Cash from a drawer: only an open shift of the same branch, and not more than the drawer holds.
- An expense paid from a drawer is **locked once that shift is closed** (Z-reports never change); others can be edited or deleted.
- Profit uses amount − GST included; GST input credit on expenses is not claimed in GSTR-3B by the system.

## Permissions
`expenses.manage` (Manager by default). Reports: `reports.financial`.

## Code
`app/Modules/Expenses/` — `Models/{Expense, ExpenseCategory}`, `Services/ExpenseService`, `Http/Controllers/{ExpenseController, ExpenseCategoryController}`, `Http/Requests/ExpenseRequest`; seeder `DefaultExpenseCategoriesSeeder`; reports `ExpensesReport`, `ProfitLossReport`.
