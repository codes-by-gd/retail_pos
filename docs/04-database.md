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
| `sales` | ✅ | `invoice_no` (≤16 chars, unique), `branch_id`, `register_session_id`, `customer_id`, `user_id` (cashier), `sold_at`; customer snapshot (`customer_name`, `customer_phone`, `customer_gstin`, `place_of_supply`, `is_inter_state`, `price_type`); totals (`subtotal`, `line_discount_total`, `bill_discount_type/value/total`, `taxable_total`, `cgst/sgst/igst/cess_total`, `round_off`, `grand_total`); `paid_total`, `change_given`, `account_amount`; `status` (completed/cancelled), `payment_status` (paid/partial/due), `note` |
| `sale_items` | ✅ | snapshot per line: `product_variant_id`, `product_name`, `variant_name`, `sku`, `hsn_code`, `unit`, `price_includes_tax`, `mrp`, `list_price`, `unit_price`, `quantity`, `returned_quantity`, `discount_type/value`, `line_discount`, `bill_discount_share`, `taxable_value`, `gst_rate` (applied), `cgst`, `sgst`, `igst`, `cess_rate`, `cess`, `line_total`, `unit_cost` (avg cost at sale → profit) |
| `sale_payments` | ✅ | `method` (cash/card/upi/account/exchange), `amount` (as tendered), `reference`, `sale_return_id` (exchange credit used) |
| `sale_returns` | ✅ | credit note: `credit_note_no`, `sale_id`, `branch_id`, `register_session_id`, `customer_id`, `user_id`, `returned_at`, tax totals, `round_off`, `total`, `refund_method` (cash/card/upi/account/exchange), `used_in_sale_id` (exchange credit), `reason` |
| `sale_return_items` | ✅ | `sale_item_id`, `product_variant_id`, `quantity`, `restock`, `taxable_value`, `cgst`, `sgst`, `igst`, `cess`, `line_total` |
| `held_carts` | ✅ | parked bills: `branch_id`, `user_id`, `label`, `payload` JSON |
| `quotations` (+`_items`) | ⏳ P4 | convertible to a sale |

## Purchases (Phase 4)
| Table | Status | Notes |
|---|---|---|
| `purchase_orders` (+`_items`) | ⏳ | ordered quantities, expected date |
| `purchases` (+`_items`) | ⏳ | GRN/bill: supplier invoice no., received quantities, cost, GST input |
| `purchase_payments` | ⏳ | payments to suppliers |
| `purchase_returns` (+`_items`) | ⏳ | debit notes |

## Other
| Table | Status | Notes |
|---|---|---|
| `expense_categories`, `expenses` | ⏳ P6 | branch, category, amount, GST input (optional) |
| `notifications` | ⏳ P6 | Laravel database notifications |
| `cache`, `jobs`, `sessions`, `password_reset_tokens` | ✅ | Laravel framework tables |
