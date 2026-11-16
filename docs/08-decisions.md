# 08 · Decision log

Short records of significant decisions. Newest last. Format: context → decision → consequences.

### D-001 · Laravel + Vue (Inertia) monolith — 2026-11-14
**Context:** team works in PHP; POS screen must feel instant. **Decision:** Laravel 12 backend with Vue 3 pages via Inertia; small JSON endpoints only where the POS needs them (product search, checkout). **Consequences:** one codebase and deploy; a public API for a mobile app would be extra work later.

### D-002 · Single business, multiple branches — 2026-11-14
**Decision:** no multi-tenancy. Branch-owned data carries `branch_id`; users are assigned to branches. **Consequences:** simpler queries; SaaS would need a tenant layer later.

### D-003 · Indian GST built in — 2026-11-14
**Decision:** GST (CGST/SGST/IGST, HSN, GSTIN) is a core concept, not a plugin. See [GST rules](06-gst.md).

### D-004 · Online only — 2026-11-14
**Decision:** the POS needs a connection to the server (LAN or cloud). Offline PWA is a possible later phase.

### D-005 · Permissions over roles — 2026-11-14
**Decision:** code checks permissions (`can:products.manage`), never role names — except Admin, which bypasses checks. Catalogue in `App\Support\Permissions`. **Consequences:** shop owners can create custom roles without code changes.

### D-006 · Module folders under `app/Modules` — 2026-11-14
**Decision:** each business module keeps its models, services, controllers and requests together under `app/Modules/<Module>`. `User` stays in `app/Models` (Laravel auth default).

### D-007 · Settings as key/value JSON — 2026-11-14
**Decision:** a `settings` table with dotted keys, cached as one array, with defaults in code. **Consequences:** adding a setting needs no migration; settings are not type-checked by the DB, so Form Requests must validate them.

### D-008 · No self-registration; accounts are never hard-deleted by users — 2026-11-14
**Decision:** removed Breeze registration and "delete my account". Staff are deactivated (`is_active = false`) instead of deleted, because sales and stock movements will reference them.

### D-009 · Sales are immutable — 2026-11-14
**Decision:** completed sales and purchases are never edited; corrections are made via returns, credit/debit notes or cancellation (with audit log). Keeps GST records and stock ledger consistent.

### D-010 · One stock service with a ledger — 2026-11-14
**Decision:** every stock change goes through `StockService` in a DB transaction with row locks and writes a `stock_movements` row. Prevents overselling and keeps stock history auditable.

### D-011 · Every sellable item is a variant — 2026-11-15
**Decision:** a product always has ≥1 `product_variants` row; single products have one unnamed variant. Stock, barcodes, prices and (later) sale lines always point to a variant. **Consequences:** one code path for single and variable products; queries join through variants.

### D-012 · Weighted average cost per branch — 2026-11-15
**Context:** open question from Phase 0. **Decision:** weighted average, kept on `stock_levels.avg_cost` and recalculated on every costed inflow; outflows take the current average. Transfers carry the sender's average to the receiver. **Consequences:** simple and standard for Indian retail; FIFO would need cost layers (not planned). Only `StockService` would change if this is revisited.

