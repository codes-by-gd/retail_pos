# 04 · Database

Status: ✅ = migration exists · ⏳ = planned (columns indicative, finalised when the phase is built).

Conventions: `id` big-int primary keys, `timestamps()` on every business table, soft deletes on master data, money `DECIMAL(15,2)`, quantity `DECIMAL(15,3)`, GST state codes `CHAR(2)`.

## Organisation & settings
| Table | Status | Key columns / notes |
|---|---|---|
| `settings` | ✅ | `key` (unique, dotted e.g. `business.gstin`), `value` JSON. Defaults in `SettingsService::DEFAULTS`. |
| `branches` | ✅ | `name`, `code` (unique, used in document numbers), `gstin` (optional, falls back to business), `state_code`, address fields, `is_active`, soft deletes |
| `branch_user` | ✅ | pivot `branch_id` + `user_id` — which branches a user can work in |
| `registers` | ✅ | `branch_id`, `name` (unique per branch), `is_active` |
| `register_sessions` | ✅ | shift: `register_id`, `branch_id`, `opened_by`, `opened_at`, `opening_cash`, `status` (open/closed), `closed_by`, `closed_at`, `expected_cash`, `counted_cash`, `difference` |
| `cash_movements` | ✅ | `register_session_id`, `type` (in/out), `amount`, `reason`, `user_id` |
| `document_sequences` | ✅ | `branch_id`, `type` (ADJ, TRF, later INV…), `financial_year` ("26-27"), `last_number` — row locked while numbering |

## Users & access
| Table | Status | Notes |
|---|---|---|
| `users` | ✅ | + `phone`, `pin` (hashed POS PIN), `is_active`, `last_login_at` |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | ✅ | spatie/laravel-permission |
| `activity_log` | ✅ | spatie/laravel-activitylog — who changed what |

