# POS billing

**Status:** ⏳ planned (Phase 3) — highest-priority screen
**Purpose:** Bill customers as fast as possible, with minimal clicks and full keyboard/scanner support.

## Layout
Full-screen (no sidebar). Left: search/scan box (always focused), category tiles, product results. Right: cart, customer, totals, big **Pay** button.

## Features
- Add items by barcode scan, SKU, name search (instant), or category tiles (touch screens)
- Cart: qty +/−, decimal quantity for weighed items, line discount (% or ₹), price override (permission), note, remove
- Customer search by phone, quick-add; walk-in default
- Bill discount, round-off, live GST breakdown
- Split payments: cash (change due), card, UPI, wallet, credit (on account)
- Hold/park bills and recall; multiple held bills
- Returns & exchanges against an original invoice → credit note / refund
- Print thermal (58/80 mm) or A4 invoice; reprint last bill
- Register sessions: open with float, cash in/out, close with counted cash → Z-report
- PIN lock / quick cashier switch
- Keyboard shortcuts (proposed): F2 search · F4 customer · F6 discount · F8 hold · F9 pay · F12 complete · Esc cancel

## Building blocks ready from earlier phases
- Item lookup: `variants.search` + `Components/VariantPicker.vue` (scanner friendly).
- Customer lookup / quick add: `contacts.search`, `contacts.quick-store` (returns group price type, discount %, balance, credit limit, state).
- Walk-in customer: `Contact::where('is_walk_in', true)`.
- GST: `TaxRate::rateFor($taxablePerUnit)`; customer `state_code` vs branch `state_code` → CGST+SGST or IGST.
- Stock: `StockService::move(..., StockMovementType::Sale, ...)`; numbering: `DocumentNumberService`; money owed: `ContactLedgerService::record()`.

## Clothing notes
- Scanning a tag adds that exact size/colour; a product search shows each size/colour with its stock.
- GST per line uses the product's rate; for *Garments* the % is picked from the per-piece taxable value after discounts (5% ≤ ₹2,500, else 18%).
- Exchanges (different size/colour) are the most common return case — design the return flow around a quick swap.
- Alteration charges are a non-stock service item.

## Business rules
- A register session must be open to bill.
- Checkout runs in one transaction (`CreateSaleAction`): invoice number, stock deduction, payments, GST snapshot.
- Completed bills are immutable; corrections via return or cancel (permission + audit).

## Permissions
`pos.access`, `pos.discount`, `pos.price_override`, `pos.returns`, `pos.register.manage`.
