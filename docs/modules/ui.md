# UI & UX conventions

**Status:** ✅ Phase 7 (2026-11-16)

## App shell
- **Sidebar** collapses to icons on desktop (remembered per device, `pos-sidebar-collapsed`); a drawer on phones/tablets. Logo (Settings → Business → Logo) replaces the letter badge.
- **Search / command palette** — `Ctrl K` / `⌘K` or the top-bar search: pages, quick actions (new bill, add expense, new customer, new product, receive goods, new PO, new quotation, switch theme) and live results for products, customers, suppliers, bills, purchases and quotations — only what the user may open (`GET /search`).
- **Theme** — Light / Dark / System in the account menu (per device, `pos-theme`; applied before the page paints).

## Dark mode — how it works
Tailwind palettes `gray, indigo, red, amber, green, pink` are CSS variables in `resources/css/theme.css`: `--c-*` for backgrounds/borders/rings, `--t-*` for text, `--surface` for `bg-white`. Tailwind 4 maps them with `@theme inline` — `--color-*` (bg, border, ring…) → `--c-*`, `--text-color-*` (text only) → `--t-*`, `--background-color-white` → `--surface`. `.dark` on `<html>` swaps them, so **every page gets dark mode without `dark:` classes**. Rules for new code:
- Use those palettes (or add a palette to `PALETTES`); don't hard-code hex colours in classes. Charts take colours from `useTheme().isDark`.
- Print views (`resources/views/print`, PDFs) don't use Tailwind and stay black-on-white.

## Lists
- `DataTable` turns each row into a **card on phones** (first column = title, others label/value, `actions` top-right; `mobile: false` hides a column on cards).
- `useListFilters(routeName, initial)` keeps filters in the URL and **remembers them for the session** — coming back from the menu restores the last filters.
- `DateRangeFilter` — preset menu (today, this week, last 7 days, this/last month, quarter, this/last financial year, custom) used by Sales, Purchases, Payments and Expenses; reports use the same presets (`utils/dateRanges.js`).
- Report tables keep the header and totals row visible while scrolling long results.

## Forms
- `useUnsavedGuard(() => dirty)` asks before leaving a form with unsaved changes (links, back button, closing the tab). Used on product, purchase, purchase order, quotation, contact, user, stock adjustment and transfer forms.

## Camera barcode scanning
- `<ScanButton mode="single|continuous" title="…" :feedback="{ text, ok }" @detected="code => …" />` next to any barcode input; it opens `CameraScanner.vue` (full-screen overlay, torch, switch camera, Done). `VariantPicker` has it built in (`scan-mode="continuous"` for bulk screens).
- `utils/barcodeScanner.js`: native `BarcodeDetector` when the browser has it (Android Chrome/Edge), otherwise the `barcode-detector` ponyfill (ZXing WebAssembly, ~950 KB) **lazy-loaded and served from our own build** — iPhone/iPad Safari, Firefox, desktop Linux. Add `?scanner=wasm` to a URL to force the fallback for testing.
- A camera read only ever accepts an **exact** barcode/SKU match. The same code is ignored while it stays in view; the camera stops on close, page change and when the tab is hidden.
- Requires a secure origin (HTTPS or localhost); the overlay explains blocked permission / no camera / no HTTPS. The overlay is always dark (fixed colours, independent of the theme).

## Sounds
`utils/sounds.js` plays short synthesized tones per area, each with its own per-device switch: `pos` (speaker button on the POS) and `alerts` (Notifications → On this device). Toggle switches use a literal white knob (`bg-[#fff]`) so they stay visible in dark mode.

## Dashboard
Quick actions, KPIs and chart render immediately; top items, outstanding money, drawers, recent bills and low stock load right after (Inertia deferred prop) with placeholders; figures refresh every 2 minutes while visible; the period/branch choice is remembered for the session.

## POS on tablets and phones
Items / Bill tabs with an item-count badge, a sticky total + **Pay** bar, quick picks (*Recently added* on this counter and *Popular here* — best sellers of the last 30 days), larger touch targets, a keyboard-shortcuts panel (`?` button) and a clearer sale-complete screen (how it was paid, points).
