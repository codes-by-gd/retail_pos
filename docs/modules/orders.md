# Orders — sales management, quotations, purchasing, payments

**Status:** ✅ done (Phase 4, 2026-11-18)
**Purpose:** Everything around the counter: managing bills after the sale, quoting prices, buying stock from suppliers and settling money owed either way.

## Sales management (`sales.view`, `sales.cancel`)
- ✅ Sales list: search (invoice, customer, phone), dates, cashier, **payment status** (unpaid / part paid / paid), **status** (completed / cancelled); due amount shown per bill
- ✅ Invoice page: items, GST, payments, **payments received later** (receipt links), returns, still-due amount, reprint
- ✅ **Cancel bill** with a reason — only while its register shift is open, and only if nothing was returned or paid against it later (otherwise use a return). Stock goes back at the original cost, the customer's account is reversed, exchange vouchers used on it become valid again; cancelled bills drop out of the Z-report.
- ✅ **Dues**: the on-account part of a bill is its due amount; store credit the customer already had is used first.

## Payments (`payments.manage`)
- ✅ **Receive payment** from a customer / **Pay supplier** — from the contact page or a purchase: amount, date, mode (cash, UPI, card, bank transfer, cheque), reference (UTR / cheque no.), note
- ✅ Cash can go **through an open register drawer** (counts in that shift's Z-report) or outside it (safe / bank)
- ✅ Allocated to unpaid bills **oldest first** (a specific bill first when paying from that bill); anything left stays **on account** (settles opening balances / becomes advance)
- ✅ Numbered receipts `RCPT/BR1/26-27/0001` and vouchers `PAY/BR1/26-27/0001`, printable (A4, amount in words, bills settled)
- ✅ Payments list with in/out, mode and date filters and totals
- ✅ Contact page shows **unpaid bills** (overdue flag for suppliers using payment terms)

## Quotations (`quotations.manage`)
- ✅ Create / edit (draft & sent): saved customer *or* a typed name/phone, date, validity, items, % or ₹ line discounts, bill discount, notes; live GST preview (same calculator as the POS)
- ✅ Prices are the customer's **list price** (retail or wholesale) + discounts — no price overrides — so they convert cleanly
- ✅ Status: draft → sent → accepted / rejected → converted; expired flag after the validity date
- ✅ **Convert to bill** → becomes a held bill and opens in the POS (also through "open register" if no shift is open); prices and stock are re-checked there
- ✅ A4 print; numbering `QTN/BR1/26-27/0001`

## Purchasing (`purchases.view`, `purchases.manage`)
**Purchase orders**
- ✅ Draft → placed (ordered) → partly received → received, or cancelled; supplier, order/expected dates, items with quantity and rate (excl. GST), notes; A4 print (`PO/BR1/26-27/0001`)
- ✅ "Receive goods" pre-fills what is still outstanding

**Goods receipt + supplier bill** (`PUR/BR1/26-27/0001`)
- ✅ Supplier, supplier bill no. (duplicates per supplier refused) and date, received date, items (scan / search), qty, rate excl. GST, % or ₹ discount
- ✅ GST per line with the same calculator (garment price-based rate per piece; IGST for out-of-state suppliers); freight / other charges; rupee round-off
- ✅ **Landed cost** = rate after discount + share of freight (by taxable value), excl. GST → stock comes in at landed cost (weighted average); optional "update item purchase price"
- ✅ Against a PO: can't receive more than outstanding; PO status updates
- ✅ Payment now (part or full) with mode/reference; cash optionally from a register drawer
- ✅ Supplier credited in the ledger; an existing supplier advance settles the bill first
- ✅ **Print tags for this delivery** — label page opens with one copy per piece received

**Purchase returns** (`DN/BR1/26-27/0001`)
- ✅ Return pieces against a purchase (up to what's left), reason; debit note print
- ✅ Stock out, supplier debited, the bill's unpaid amount reduced first; amounts proportional, the last pieces take exactly what is left; freight/round-off only when everything is returned

## Business rules
- Every document is immutable; corrections via return / debit note / cancellation (sales, same shift only).
- Payment allocations and dues are a convenience view; the **contact ledger is the truth** for balances.
- Purchase rates are excluding GST (input tax credit), so GST never enters stock cost.

## Code
- `app/Modules/Contacts/` — `Models/{ContactPayment, PaymentAllocation}`, `Services/PaymentService`, `Http/Controllers/ContactPaymentController`
- `app/Modules/Sales/` — `Models/{Quotation, QuotationItem}`, `Services/{QuotationService, SaleCancelService}`, `Http/Controllers/QuotationController`
- `app/Modules/Purchases/` — `Models/{PurchaseOrder(+Item), Purchase(+Item), PurchaseReturn(+Item)}`, `Services/{PurchaseOrderService, PurchaseService, PurchaseReturnService}`, `Http/Controllers/{PurchaseOrderController, PurchaseController}`
- Frontend: `Components/{ContactPicker, PaymentFormModal}.vue`, `Pages/{Quotations,Purchases,Payments}/*`
- Prints: `resources/views/print/{payment, quotation, purchase-order, debit-note}.blade.php`
