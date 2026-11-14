# 06 · GST rules

This page describes how the system applies Indian GST. Billing logic (Phase 3) and reports (Phase 5) must follow it.

## Intra-state vs inter-state
- **Seller state** = the branch's `state_code` (branch GSTIN, or the business GSTIN if the branch has none).
- **Place of supply** = the customer's state if known (B2B customers always have one via GSTIN); walk-in counter sales use the branch state.
- Same state → **CGST + SGST** (rate split in half). Different state → **IGST** (full rate).

## Rates
- Since the GST rate rationalisation effective **22 Sep 2025**, the main slabs are 0%, 5% and 18% (40% for a few luxury/sin goods). Stored in `tax_rates` (+ optional **cess**); each product has one rate and an **HSN code**.
- **Garments are price-based** — the seeded rate *Garments*: **5%** when the sale value of one piece is **up to ₹2,500**, **18%** above. A tax rate can carry `threshold_amount` + `rate_above` for this; `TaxRate::rateFor($taxableValuePerUnit)` returns the applicable %.
  - The test is on the **taxable value per piece after discount** (excluding GST). A ₹2,999 MRP shirt sold with a discount that brings the per-piece taxable value to ₹2,500 or less is taxed at 5%.
  - With GST-inclusive prices, the boundary is around ₹2,625 (₹2,500 + 5%); the billing calculator (Phase 3) resolves the rate per line from the taxable value, not from the sticker price.
  - Fabrics (e.g. sarees classified as fabric, shirting/suiting by the metre) are generally 5% flat; leather belts and similar accessories may be 18%. **Confirm rates and HSN codes with your tax adviser** — they're editable under Catalog setup → GST rates.
- Typical HSN chapters: **61** knitted/crocheted apparel (T-shirts 6109, leggings 6104, socks 6115), **62** woven apparel (shirts 6205, trousers/jeans 6203, women's 6204), fabrics **50–55**, leather goods **42**; tailoring/alteration service SAC **9988**.
- Rates are **snapshotted on each sale line** (the actual % applied after the price test), so later rate changes never alter old invoices.

## Inclusive vs exclusive pricing
- Setting `tax.prices_include_tax` (default **on**, MRP-style retail). Each product can override it.
- Inclusive: `taxable = price × qty ÷ (1 + rate)`; tax = price − taxable.
- Exclusive: `tax = taxable × rate`.
- Discounts reduce the **taxable value** before tax is computed.
- Tax is calculated **per line** and rounded to 2 decimals; the bill total may be rounded to the nearest rupee (`tax.round_off`), with the difference shown as "Round off".

## Validation
- GSTIN format: `2-digit state + 10-char PAN + entity digit + 'Z' + check digit`, validated by [`App\Rules\Gstin`](../app/Rules/Gstin.php) including the checksum. The first two digits must match the selected state.

## Documents
- **Tax invoice** for registered (B2B) buyers: GSTIN of both parties, HSN, taxable value and tax per rate.
- **Bill of supply / retail invoice** for walk-in customers.
- **Credit note** for returns, **debit note** for purchase returns.
- Invoice numbers are unique per branch and **financial year** (starts 1 April by default), max 16 characters, e.g. `BR1/25-26/000123`.

## Reports (Phase 5)
- GSTR-1 style: B2B invoices, B2C large/small, credit/debit notes, **HSN summary**.
- GSTR-3B style summary: output tax vs input tax credit (from purchases).

> E-invoicing (IRN/QR) and e-way bills are not in scope yet; see the [Roadmap](07-roadmap.md).
