# CSS Ownership Map — Phase 2A

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** CSS/SCSS ownership across themes and custom modules. Audit only.

**`!important` aggregate (themes/custom SCSS+CSS):** rg count across files — hotspots listed below (not exhaustive).

---

## Ownership model (repository-confirmed)

| Owner | Build | Scope | Primary entry |
|-------|-------|-------|---------------|
| `myeventlane_theme` | Vite → `dist/main.css` | Public site, shared `mel-*` system | `src/scss/main.scss` |
| `myeventlane_vendor_theme` | Vite → `dist/main.css`, `dist/workspace.css` | Vendor console under `.mel-vendor` | `src/scss/main.scss`, `workspace.scss` |
| Custom modules | Static CSS (+ some SCSS compile) | Feature libraries attached per route | `*.libraries.yml` |
| `myeventlane_radix` | Sub-theme SCSS | Legacy radix paths | Partial overlap with public theme |

Event Studio shell CSS documents explicit ownership split in `mel-event-studio-shell.css` header: **module owns layout grid**; theme may layer colour/typography only.

---

## Component ownership matrix

| Component | Owner | Duplicate owner | Notes |
|-----------|-------|-----------------|-------|
| **Buttons (`.mel-btn`)** | `myeventlane_theme/src/scss/components/_buttons.scss` | `myeventlane_vendor_theme/.../_buttons.scss`; `mel-event-studio.css`; RSVP/checkout module CSS | Vendor redefines base `.mel-btn` under `.mel-vendor`; gradient vs flat styles differ |
| **Buttons (form hierarchy)** | `_mel-buttons.scss` (`.mel-form-system`) | Module form action rules in `mel-event-studio.css` | Governed vs feature overrides |
| **Cards (`.mel-card`)** | `_cards.scss` + `_mel-cards.scss` | Vendor `_cards.scss`; `_event-card.scss`; module teasers | Different token sources (`$mel-color-surface` vs `$ml-vendor-card`) |
| **Forms** | `_mel-forms.scss`, `base/_forms.scss` | Vendor `_forms.scss`, `_event-form.scss` (94× `!important`) | Highest conflict risk |
| **Tables** | `_mel-tables.scss`, `_tables.scss` | Vendor `_event-table.scss`; commerce module tables | Mobile stack at 767/768px in multiple files |
| **Tabs** | Vendor `_tabs.scss` | `myeventlane_vendor/css/simple-tabs.css`; wizard tabs in `_wizard.scss` | Three tab styling sources |
| **Public navigation** | `_site-header.scss`, `layout/_navigation.scss` | `_mel-navigation.scss` | JS coupling in `header.js` |
| **Vendor shell navigation** | `myeventlane_vendor_theme.theme` (nav builder) | `layout/_navigation.scss`, sidebar Twig | PHP + SCSS split |
| **Event Studio layout** | `myeventlane_event_studio/css/mel-event-studio-shell.css` (~5.4k lines, 23 `@media`) | `_event-studio.scss`; vendor `_mel-builder.scss` (21 `@media`, 30× `!important`) | **Primary ownership conflict** |
| **Event Studio polish** | `mel-event-studio.css`, `mel-event-studio-nav.css` | Theme `_event-studio.scss` | Module + theme both style `.mel-event-studio` |
| **Checkout** | `_checkout.scss` (20 `@media`) | `mel-operational-checkout.css`; `mel-checkout.js` | Public commerce flow |
| **Booking / event page** | `_event-full.scss` (29 `@media`), `_booking-page.scss` | `event-builder-preview.css`; `event-node.css` | High responsive surface area |
| **Empty states** | `_mel-empty-states.scss` | Vendor `_empty-states.scss`; `_mel-browse.scss` `.mel-empty` | Class name fragmentation |
| **Modals / overlays** | `_mel-modals.scss`, `_mel-overlays.scss` | Vendor dialogs; notifications drawer CSS | |
| **Badges** | Vendor `_badges.scss` | `_sla-badge.scss`, governance state panels | |
| **Live ops / dashboard** | `_live-operations.scss` (31× `!important`) | Vendor dashboard pages, `_mel-builder.scss` | Density + grid conflicts |
| **Klaro consent** | `_klaro-consent.scss` (public + radix) | Loaded at vendor root in `main.scss` | Intentional cross-theme include |

