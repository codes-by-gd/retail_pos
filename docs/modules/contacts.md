# Contacts

**Status:** ✅ done (Phase 2, 2026-11-16) · loyalty points ⏳ later
**Purpose:** Customers and suppliers, their details, and how much money is owed either way.

## Key concepts
- **One `contacts` table** with `type` = `customer`, `supplier` or `both` (e.g. a tailor who buys fabric from us and does job work for us). "Both" contacts appear in both lists.
- **Code**: `CUS-00001` / `SUP-00001`, generated on create. The built-in counter customer is `WALK-IN`.
- **Phone is the counter lookup key**: normalised to digits (`+91 98765-43210` → `9876543210`, leading `0` removed), unique across all contacts, **required for customers**.
- **Account ledger** (`contact_ledger_entries`): append-only, like the stock ledger. **Balance = debit − credit**: positive = they owe us (**Dr**, receivable), negative = we owe them (**Cr**, payable).

## Features
- ✅ Customers and suppliers lists: search (name, phone digits, code, company, GSTIN), group, balance (owes us / we owe), status; **birthday / anniversary in the next 30 days** for offers
- ✅ Create/edit: name, type, mobile + alternate phone, email, company, GSTIN (validated, **fills the state**; state must match), address, city, PIN
- ✅ Customer details: group, credit limit (0 = no credit/udhaar sales), date of birth, anniversary
- ✅ Supplier details: payment terms (days)
- ✅ Opening balance (they owe us / we owe them) — the only ledger entry that can be edited
- ✅ Contact page: balance card, details, **account statement** with date range, brought-forward balance, running Dr/Cr balance, totals; print
- ✅ Customer groups: retail or wholesale price + member discount % (e.g. *Gold Members* 5%); groups in use can't be deleted
- ✅ Built-in **Walk-in Customer** (can be renamed; can't be deleted, deactivated or turned into a supplier)
- ✅ Delete only when the contact has no transactions (other than an opening balance); otherwise deactivate
- ✅ CSV import (create, or update by `code`; per-row errors) and export, per customers / suppliers
- ✅ JSON endpoints for the POS / purchases: search (exact phone match first) and quick-add customer
- ⏳ Loyalty points (earn & redeem), WhatsApp/SMS birthday greetings (later)
- ⏳ Sales/purchase history on the contact page (as Phases 3–4 create documents they post to the ledger)

## Screens
| Screen | Route | Page |
|---|---|---|
| Customers / Suppliers list | `customers.index`, `suppliers.index` | `Pages/Contacts/Index.vue` |
| New / edit | `customers.create/edit`, `suppliers.create/edit` | `Pages/Contacts/Form.vue` |
| Contact & statement | `customers.show`, `suppliers.show` | `Pages/Contacts/Show.vue` |
| Customer groups | `customer-groups.index` | `Pages/Contacts/Groups.vue` |
| Import | `customers.import`, `suppliers.import` | `Pages/Contacts/Import.vue` |
| Search (JSON) | `contacts.search?q=&kind=customer\|supplier` | used by POS (Phase 3) |
| Quick add (JSON) | `POST contacts.quick-store` {name, phone, gstin?} | used by POS (Phase 3) |

Customers and suppliers share `ContactController`; each route passes `kind` via `->defaults('kind', …)`.

## Business rules
- The **state** (from GSTIN or chosen) is the place of supply: same state as the branch → CGST + SGST, otherwise IGST.
- **Customer group** decides which variant price the POS uses (`selling_price` or `wholesale_price`) and an automatic bill discount.
- **Credit limit** is checked at billing (Phase 3): a credit sale may not push the balance above the limit.
- Ledger postings by later phases: sale → debit; sale return → credit; payment received → credit; purchase → credit; purchase return → debit; payment made → debit (see `LedgerEntryType`).
- Permissions follow the type: customer → `customers.*`, supplier → `suppliers.*`, both → both `.manage` permissions.

## Permissions
`customers.view`, `customers.manage`, `suppliers.view`, `suppliers.manage`. Search API: `customers.view` or `pos.access` (customers); `suppliers.view` or `purchases.manage` (suppliers). Quick-add: `customers.manage`.

## Code
`app/Modules/Contacts/`
- `Models/` Contact, CustomerGroup, ContactLedgerEntry · `Enums/LedgerEntryType`
- `Services/` ContactService (create/update/delete), **ContactLedgerService** (`record()`, `setOpeningBalance()`, `statement()`), ContactCsvService
- `Http/Controllers/` ContactController, CustomerGroupController, ContactSearchController, ContactImportController · `Http/Requests/ContactRequest`
- Seeders: `DefaultContactsSeeder` (walk-in + Regular/Wholesale groups), `DemoContactsSeeder`
