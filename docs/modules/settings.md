# Settings

**Status:** ✅ done (Phases 0–6) · template designer ⏳ later
**Purpose:** Business identity, branches and the defaults every other module reads.

## Features
- ✅ Business profile: name, legal name, GSTIN (checksum-validated), state, address, phone, email
- ✅ Financial year start (default 1 April)
- ✅ Invoice: prefix, receipt footer, terms & conditions
- ✅ Tax: prices include GST (default), round-off bill total
- ✅ POS: allow negative stock, beep on scan, default receipt size (58 mm / 80 mm / A4)
- ✅ Branches: create/edit/delete, code, state, optional own GSTIN, active flag
- ✅ Current-branch switcher in the top bar
- ✅ Logo upload (Phase 7): receipts (grayscale on thermal), A4 invoices, all prints, PDF reports, app header and login page
- ✅ Loyalty rules (Phase 7) — see [Loyalty](loyalty.md)
- ✅ Cash registers per branch (Phase 3)
- ✅ Card / UPI on or off at the POS (Phase 3) · ⏳ custom payment methods later
- ⏳ Receipt / label template designer (P1, P3)
- ✅ Alerts: large-discount % and cash-difference limits, email on/off (Phase 6; SMTP itself in `.env`) · ⏳ SMS later
- ✅ **Email (SMTP)** (*Administration → Email*, `settings.mail.edit`): server, port, encryption (STARTTLS / SSL/TLS / none), username, password (stored encrypted with `APP_KEY`, never sent back to the page), from address and name; quick-fill for Gmail, Microsoft 365, Zoho (India), Amazon SES (Mumbai); **Send test email** shows the SMTP error if it fails. When switched off, `.env` `MAIL_*` is used. Applied at runtime by `MailSettings::apply()`
- ✅ Database backups: back up now, nightly with retention, download/delete (Phase 6, route `settings.backups.index`)

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