## Catalog & stock (Phase 1)
| Table | Status | Notes |
|---|---|---|
| `categories` | ✅ | `parent_id` (nested, null on parent delete), `name` (unique per parent), `code`, `is_active`, soft deletes |
| `brands` | ✅ | `name` (unique), `is_active`, soft deletes |
| `units` | ✅ | `name`, `short_name` (unique), `allow_decimal` |
| `tax_rates` | ✅ | `name`, `rate` DECIMAL(5,2), `cess_rate`, `threshold_amount` + `rate_above` (price-based garments rate: `rate` up to the threshold per piece, `rate_above` beyond), `is_active` |
| `attributes` | ✅ | variant dimensions: `name` (unique: Colour, Size, Shirt Size, Waist, Kids Age), `sort_order` (order in variant names) |
| `attribute_values` | ✅ | `attribute_id`, `value` (unique per attribute), `sort_order` (S, M, L…) |
| `products` | ✅ | `name`, `article_no` (brand style code, indexed), `material` (fabric), `type` (single/variable), `category_id`, `brand_id`, `unit_id`, `tax_rate_id`, `hsn_code`, `price_includes_tax`, `track_stock`, `description`, `image_path` (public disk), `is_active`, soft deletes |
| `product_variants` | ✅ | the sellable SKU: `product_id`, `name` ("Navy / 40", built from attribute values; null for single), `sku` (unique), `purchase_price`, `mrp`, `selling_price`, `wholesale_price`, `reorder_level` DECIMAL(15,3), `is_active`, soft deletes |
| `attribute_value_product_variant` | ✅ | pivot: which values (Navy, 40) make up a variant; values in use can't be deleted |
| `product_barcodes` | ✅ | `product_variant_id`, `barcode` (unique) — several per variant |
| `stock_levels` | ✅ | `product_variant_id` + `branch_id` (unique pair), `quantity` DECIMAL(15,3), `avg_cost` DECIMAL(15,4). Written only by `StockService`. |
| `stock_movements` | ✅ | append-only ledger: `product_variant_id`, `branch_id`, `type` (see `StockMovementType`), signed `quantity`, `unit_cost`, `balance_after`, `reference_type/id` (morph to adjustment, transfer, later sale/purchase), `note`, `user_id`, `created_at` |
| `stock_adjustments` | ✅ | `reference_no` (unique), `branch_id`, `date`, `reason`, `note`, `user_id` |
| `stock_adjustment_items` | ✅ | `product_variant_id`, signed `quantity`, `unit_cost` |
| `stock_transfers` | ✅ | `reference_no`, `from_branch_id`, `to_branch_id`, `status` (in_transit/received/cancelled), `note`, `user_id`, `received_by`, `received_at` |
| `stock_transfer_items` | ✅ | `product_variant_id`, `quantity`, `unit_cost` (sender's average at dispatch) |
| `batches` | ⏳ | optional batch/expiry tracking |
| `product_images` | ⏳ | multiple images (today: one `products.image_path`) |

## Contacts (Phase 2)
| Table | Status | Notes |
|---|---|---|
| `customer_groups` | ✅ | `name` (unique), `price_type` (retail/wholesale), `discount_percent` |
| `contacts` | ✅ | `code` (unique, CUS-/SUP-/WALK-IN), `type` (customer/supplier/both), `name`, `company_name`, `phone` (unique, normalised digits), `alt_phone`, `email`, `gstin`, `state_code`, `address`, `city`, `pincode`, `customer_group_id`, `credit_limit` (0 = no credit), `payment_terms_days`, `date_of_birth`, `anniversary`, `notes`, `is_walk_in`, `is_active`, soft deletes |
| `contact_ledger_entries` | ✅ | append-only account ledger: `contact_id`, `branch_id`, `date`, `type` (see `LedgerEntryType`), `debit`, `credit`, `reference_type/id` (morph to sale, payment, purchase…), `note`, `user_id`, `created_at`. Balance = Σdebit − Σcredit (+ = receivable, − = payable) |
| `contact_addresses` | ⏳ | multiple shipping addresses — not needed for counter retail yet; one address lives on `contacts` |

## Sales (Phase 3)
| Table | Status | Notes |
|---|---|---|
| `sales` | ✅ | (+ Phase 4: `due_amount`, `cancelled_at`, `cancelled_by`, `cancel_reason`) `invoice_no` (≤16 chars, unique), `branch_id`, `register_session_id`, `customer_id`, `user_id` (cashier), `sold_at`; customer snapshot (`customer_name`, `customer_phone`, `customer_gstin`, `place_of_supply`, `is_inter_state`, `price_type`); totals (`subtotal`, `line_discount_total`, `bill_discount_type/value/total`, `taxable_total`, `cgst/sgst/igst/cess_total`, `round_off`, `grand_total`); `paid_total`, `change_given`, `account_amount`; `status` (completed/cancelled), `payment_status` (paid/partial/due), `note` |
| `sale_items` | ✅ | snapshot per line: `product_variant_id`, `product_name`, `variant_name`, `sku`, `hsn_code`, `unit`, `price_includes_tax`, `mrp`, `list_price`, `unit_price`, `quantity`, `returned_quantity`, `discount_type/value`, `line_discount`, `bill_discount_share`, `taxable_value`, `gst_rate` (applied), `cgst`, `sgst`, `igst`, `cess_rate`, `cess`, `line_total`, `unit_cost` (avg cost at sale → profit) |
| `sale_payments` | ✅ | `method` (cash/card/upi/account/exchange), `amount` (as tendered), `reference`, `sale_return_id` (exchange credit used) |
| `sale_returns` | ✅ | credit note: `credit_note_no`, `sale_id`, `branch_id`, `register_session_id`, `customer_id`, `user_id`, `returned_at`, tax totals, `round_off`, `total`, `refund_method` (cash/card/upi/account/exchange), `used_in_sale_id` (exchange credit), `reason` |
| `sale_return_items` | ✅ | `sale_item_id`, `product_variant_id`, `quantity`, `restock`, `taxable_value`, `cgst`, `sgst`, `igst`, `cess`, `line_total` |
| `held_carts` | ✅ | parked bills: `branch_id`, `user_id`, `label`, `payload` JSON |
| `quotations` / `quotation_items` | ✅ | `reference_no`, customer (id or typed name/phone), place of supply, dates, `status` (draft/sent/accepted/rejected/converted), bill discount, GST totals like `sales`; items snapshot with discounts and computed GST |

## Purchases (Phase 4)
| Table | Status | Notes |
|---|---|---|
| `purchase_orders` / `purchase_order_items` | ✅ | `reference_no`, `branch_id`, `supplier_id`, dates, `status` (draft/ordered/partially_received/received/cancelled), `estimated_total`; items `quantity`, `received_quantity`, `unit_cost` |
| `purchases` | ✅ | goods receipt + supplier bill: `reference_no`, `supplier_id`, `purchase_order_id`, `supplier_invoice_no/date`, `received_date`, place of supply, GST totals, `other_charges`, `round_off`, `grand_total`, `paid_amount`, `due_amount`, `payment_status` |
| `purchase_items` | ✅ | snapshot, `quantity`, `returned_quantity`, `unit_cost` (excl. GST), discount, taxable, GST split, `line_total`, `landed_unit_cost` (stock cost) |
| `purchase_returns` / `purchase_return_items` | ✅ | debit notes: totals; items `quantity` and amounts |

## Payments (Phase 4)
| Table | Status | Notes |
|---|---|---|
| `contact_payments` | ✅ | `reference_no` (RCPT/PAY), `contact_id`, `branch_id`, `register_session_id` (cash through a drawer), `direction` (in/out), `method` (cash/upi/card/bank/cheque), `amount`, `unallocated`, `reference`, `date`, `note`, `user_id` |
| `payment_allocations` | ✅ | `contact_payment_id`, `allocatable` (sale or purchase), `amount` |

## Loyalty (Phase 7)
| Table | Purpose |
|---|---|
| `loyalty_transactions` | Append-only points ledger: contact, branch, `type` (earn, redeem, reverse, restore, expire, adjust), signed `points`, `remaining` (unspent part of a + row, for oldest-first use and expiry), `expires_at`, sale / sale_return, note, user · balance = SUM(points) |
| `sales.points_earned`, `sales.points_redeemed` | Snapshot for receipts and returns |

Payments with points are `sale_payments.method = 'points'` (reference "200 points").

## Expenses & notifications (Phase 6)
| Table | Purpose |
|---|---|
| `expense_categories` | name (unique), is_active |
| `expenses` | `reference_no` (EXP/BR1/26-27/0001), branch, category, date, amount, gst_amount (included), method, reference, paid_to, note, `register_session_id` (cash from a drawer), `attachment_path` (private bill photo), user · index (branch_id, date) |
| `notifications` | Laravel database notifications: uuid, notifiable (user), `data` JSON {type, title, body, url, level, key, branch}, read_at |
| `users.notification_preferences` | JSON `{off: [types], email: [types]}` |

Performance indexes added in Phase 6: `sale_returns (branch_id, returned_at)`, `contact_payments (branch_id, date)`, `purchases (branch_id, received_date)`, `purchase_returns (branch_id, date)`, `stock_movements (branch_id, created_at)`, `register_sessions (branch_id, opened_at)`, `sales (status, due_amount)`, `activity_log (created_at)`.

## Reports & dashboard (Phase 5)
No new tables. Reports read the saved documents only: `sales`/`sale_items` (bill date, GST, `unit_cost` for profit), `sale_returns`/`sale_return_items` (credit-note date, `restock`), `sale_payments`, `contact_payments`, `purchases`/`purchase_items`, `purchase_returns`, `stock_levels`, `stock_movements`, `register_sessions` and the contact ledger. The `sales (branch_id, sold_at)` index carries most date-range queries.

## Other
| Table | Status | Notes |
|---|---|---|
| `expense_categories`, `expenses` | ⏳ P6 | branch, category, amount, GST input (optional) |
| `notifications` | ⏳ P6 | Laravel database notifications |
| `cache`, `jobs`, `sessions`, `password_reset_tokens` | ✅ | Laravel framework tables |
