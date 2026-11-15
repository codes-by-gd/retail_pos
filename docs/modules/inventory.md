# Inventory

**Status:** ✅ done (Phase 1, 2026-11-15) · a few items ⏳ later
**Purpose:** Everything about what the shop sells and how much of it is where.

## Clothing specifics
- **Attributes** (Catalog setup → Sizes & Colours): Colour, Size (XS–4XL, Free Size), Shirt Size (36–46), Waist (26–42), Kids Age (0-6M…12-14Y). Order of values = order on screens/labels. Add your own (e.g. Footwear Size, Cup Size).
- **Creating a garment**: pick *Sizes / colours* → choose what it varies by (max 3) → tick values or type new ones → set default prices → every combination becomes a row (SKU and EAN-13 auto). Remove combinations you don't stock with ✕.
- **Article / style no.** and **fabric** on each product; search finds products by article no.
- **GST**: use the *Garments* rate for apparel (5% up to ₹2,500 per piece, 18% above); *GST 5%* for fabric/sarees; *GST 18%* for items like leather belts.
- **Stock matrix** on the product page: colour rows × size columns, per branch or all branches; red = out, amber = at/below reorder level.
- **Tags**: brand, product, size/colour, article no., MRP (struck through when discounted), price, barcode; 50 × 75 mm hang-tag preset.
- **Import**: one CSV row per size/colour; rows with the same article no. become one product; add a column per attribute (Colour, Size…).

## Key concepts
- **Product** = what you describe (name, category, brand, unit, HSN, GST rate, image).
- **Variant** = what you sell and count. Every product has at least one; a *single* product has exactly one unnamed variant, a *variable* product has one per combination of attribute values (e.g. Navy / 40). SKU, barcodes, prices, reorder level and stock all live on the variant.
- **Stock level** = quantity + weighted-average cost of one variant at one branch.
- **Stock movement** = one row in the append-only **stock ledger**. Quantities only ever change through `StockService`.

## Features
**Catalog setup** (`catalog.manage`)
- ✅ Categories, nested to any depth, shown as "Food › Biscuits"; cycles are blocked
- ✅ Brands · Units (with "allow decimal" for fabric by the metre) · GST rates (rate + cess, optional price-based higher rate)
- ✅ Sizes & Colours: attributes with ordered values; renaming a value renames variants; values in use can't be removed
- ✅ Masters in use cannot be deleted; a GST rate in use cannot change its percentage (create a new rate instead)
- ✅ Default GST slabs (0/5/12/18/28) and units (pcs, kg, g, ltr, ml, mtr, box, dzn, pack) seeded on install

**Products**
- ✅ List with search (name, SKU, exact barcode), category/brand/status filters, stock at current branch, thumbnails
- ✅ Create/edit: details, image upload, track-stock flag, prices include GST flag
- ✅ Single or variable products; add many variants at once ("S, M, L, XL") copying the last row's prices
- ✅ Per variant: SKU (auto `P00012` / `P00013-2` if blank), barcodes (several, comma separated; auto in-store EAN-13 if blank), purchase price, MRP, selling, wholesale, reorder level
- ✅ Opening stock on create (current branch, at purchase price)
- ✅ Product page: details, barcodes, prices, avg cost, stock per branch, link to history
- ✅ Soft delete (history kept); removing a variant in the form soft-deletes it
- ✅ CSV import (create single products, update prices by SKU, per-row error report, categories/brands auto-created) and CSV export; downloadable template

**Stock**
- ✅ Stock on hand at the current branch: totals (items, value at cost, retail value), filters (in / low-or-out / out of stock, category, search)
- ✅ Stock ledger with filters (item, movement type, dates) and links to source documents
- ✅ Adjustments: reasons (count correction, damage, expiry, theft, internal use, found, other); **add/remove** mode or **stock count** mode (enter counted qty, the difference is posted under lock)
- ✅ Transfers between branches: send (stock leaves source, "in transit") → receive at destination (switch to that branch) or cancel from source (stock returns)
- ✅ Document numbers per branch & financial year: `ADJ/BR1/26-27/0001`, `TRF/BR1/26-27/0001`