---

## Module CSS with `.mel-*` classes (selected)

Modules ship standalone CSS that defines or overrides MEL classes (grep `\.mel-` under `web/modules/custom`):

| Module path | Role | Overlap risk |
|-------------|------|--------------|
| `myeventlane_event_studio/css/mel-event-studio-shell.css` | Studio grid, sidebar, topbar, sections | **High** — canonical layout owner |
| `myeventlane_event_studio/css/mel-event-studio.css` | Forms, buttons, touch targets | **High** — duplicates theme buttons |
| `myeventlane_event/css/event-builder-preview.css` | Public booking preview | **Medium** — event page |
| `myeventlane_commerce/css/mel-operational-checkout.css` | Checkout columns | **Medium** |
| `myeventlane_rsvp/css/rsvp-thankyou.css` | Confirmation cards + buttons | **Low** |
| `myeventlane_analytics/css/analytics.css` | Vendor analytics | **Medium** — tables/charts |
| `myeventlane_vendor/css/manage-event.css` | Legacy manage event comments/overrides | **Legacy** |
| `myeventlane_core/css/studio-layout.css` | Studio layout helper | **Unknown** — relationship to Event Studio shell not fully traced |

Full module CSS file list: 70+ files under `web/modules/custom/*/css/` (rg files_with_matches `\.mel-`).

---

## `!important` hotspots (themes/custom)

| File | Approx. count | Notes |
|------|---------------|-------|
| `myeventlane_vendor_theme/.../_event-form.scss` | 94 | Form layout fights Drupal/Gin markup |
| `myeventlane_vendor_theme/.../_settings.scss` | 34 | Settings page overrides |
| `myeventlane_theme/.../_live-operations.scss` | 31 | Dashboard live ops grid |
| `myeventlane_vendor_theme/.../_mel-builder.scss` | 30 | Event Studio builder layout |
| `myeventlane_event_studio/css/mel-event-studio-shell.css` | Present (e.g. `.mel-builder` grid) | Module layout enforcement |

**Interpretation:** `!important` clusters indicate **ownership fights** between module layout CSS, vendor SCSS, and Drupal form markup — not random debt.

---

## Vendor theme import of public SCSS

From `myeventlane_vendor_theme/src/scss/main.scss` (lines 90–103):

- `@mel-theme/components/event-studio`
- `@mel-theme/components/mel-components`, `mel-cards`, `mel-navigation`, `mel-empty-states`, `live-operations`, etc.

**Effect:** Vendor console compiles **second copies** of public `mel-*` rules inside `.mel-vendor`, while also loading local duplicates (`components/buttons`, `components/cards`). This is structural duplication, not just class reuse.

---

## Duplicated CSS categories

| Category | Evidence |
|----------|----------|
| **Dual token systems** | Public `tokens/` vs vendor `tokens/` (colors, spacing, breakpoints) |
| **Dual button/card base** | Parallel `_buttons.scss` / `_cards.scss` in both themes |
| **Module + theme Event Studio** | `mel-event-studio-shell.css` + `_event-studio.scss` + `_mel-builder.scss` |
| **Legacy radix** | `myeventlane_radix` component SCSS still in repo |
| **Orphan studio SCSS imports** | Vendor `main.scss` still `@include`s `@mel-theme/components/vendor-studio-editor` and `studio-inspector` (staff shell retired Phase 1E) — **dead import risk** |

---

## Phase 2A conclusions

1. **No single CSS owner** for `.mel-btn`, `.mel-card`, or Event Studio layout — three layers (public theme, vendor theme, module).
2. **Event Studio shell** is the largest consolidated CSS asset and should be treated as layout source of truth once breakpoints unify.
3. **`!important` density** marks boundaries where Phase 2B consolidation will be hardest (`_event-form.scss`, `_mel-builder.scss`, shell CSS).
4. **Phase 1E retirement** may leave unused SCSS imports in vendor `main.scss` — flag for Phase 2B cleanup (audit note only; not changed in 2A).
