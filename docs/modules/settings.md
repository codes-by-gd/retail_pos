# Settings

**Status:** ✅ core done (Phase 0) · remaining items ⏳ in later phases
**Purpose:** Business identity, branches and the defaults every other module reads.

## Features
- ✅ Business profile: name, legal name, GSTIN (checksum-validated), state, address, phone, email
- ✅ Financial year start (default 1 April)
- ✅ Invoice: prefix, receipt footer, terms & conditions
- ✅ Tax: prices include GST (default), round-off bill total
- ✅ POS: allow negative stock, beep on scan, default receipt size (58 mm / 80 mm / A4)
- ✅ Branches: create/edit/delete, code, state, optional own GSTIN, active flag
- ✅ Current-branch switcher in the top bar
- ⏳ Logo upload (P3, for receipts)
- ⏳ Cash registers per branch (P3)
- ⏳ Payment methods enable/disable & custom methods (P3)
- ⏳ Receipt / label template designer (P1, P3)
- ⏳ SMTP / SMS settings (P6), database backup download (P6)

## Screens
| Screen | Route | Page |
|---|---|---|
| Business settings | `settings.business.edit` | `Pages/Settings/Business.vue` |
| Branches | `settings.branches.index` | `Pages/Settings/Branches/Index.vue` (modal form) |

## Business rules
- A branch's GSTIN, if given, must start with its state code. Without one, the business GSTIN is used.
- There must always be at least one active branch; the last branch cannot be deleted.
- Branch `code` is unique, uppercase letters/digits, and appears in document numbers.
- Settings are cached; `SettingsService::set()` clears the cache automatically.

## Permissions
`settings.manage` for everything on this page.

## Code
`app/Modules/Settings/` — `Models/{Setting,Branch}`, `Services/SettingsService`, `Http/Controllers/{BusinessSettings,Branch}Controller`, `Http/Requests/*`. Helpers: `settings()`, `current_branch()`.
