# Mobile CSS Ownership Review

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-14  
**Input:** `css-ownership-map.md`, `mobile-breakpoint-inventory.md`, `component-system-inventory.md`  
**Status:** Audit only — document conflicts; do not remove code

---

## Branch verification

| Check | Expected | Actual |
|-------|----------|--------|
| Branch | `feature/mobile-foundation` | `feature/brand-rollout-phase-1a-discovery-copy` |
| Working tree | Clean | Dirty (unrelated Twig edits) |

---

## Ownership model (confirmed)

| Owner | Build output | Scope | Entry |
|-------|--------------|-------|-------|
| `myeventlane_theme` | Vite → `dist/main.css` | Public site, shared `mel-*` system | `src/scss/main.scss` |
| `myeventlane_vendor_theme` | Vite → `dist/main.css`, `dist/workspace.css` | Vendor console under `.mel-vendor` | `src/scss/main.scss`, `workspace.scss` |
| Custom modules | Static CSS libraries | Per-route attachments | `*.libraries.yml` |
| `myeventlane_radix` | Sub-theme SCSS | Legacy overlap | Partial duplication with public theme |

Event Studio shell header in `mel-event-studio-shell.css` states: **module owns layout grid**; theme may layer colour/typography only.

---

## Canonical component owners (recommended targets)

Per `canonical-design-system.md` and repository structure:

| Component | Canonical owner | Responsibility |
|-----------|-----------------|----------------|
| **Breakpoints / media** | `myeventlane_theme/src/scss/tokens/_breakpoints.scss` + `@mixin mel-break` | Cross-surface token map (once unified) |
| **Buttons `.mel-btn`** | `myeventlane_theme/src/scss/components/_buttons.scss` | Global variants, touch sizes |
| **Form actions** | `_mel-buttons.scss` (`.mel-form-system` scope) | Drupal submit mapping only |
| **Cards `.mel-card`** | `_cards.scss` + `_mel-cards.scss` | Base + governed modifiers |
| **Forms** | `_mel-forms.scss`, `_mel-form-states.scss`, `base/_forms.scss` | Governed form shell |
| **Tables** | `_mel-tables.scss` + legacy `_tables.scss` | Responsive stack/scroll |
| **Public navigation** | `_site-header.scss`, `layout/_navigation.scss`, `header.js` | Mobile overlay nav |
| **Empty states** | `_mel-empty-states.scss` | Single BEM block target |
| **Modals / overlays** | `_mel-modals.scss`, `_mel-overlays.scss` | Governed surfaces |
| **Checkout layout** | `_checkout.scss` | Public commerce flow |
| **Event page layout** | `_event-full.scss`, `_booking-page.scss`, `_event-mobile-cta.scss` | Public event + book |
| **Event Studio layout** | `myeventlane_event_studio/css/mel-event-studio-shell.css` | Grid, sidebar, topbar (module) |
| **Event Studio nav polish** | `mel-event-studio-nav.css` | Section list styling |
| **Vendor shell nav** | `myeventlane_vendor_theme.theme` + `layout/_navigation.scss` | PHP + SCSS split |

---

## Duplicate ownership

| Component | Locations | Conflict nature |
|-----------|-----------|-----------------|
| **`.mel-btn`** | Public `_buttons.scss`; vendor `_buttons.scss`; `mel-event-studio.css`; RSVP/checkout module CSS | Gradient vs flat; vendor redefines base under `.mel-vendor` |
| **`.mel-card`** | Public `_cards.scss` + `_mel-cards.scss`; vendor `_cards.scss`; `_event-card.scss`; module teasers | Different token sources (`$mel-color-surface` vs `$ml-vendor-card`) |
| **Forms** | `_mel-forms.scss`; vendor `_forms.scss`, `_event-form.scss` (94× `!important`) | Drupal markup override wars |
| **Tables** | `_mel-tables.scss`, `_tables.scss`; vendor `_event-table.scss`; commerce module CSS | Multiple 767/768px stack rules |
| **Tabs** | Vendor `_tabs.scss`; `simple-tabs.css`; `_wizard.scss` | Three styling sources |
| **Empty states** | `_mel-empty-states.scss`; vendor `_empty-states.scss`; `_mel-browse.scss` `.mel-empty` | Class fragmentation (`.mel-empty` vs `.mel-empty-state`) |
| **Toasts / alerts** | `_toasts.scss`; vendor `_vendor-alert.scss`, `_notifications.scss` | Parallel patterns |
| **Category filters** | `_category-pills.scss`; `_mel-events-filters.scss`; hero `.mel-chip` vs `.mel-category-chip` | Naming fragmentation |
| **KPI cards** | Vendor `_kpi-cards.scss`; `_mel-metrics.scss` | Duplicate metric card patterns |
| **Breakpoint systems** | Public `sm: 480px`; vendor `sm: 640px`; parallel mixins `mel-break` vs `respond-to` | **Structural duplication** |
| **Vendor compiles public SCSS** | Vendor `main.scss` lines 90–103 `@mel-theme/components/*` | Second copy of public rules inside `.mel-vendor` **plus** local `_buttons.scss`, `_cards.scss` |

---

## Conflicting ownership (highest risk)