**Labels** (`labels.print`)
- ✅ Pick items (search/scan or "Print labels" from the product list / product page), copies per item, "copies = stock on hand"
- ✅ Presets: A4 sheets with 65 / 40 / 24 labels, thermal rolls 50×25, 38×25, 100×50 mm; skip N labels on a used sheet
- ✅ Choose fields: business name, product name, price, MRP (struck through when higher), SKU; EAN-13 or Code128 barcode
- ✅ Browser printing with exact mm sizes; settings remembered per computer

**Later**
- ⏳ Batch & expiry tracking (schema not yet created)
- ⏳ Multiple images per product
- ⏳ Excel (.xlsx) import/export (CSV today)
- ✅ Print labels straight from a purchase (Phase 4)

## Screens
| Screen | Route | Page |
|---|---|---|
| Catalog setup | `categories.index`, `brands.index`, `attributes.index`, `units.index`, `tax-rates.index` | `Pages/Inventory/Catalog/*` |
| Products | `products.index` / `create` / `edit` / `show` | `Pages/Inventory/Products/*` |
| Import products | `products.import` | `Pages/Inventory/Products/Import.vue` |
| Stock on hand | `stock.index` | `Pages/Inventory/Stock/Index.vue` |
| Stock ledger | `stock.ledger` | `Pages/Inventory/Stock/Ledger.vue` |
| Adjustments | `stock-adjustments.*` | `Pages/Inventory/Adjustments/*` |
| Transfers | `stock-transfers.*` | `Pages/Inventory/Transfers/*` |
| Labels | `labels.index` | `Pages/Inventory/Labels/Index.vue` |
| Item search (JSON) | `variants.search` | used by `Components/VariantPicker.vue` |

## Business rules
- **All stock changes go through `StockService::move()`**: transaction + row lock on `stock_levels` + ledger row with balance after.
- **Costing: weighted average per branch.** Incoming stock with a cost updates the average; outgoing stock leaves at the current average. If stock was zero/negative, the incoming cost becomes the new average. Transfers carry the sender's average cost to the receiver.
- Negative stock is refused unless the setting *Allow selling out-of-stock items* is on. Adjustments and transfers never allow negative stock.
- Products with *Track stock* off never create stock rows (services, non-inventory items).
- Selling price may not exceed MRP. SKUs and barcodes are unique across all items (including deleted ones for SKUs).
- In-store barcodes are EAN-13 starting with `2` (GS1 range for internal use), derived from the variant id.
- Completed adjustments/transfers are never edited; correct with another adjustment or cancel an in-transit transfer.

## Permissions
`products.view` (products, stock, ledger) · `products.manage` (create/edit/import) · `products.delete` · `catalog.manage` · `stock.adjust` · `stock.transfer` · `labels.print`. The item search API accepts any of `products.view`, `stock.adjust`, `stock.transfer`, `labels.print`, `pos.access`.

## Code
`app/Modules/Inventory/`
- `Models/` Category, Brand, Attribute, AttributeValue, Unit, TaxRate, Product, ProductVariant, ProductBarcode, StockLevel, StockMovement, StockAdjustment(+Item), StockTransfer(+Item)
- `Enums/StockMovementType`
- `Services/` **StockService**, ProductService, BarcodeService, StockAdjustmentService, StockTransferService, ProductCsvService, InsufficientStockException
- `Http/Controllers/` Category, Brand, Unit, TaxRate, Product, ProductImport, VariantSearch, Stock, StockAdjustment, StockTransfer, Label
- Shared: `app/Modules/Settings/Services/DocumentNumberService`, `app/Support/FinancialYear`
- Frontend helpers: `Components/VariantPicker.vue`, `BarcodeSvg.vue`, `CatalogTabs.vue`, `RowActions.vue`, `composables/useCrudModal.js`

## Data
See [Database › Catalog & stock](../04-database.md#catalog--stock-phase-1).
