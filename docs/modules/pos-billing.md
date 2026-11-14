# POS billing

**Status:** ✅ done (Phase 3, 2026-11-17)
**Purpose:** Bill customers as fast as possible at a clothing-store counter, with keyboard and barcode-scanner support, correct GST, and a clean cash drawer at the end of the day.

## Screens
| Screen | Route | Page |
|---|---|---|
| Open / join register | `pos.terminal` (no open shift) | `Pages/Pos/OpenRegister.vue` |
| Billing screen | `pos.terminal` | `Pages/Pos/Terminal.vue` + components in `resources/js/Pos/` |
| Shift history | `register-sessions.index` | `Pages/Pos/Sessions/Index.vue` |
| Z-report / close register | `register-sessions.show` | `Pages/Pos/Sessions/Show.vue` |
| Registers (settings) | `settings.registers.index` | `Pages/Settings/Registers.vue` |
| Receipt / A4 invoice / credit note | `sales.print`, `sale-returns.print` (`?format=80mm\|58mm\|a4&autoprint=1`) | `resources/views/print/*.blade.php` |

## Features
**Registers & shifts**
- ✅ Registers (counters) per branch; a new branch gets "Counter 1" automatically
- ✅ Open a shift with an opening float, or join the shift already open on a register (one open shift per register)
- ✅ Cash in / out with reason (float top-up, petty expenses, bank deposit) — can't take out more than is in the drawer
- ✅ Close with counted cash → expected vs counted difference; printable **Z-report** (bills, sales, discounts, GST, payments by method, change, refunds, cash drawer)

**Billing screen**
- ✅ Full-screen, keyboard-first: **F2** search · **F4** customer · **F6** bill discount · **F8** hold · **F9** pay · **F10** returns · **Enter** completes / starts the next sale
- ✅ Scan a tag → exact barcode/SKU adds the item immediately (beep on scan, optional); scanning again adds one more
- ✅ Search by name / article no. / SKU → results grouped by product with **size/colour chips showing stock**
- ✅ Cart line: quantity stepper (decimals for metre-sold fabric), click to edit price (permission, ≤ MRP) and item discount % or ₹ (permission)
- ✅ Customer: walk-in by default; find by mobile number or name; quick-add (name, mobile, GSTIN); shows balance, wholesale badge, member discount, IGST badge
- ✅ Customer group: **wholesale prices** and **member discount %** applied automatically
- ✅ Bill discount % or ₹ with quick 5/10/15/20% buttons (permission)
- ✅ Live totals: subtotal, discounts, taxable, CGST+SGST or IGST, round-off, total — computed by `Pos/gst.js`, an exact mirror of the server calculator
- ✅ Payment: cash (exact or ₹100/200/500/2000 notes → change), card / UPI with reference, **on account** (credit within limit, or using store credit), **exchange credit note**; split payments
- ✅ Hold bills (any cashier of the branch can recall; prices/GST/stock are refreshed on recall) and clear
- ✅ After sale: big "Change ₹X" screen, auto-print receipt (setting), reprint receipt / A4 invoice, reprint last bill from the top bar
- ✅ Lock screen / **switch cashier with PIN** (rate-limited) without leaving the register
- ✅ The search box keeps focus (after printing, after closing dialogs, when the window regains focus) so a scanner always works

**Returns & exchanges** (`pos.returns`, granted to cashiers by default)
- ✅ Find the invoice by number (scan or type), choose pieces and quantities, untick "back to stock" for damaged pieces
- ✅ Refund as: **exchange credit** (applied to the current bill — the classic size swap), cash (from the drawer), UPI, card, or store credit on the customer's account
- ✅ Credit note (`CNBR1/2627/00001`) printable; exchange credit can also be used later by entering its number at payment
- ✅ If the exchange credit is larger than the new bill, the difference is paid back as change

**Printing**
- ✅ Thermal 80 mm / 58 mm receipt: store, GSTIN, bill no., items with HSN/GST %, MRP savings, discounts, GST split, payments, change, "You saved ₹X"
- ✅ A4 tax invoice: both GSTINs, place of supply, line-wise taxable/GST, HSN-wise summary, amount in words (Indian numbering), terms, signature
- ✅ Credit note print
- ✅ Prints through a hidden iframe (`Pos/helpers.js → printUrl`) — any printer the OS knows

## Business rules
- A register shift must be open to bill; cash refunds also need the shift (cash leaves the drawer).
- The server recalculates every bill (`GstCalculator`). The client sends `expected_total`; if it differs (price/rate changed), the sale is refused with the new total — the cashier never charges a different amount than shown.
- **GST per line**: Garments rate decided per piece from the taxable value *after discounts* (5% ≤ ₹2,500, else 18%); bill discounts are shared across lines before the test. Customer state ≠ branch state → IGST.
- **Price override** needs `pos.price_override` and can't exceed MRP. **Discounts** need `pos.discount` — except the customer group's automatic member discount.
- **Payments**: total tendered ≥ bill; only cash or exchange credit can produce change; card/UPI/account can't exceed the bill; on account needs a named customer and balance + amount ≤ credit limit; each exchange credit is used once, in full.
- **Ledger** (named customers only): sale → debit; money received now → credit; return → credit; cash/card/UPI refund → debit. Store credit = negative balance, usable via "on account".
- **Stock** leaves at sale (`StockMovementType::Sale`, at average cost — saved on the line for profit) and returns at the original cost on a restocked return.
- **Invoice numbers**: `{prefix}{branch}/{FY}/{seq}` within GST's 16 characters, gap-free per branch and FY (`DocumentNumberService::gstNumber`); credit notes use their own sequence.
- **Returns** refund the proportional share of each line; the last remaining pieces take exactly what is left, and rupee rounding is cumulative per invoice — returning pieces one by one never refunds more than was paid.
- Completed sales are immutable; cancellation arrives in Phase 4.

## Permissions
`pos.access` (billing), `pos.discount`, `pos.price_override`, `pos.returns`, `pos.register.manage` (open/close, cash in/out, shift history). Viewing/printing sales: `sales.view`.

## Code
- `app/Support/Gst/GstCalculator.php` ⟷ `resources/js/Pos/gst.js` (kept identical; a fuzz test of 2,000 random carts matched to the paisa)
- `app/Modules/Pos/` — `Models/{Register, RegisterSession, CashMovement, HeldCart}`, `Services/RegisterService`, `Http/Controllers/{PosController, CheckoutController, HeldCartController, ReturnController, RegisterSessionController, RegisterController}`
- `app/Modules/Sales/` — `Models/{Sale, SaleItem, SalePayment, SaleReturn, SaleReturnItem}`, `Services/{CheckoutService, SaleReturnService}`, `Http/Controllers/{SaleController, PrintController}`
- Frontend: `stores/cart.js` (Pinia), `resources/js/Pos/*.vue`, `Pages/Pos/*`, `Pages/Sales/*`
- `app/Support/AmountInWords.php`