### D-013 · Document numbers `TYPE/BRANCH/FY/SEQ` — 2026-11-15
**Decision:** internal documents use `ADJ/BR1/26-27/0001` from `document_sequences` (locked row, inside the document's transaction, so numbers are gap-free). GST invoices (Phase 3) still need a ≤16-character format — see open questions.

### D-014 · Two-step stock transfers — 2026-11-15
**Decision:** stock leaves the source on *send* (in transit) and enters the destination only when someone at that branch *receives* it; the sender can cancel while in transit. **Consequences:** goods on the road are not counted in either branch; receiving requires switching to the destination branch.

### D-015 · CSV (not Excel) for import/export; browser printing for labels — 2026-11-15
**Decision:** native CSV via `fgetcsv/fputcsv` instead of adding `maatwebsite/excel`; labels rendered with JsBarcode and printed with mm-exact print CSS instead of server PDFs. **Consequences:** no extra dependencies, works with any printer the OS knows; .xlsx can be added later if users need it.

### D-016 · Business-rule exceptions become flash errors — 2026-11-15
**Decision:** `DomainException` and `InsufficientStockException` are rendered globally (`bootstrap/app.php`) as "back with error" (or JSON 422). Services throw; controllers stay free of try/catch.

### D-017 · Clothing store focus; variants from attributes — 2026-11-15
**Context:** the business is a clothing store. **Decision:** variants are defined by attribute values (`attributes`, `attribute_values`, pivot to variants) instead of free-text names; `product_variants.name` is a denormalised label ("Navy / 40") rebuilt when values are renamed. Several size systems are separate attributes (Size, Shirt Size, Waist, Kids Age) rather than one long list. **Consequences:** size/colour reports and stock matrices are possible; a product varies by at most 3 attributes.

### D-018 · Price-based GST on the tax rate — 2026-11-15
**Decision:** `tax_rates.threshold_amount` + `rate_above` express "x% up to ₹N per piece, y% above"; the rate actually applied is decided per sale line from the per-piece taxable value after discount (Phase 3) and snapshotted. **Consequences:** one product setting covers cheap and premium items of the same type; the threshold is data, so a future change in law is a settings edit.

### D-019 · Phase 1 migrations edited in place — 2026-11-15
**Decision:** because nothing had been committed or deployed, the clothing changes were folded into the original Phase 1 migrations instead of new alter-table migrations. From the first commit / deployment onward, schema changes must be new migrations.

### D-020 · One contacts table with an append-only ledger — 2026-11-16
**Decision:** customers and suppliers share `contacts` (`type` customer/supplier/both). Money owed is an append-only `contact_ledger_entries` table (Σdebit − Σcredit), mirroring the stock ledger; documents in Phases 3–4 post entries, only the opening balance is editable. **Consequences:** balances and statements are simple sums; a contact who is both customer and supplier has a single net balance.

### D-021 · Phone is the customer key — 2026-11-16
**Decision:** phone numbers are normalised to digits (strip +91 / leading 0 / punctuation) and unique; required for customers (not for suppliers or the walk-in). **Consequences:** fast counter lookup and no duplicate customers; a customer without a phone is billed as walk-in.

### D-022 · Single address per contact — 2026-11-16
**Decision:** address fields live on `contacts`; the planned `contact_addresses` table is deferred until delivery/shipping is needed.

### D-023 · One GST calculator, mirrored in the browser — 2026-11-17
**Decision:** `App\Support\Gst\GstCalculator` is authoritative; `resources/js/Pos/gst.js` re-implements it with BigInt paise for the live preview. Checkout sends `expected_total` and the server refuses a mismatch. **Consequences:** instant, exact totals on screen; any change to tax logic must be made in both files (a fuzz comparison script lives in the dev notes).

### D-024 · GST invoice number format — 2026-11-17 (resolves open question)
**Decision:** `{prefix}{branch code}/{FY like 2627}/{sequence}`, padded to fill 16 characters, e.g. `BR1/2627/0000001`; credit notes `CN{branch}/…`. Invoice prefix optional (≤4 chars), branch code ≤6 chars; generation refuses numbers over 16 characters.

### D-025 · Price-based GST decided per piece after discounts — 2026-11-17
**Decision:** for inclusive prices the lower rate is used to back out the per-piece taxable value; if that exceeds the limit the higher rate applies. Bill discounts are allocated to lines first. **Consequences:** discounted premium items can drop to 5%; behaviour in the ₹2,625–₹2,950 inclusive band follows the "lower-rate test" (documented in GST rules).

### D-026 · Exchange credit as a payment method — 2026-11-17
**Decision:** a return with refund method `exchange` creates a credit note whose value is used once, in full, as a payment on a bill (any excess is given back as change). Works for walk-in customers, unlike store credit on account.

### D-027 · Credit notes round cumulatively — 2026-11-17
**Decision:** each credit note's total = round(all returned line totals for the invoice so far) − totals already refunded. Prevents ₹1 over-refunds when pieces are returned one at a time.

### D-028 · No native `<dialog>` for modals — 2026-11-17
**Context:** after a modal `<dialog>` closed, Chrome kept typed input away from the programmatically focused POS search box until a mouse click — barcode scans were lost. **Decision:** the shared `Modal` component is a teleported fixed overlay; the POS also refocuses the search box after printing and on window focus.

### D-029 · Exchange credit is a voucher (supersedes the ledger part of D-026) — 2026-11-18
**Context:** a named customer paying with a credit note from someone else's return (e.g. a walk-in's) ended up owing that amount. **Decision:** a return refunded as *exchange* posts nothing to any account; spending the voucher counts like money for the spender (credit in their ledger), and cancelling that bill reverses it and re-validates the voucher.