| Surface | Parties | Evidence | Mobile impact |
|---------|---------|----------|---------------|
| **Event Studio shell** | `mel-event-studio-shell.css` (~5.4k lines, 23 `@media`); theme `_event-studio.scss`; vendor `_mel-builder.scss` (21 `@media`, 30× `!important`) | `grid-template-columns: 280px 1fr` at line 19; onboarding `200px 280px 1fr` | Horizontal scroll / unusable sidebar at 390px |
| **Event Studio buttons/forms** | `mel-event-studio.css` vs theme `_buttons.scss` / `_mel-forms.scss` | Module redefines `.mel-btn` base | Inconsistent touch targets |
| **Vendor event forms** | `_event-form.scss` (94× `!important`) vs module + Drupal core form CSS | Highest `!important` count in themes | Unpredictable mobile field layout |
| **Public header** | `_site-header.scss` uses **900px**; `header.js` uses **768px** matchMedia | 4+ `@media (width <= 900px)` in `_site-header.scss` | Nav drawer/state mismatch between 768–900px |
| **Event gallery** | `_event-gallery.scss` — 900px, 767px, 899px | Hardcoded cuts alongside tokens | Layout jumps on event detail |
| **Checkout** | `_checkout.scss` (20 `@media`); `mel-operational-checkout.css`; `mel-checkout.js` | Module + theme both style checkout | Multi-column bleed on narrow viewports |
| **Live ops / dashboard** | `_live-operations.scss` (31× `!important`); vendor dashboard pages | Grid density | KPI overflow on mobile |
| **Cart** | `commerce/_commerce.scss` (active) vs `components/_cart.scss` (**not** in `main.scss`) | Legacy duplicate file | Dead-code confusion risk |

---

## Potential dead code (flag only — do not remove)

| Item | Evidence | Status |
|------|----------|--------|
| **`components/_cart.scss`** | Not `@use`'d in public `main.scss`; cart rules in `commerce/_commerce.scss` per cart audit | **Likely dead / legacy duplicate** |
| **Vendor `vendor-studio-editor` import** | `myeventlane_vendor_theme/src/scss/main.scss` line 92 | Staff shell retired Phase 1E — **orphan import risk** |
| **Vendor `studio-inspector` import** | Same file line 93 | **Orphan import risk** |
| **`myeventlane_core/css/studio-layout.css`** | Listed in css-ownership-map; relationship to Event Studio shell **not fully traced** | **Unknown** |
| **`myeventlane_radix` component SCSS** | `_event-card.scss` with 700px/1100px breakpoints | **Legacy** — may still load if radix active |
| **`myeventlane_vendor/css/manage-event.css`** | Marked legacy in ownership map | Legacy manage-event overrides |
| **Parallel sm token (640 vs 480)** | Not dead but **duplicate system** — vendor rules at 640px may never align with public 480px components on shared surfaces | Consolidation candidate |

---

## `!important` hotspots (ownership fight markers)

| File | Approx. count | Interpretation |
|------|---------------|----------------|
| `myeventlane_vendor_theme/.../_event-form.scss` | 94 | Form layout vs Drupal/Gin markup |
| `myeventlane_vendor_theme/.../_settings.scss` | 34 | Settings page overrides |
| `myeventlane_theme/.../_live-operations.scss` | 31 | Dashboard live ops grid |
| `myeventlane_vendor_theme/.../_mel-builder.scss` | 30 | Event Studio builder vs module shell |
| `mel-event-studio-shell.css` | Present (e.g. `.mel-builder` grid) | Module layout enforcement |

**Interpretation:** Clusters mark boundaries where mobile consolidation will fail if attempted via overrides alone. Markup/ownership fixes required (`canonical-design-system.md` §8).

---

## Module CSS overlap (selected)

70+ module CSS files under `web/modules/custom/*/css/` contain `.mel-` classes. Highest mobile overlap risk:

| Module CSS | Role | Overlap |
|------------|------|---------|
| `mel-event-studio-shell.css` | Studio grid, sidebar, topbar | **High** — layout owner |
| `mel-event-studio.css` | Forms, buttons, touch targets | **High** — duplicates theme buttons |
| `event-builder-preview.css` | Public booking preview | **Medium** |
| `mel-operational-checkout.css` | Checkout columns | **Medium** |
| `analytics.css` | Vendor charts/tables | **Medium** |
| `rsvp-thankyou.css` | Confirmation cards | **Low** |

---

## Breakpoint fragmentation (CSS-level mobile blocker)

From `mobile-breakpoint-inventory.md`:

| Classification | Evidence |
|----------------|----------|
| **Canonical (public)** | `mel-break`; sm 480, md 768, lg 1024, xl 1280 |
| **Duplicate (vendor)** | `respond-to`; sm **640** (≠ public 480) |
| **Conflicting hardcoded** | 479, 600, 767, 900, 959px across theme + module CSS |
| **390px style guide** | **Evidence not found** in theme SCSS |
| **Drupal breakpoints.yml** | **Evidence not found** |

**Mobile CSS conclusion:** Implementation must start with **token unification** before route-level layout work, or each surface will continue using incompatible cut points.

---

## Consolidation readiness by component

| Component | Ready to implement mobile fixes? | Blocker |
|-----------|----------------------------------|---------|
| Public header | **Partial** | 900px vs 768px conflict |
| Buttons | **No** | Triple definition |
| Cards | **No** | Dual token systems |
| Forms (vendor) | **No** | 94× `!important` |
| Event Studio layout | **No** | Module owns grid; theme fights |
| Checkout SCSS | **Partial** | Visual-only scope OK; module CSS parallel |
| Tables (vendor orders) | **No** | No single stack pattern |
| Empty states | **Partial** | Class name fragmentation |

---

## Audit actions (documentation only)

1. Treat `mel-event-studio-shell.css` as **layout source of truth** for Studio — theme must not re-grid without module coordination.
2. Treat public `_buttons.scss` + `_mel-buttons.scss` as **target canonical** for buttons — vendor base redeclaration is tech debt.
3. Flag orphan vendor imports (`vendor-studio-editor`, `studio-inspector`) for Phase 2B verification before removal.
4. Do not delete `_cart.scss` until grep confirms zero references and build output parity.

---

**Mobile CSS review complete. No files removed or edited.**
