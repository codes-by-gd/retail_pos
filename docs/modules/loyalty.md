# Loyalty points

**Status:** ✅ done (Phase 7, 2026-11-16)
**Purpose:** Reward repeat customers: points on every bill, spent like money at the counter.

## Rules (Settings → Business → Loyalty points; defaults shown)
| Setting | Default | Meaning |
|---|---|---|
| Loyalty points on | on | Off = nothing earned or used (balances are kept) |
| ₹ spent for 1 point | 100 | Points = ⌊(bill total − part paid with points) ÷ 100⌋ |
| 1 point is worth | ₹1 | Value when used at the POS |
| Min. points to use | 100 | Fewest points per redemption |
| Max. % of a bill | 50 % | Points can pay at most this share of a bill |
| Expire after | 12 months | Each batch expires 12 months after it was earned (0 = never) |

- Only **named customers** — never the walk-in customer.
- **Using points is a payment method** (`Loyalty points`), not a discount: GST and the bill total are unchanged, the customer's account is credited like money (D-042).
- Oldest points are used first; nightly `pos:expire-points` (00:30) removes what is left of expired batches.
- **Returns** take back the points earned on the returned share of the bill (cumulative; never below a zero balance). Points used on a bill are not given back on a return (the refund goes by the chosen method).
- **Cancelling** a bill takes back its earned points and gives back points used on it.
- Manual adjustments (+/−) with a reason need `loyalty.adjust` (Manager by default, not Cashier) and are audit-logged.

## Where it shows
- POS: ★ points badge on the customer, **Points** button in the payment dialog (fills the most allowed; editable in whole points), points earned/used/balance on the sale-complete screen
- Thermal receipt and A4 invoice: points used, earned and balance
- Customer page: balance and value, next expiry, recent history (earned / redeemed / taken back / given back / expired / adjustment), adjust form
- Report **Loyalty points** (Sales group): earned, used, taken back, expired, adjusted per customer for the period, balance and value now (the outstanding liability)

## Code
`app/Modules/Loyalty/` — `Models/LoyaltyTransaction` (append-only ledger; `remaining` on + rows for FIFO and expiry), `Services/LoyaltyService` (rules, balance, `checkRedemption`, `afterSale/afterReturn/afterCancel`, `adjust`, `expire`), `Http/Controllers/LoyaltyController`. Hooks in `CheckoutService`, `SaleReturnService`, `SaleCancelService`. Report `LoyaltyReport`. Tests `tests/Feature/Loyalty`.