### D-030 · Payments allocate oldest-first; ledger stays the truth — 2026-11-18
**Decision:** customer/supplier payments are allocated to unpaid bills oldest first (a specific bill first when paid from it); the remainder is "on account" (settles opening balances or is an advance). Bills keep a `due_amount` for display; balances always come from the contact ledger.

### D-031 · Cancel only in the open shift — 2026-11-18
**Decision:** a bill can be cancelled only while its register shift is open and nothing was returned or paid against it later. Afterwards use a return/credit note. **Consequences:** closed Z-reports never change.

### D-032 · Purchases excluding GST, landed cost — 2026-11-18
**Decision:** supplier rates are entered excluding GST; GST (input credit) never enters stock cost. Freight/other charges are shared over lines by taxable value into `landed_unit_cost`, which is the cost used for the weighted average. GST on freight is not modelled.

### D-033 · Quotations use list price + discounts — 2026-11-18
**Decision:** quote lines take the customer's list price (retail/wholesale) and discounts only, so converting to a POS bill needs only the normal discount permission and the POS re-prices at current rates.

### D-034 · Reports count documents on their own date — 2026-11-15
**Decision:** a bill counts on its bill date and a return on its credit-note date; "net" = sold − returned within the period. Same rule as GST returns, so the sales reports, dashboard and GSTR-1/3B agree. **Consequence:** a period can show negative net sales for an item returned from an earlier period.

### D-035 · Profit from the cost snapshot on the bill line — 2026-11-15
**Decision:** cost of goods sold = quantity × `sale_items.unit_cost` (weighted-average cost when sold), excluding GST. Restocked returns give the cost back; damaged returns don't. Later purchase prices never change past profit.

### D-036 · GST reports by registration, ITC only from registered suppliers — 2026-11-15
**Decision:** GST reports select a GSTIN (branch GSTIN, else business GSTIN) and include the user's branches under it. ITC in 3B comes only from bills of suppliers with a GSTIN, in the month goods are received; debit notes reduce it. B2C (large) threshold ₹1,00,000. The 3B "tax payable" is indicative (no statutory set-off order).

### D-037 · One report framework for screen and exports — 2026-11-15
**Decision:** reports return typed `Section`s (columns, rows, totals); the same sections render on screen and to Excel (openspout, one sheet per section), CSV (UTF-8 BOM) and PDF (dompdf, "Rs." because of fonts). Reports are aggregated in SQL and then merged in PHP; screen tables are capped at 1,000 rows, exports are complete.

### D-038 · Alerts are synchronous database notifications — 2026-11-15
**Decision:** alerts use Laravel's database channel (+ optional mail) sent immediately, no queue worker, so a small shop needs nothing but cron. Recipients come from permissions + branch assignment + personal preferences; a de-duplication key keeps one unread alert per subject. Alert code runs inside `rescue()` and can never fail a sale. **Trade-off:** email (if enabled) is sent during the request; switch `AlertNotification` to `ShouldQueue` if mail is slow.

### D-039 · Low stock alerts on crossing, not on every sale — 2026-11-15
**Decision:** an alert fires only when a stock decrease takes an item from above to at/below its reorder level, and not again while an unread alert for that item/branch exists. Out-of-stock with reorder level 0 counts.

### D-040 · Expenses paid from a drawer lock with the shift — 2026-11-15
**Decision:** cash expenses can come out of an open register drawer (they reduce expected cash, like cash-out); once that shift is closed the expense can't be edited or deleted, so Z-reports never change. GST on expenses is recorded for information and excluded from profit, but not claimed as ITC by the system.

### D-041 · Backups as gzip SQL dumps, restore from the CLI — 2026-11-15
**Decision:** `mysqldump --single-transaction` (no locks during billing), password via `MYSQL_PWD`, files only under `storage/app/backups` with a strict file-name pattern (no path traversal). Restoring replaces all data, so there is no restore button — it is a documented server command.

### D-042 · Loyalty points are a payment method — 2026-11-16
**Decision:** redeeming points is a tender (`points`) worth points × point value, not a discount, so GST, the bill total and the JS/PHP calculators are untouched; the customer's account is credited like money (as with exchange vouchers). Points are earned on the bill total minus the part paid with points. Points used are not restored on a return (refund by the chosen method); a cancellation restores them. **Trade-off:** tax is charged on the full value; a discount treatment would lower GST but change the calculators.

### D-043 · Points ledger with FIFO batches — 2026-11-16
**Decision:** append-only `loyalty_transactions`; each + row keeps `remaining`, so spending uses the oldest batch first and expiry removes exactly what is left of each batch. Balance = SUM(points).

### D-044 · Dark mode by variable palettes — 2026-11-16
**Decision:** instead of adding `dark:` classes to every page, the used Tailwind palettes are CSS variables with separate background (`--c-*`) and text (`--t-*`) maps, flipped under `.dark`. One change themes every existing and future page; coloured buttons keep white text. New colours must go through these palettes.

### D-045 · List filters remembered per session, forms guarded — 2026-11-16
**Decision:** filters live in the URL (shareable) and are also saved in sessionStorage per list; opening a list without a query restores them. Forms with unsaved changes ask before navigating away.

### D-046 · Real-time alerts with Reverb, polling as fallback — 2026-11-16
**Decision:** Laravel Reverb (first-party, self-hosted, free) pushes alerts to private per-user channels. Pushes run after the HTTP response with short timeouts and pause for 60 s when the server is unreachable; the database notification is always stored and the bell keeps polling, so the app never depends on the WebSocket server. **Consequence:** one more long-running process in production (`reverb:start`).

### D-047 · POS sounds are synthesized and per counter — 2026-11-16
**Decision:** Web Audio tones instead of audio files (no downloads, work offline); the on/off choice is stored per browser so each counter decides, with the business setting as the default.

### D-048 · Installer instead of seeding a live shop — 2026-11-16
**Decision:** live shops are created with `php artisan pos:install` (masters + first branch + Admin) and upgraded with `ProductionSeeder`; `DatabaseSeeder` is demo-only and refuses to run in production, so demo users with known passwords can never reach a live system.

### D-049 · SMTP settings in the app, encrypted — 2026-11-16
**Decision:** shop owners configure email in *Administration → Email* instead of editing `.env`; the SMTP password is encrypted with `APP_KEY` and applied at runtime (`MailSettings::apply()` on boot). `.env` `MAIL_*` stays as a fallback for hosts that prefer it. Welcome links use a separate password broker (`invites`, 3 days) on the same token table so the normal reset link can stay short (60 min). The forgot-password answer is identical for every address.

### Open questions
