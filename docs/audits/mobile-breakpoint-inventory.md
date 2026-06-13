# Mobile Breakpoint Inventory — Phase 2A

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** Breakpoint definitions and responsive logic under `web/themes/custom` and related module CSS. Audit only — no code changes.

**Style guide baseline (Phase 2A brief):** 390px mobile-first target.  
**Repository note:** No `390px` token found in theme SCSS/CSS grep under `web/themes/custom`. Evidence not found for an enforced 390px breakpoint in code.

---

## Summary

| Classification | Count (approx.) | Notes |
|----------------|-----------------|-------|
| **Canonical** (token + mixin) | 2 systems | Public `mel-break`; vendor `respond-to` / `respond-down` |
| **Duplicate** | 2 sm scales | Public `sm: 480px` vs vendor `sm: 640px` |
| **Conflicting** | Multiple hardcoded values | 479, 600, 767, 900, 959px used alongside tokens |
| **Unknown** | Drupal `breakpoints.yml` | No `*.breakpoints.yml` files in repository |

**Aggregate (SCSS under `web/themes/custom`):** ~470 `@media` occurrences across theme SCSS (rg count, 2026-06-13).

---

## 1. Drupal theme breakpoints.yml

| Location | Breakpoint | Purpose | Status |
|----------|------------|---------|--------|
| `web/themes/custom/**/*.breakpoints.yml` | — | Drupal Responsive Image / Picture mapping | **Evidence not found** — glob returned 0 files |

---

## 2. Public theme — `myeventlane_theme`

| Location | Breakpoint | Purpose | Status |
|----------|------------|---------|--------|
| `src/scss/tokens/_breakpoints.scss` | `xs: 0` | Mobile-first base | **Canonical** (public token map) |
| same | `sm: 480px` | Small phones / large phones | **Canonical** |
| same | `md: 768px` | Tablet | **Canonical** |
| same | `lg: 1024px` | Desktop | **Canonical** |
| same | `xl: 1280px` | Wide desktop | **Canonical** |
| same | `@mixin mel-break($size, min\|max)` | Standard media wrapper | **Canonical** |
| `src/scss/layout/_container.scss` | `mel-break(md)`, `mel-break(lg)` | Container padding | **Canonical** |
| `src/scss/layout/_header-layout.scss` | `mel-break(md)` | Header layout | **Canonical** |
| `src/scss/auth-pages.scss` | `$mel-auth-break-mobile: 900px` | Auth layout stack | **Conflicting** (not in token map) |
| `src/scss/components/_site-header.scss` | `width <= 900px` (×4) | Mobile nav / header | **Conflicting** |
| `src/scss/base/_global.scss` | `width >= 900px` | Global layout gate | **Conflicting** |
| `src/scss/components/_event-studio.scss` | `600px`, `768px` | Studio layout collapse | **Conflicting** |
| `src/scss/components/_event-gallery.scss` | `900px`, `767px`, `899px` | Gallery grid | **Conflicting** |
| `src/scss/components/_tables.scss` | `width <= 767px` | Table scroll/stack | **Conflicting** |
| `src/scss/components/_vendor-events.scss` | `1240px`, `1440px` | Vendor workspace grid | **Conflicting** (desktop-only) |
| `src/scss/components/_mel-attendee-operations.scss` | `640px`, `768px` hardcoded | Attendee ops layout | **Conflicting** |
| `myeventlane_radix/src/scss/components/_event-card.scss` | `700px`, `1100px` | Radix event card grid | **Conflicting** (legacy radix sub-theme) |

---

## 3. Vendor theme — `myeventlane_vendor_theme`

| Location | Breakpoint | Purpose | Status |
|----------|------------|---------|--------|
| `src/scss/tokens/_breakpoints.scss` | `sm: 640px` | Vendor sm | **Duplicate** (≠ public 480px) |
| same | `md: 768px` | Tablet | **Canonical** (matches public md) |
| same | `lg: 1024px` | Desktop | **Canonical** |
| same | `xl: 1280px` | Wide | **Canonical** |
| same | `2xl: 1536px` | Extra wide | **Unknown** (no public equivalent) |
| same | `@mixin respond-to` / `respond-down` | Vendor media wrappers | **Duplicate** (parallel API to `mel-break`) |
| same | `@mixin container-query` | Future container queries | **Unknown** (usage not audited in depth) |
| `src/scss/components/_kpi-cards.scss` | `max-width: 479px` | KPI stack | **Conflicting** |
| `src/scss/components/_event-table.scss` | `768px` | Table responsive | **Conflicting** (uses px not mixin) |
| `src/scss/components/_insights.scss` | `640px` | Insights grid | **Duplicate** (aligns with vendor sm) |
| `src/scss/components/_mel-builder.scss` | 21 `@media` blocks | Event Studio builder layout | **Conflicting** (high local breakpoint density) |
| `src/scss/workspace.scss` | 2 `@media` | Workspace shell | **Conflicting** |

---

## 4. Module CSS (selected — overlaps theme)

| Location | Breakpoint | Purpose | Status |
|----------|------------|---------|--------|
| `myeventlane_event_studio/css/mel-event-studio-shell.css` | 23 `@media` rules | Event Studio shell grid/sidebar collapse | **Conflicting** (module-owned, not token-linked) |
| `myeventlane_event_studio/css/mel-event-studio.css` | `640px`, `767px`, `959px` | Studio forms / touch targets | **Conflicting** |
| `myeventlane_event/css/event-builder-preview.css` | `600px`, `768px`, `959px`, `960px` | Booking preview | **Conflicting** |
| `myeventlane_commerce/css/mel-operational-checkout.css` | `48rem` (768px) | Checkout columns | **Canonical-ish** (rem-based) |
| `myeventlane_notifications/css/mel-notifications-ui.css` | `600px` | Notification drawer | **Conflicting** |
| `myeventlane_rsvp/css/rsvp-thankyou.css` | `767px` | Confirmation card | **Conflicting** |

---

## 5. JavaScript viewport logic

| Location | Breakpoint / logic | Purpose | Status |
|----------|-------------------|---------|--------|
| `myeventlane_theme/src/js/header.js` | `matchMedia('(min-width: 768px)')` | Close mobile nav on desktop resize | **Canonical** (matches md) |
| same | `.mel-nav-mobile-wrapper`, overlay | Mobile navigation shell | **Active** |
| `myeventlane_theme/js/footer-accordion.js` | Mobile footer accordion (no fixed px in grep header) | Public footer | **Active** |
| `myeventlane_theme/js/mel-booking-summary.js` | `[data-mel-mobile-booking-bar]` | Mobile booking sticky bar | **Active** |
| `myeventlane_theme/js/mel-checkout.js` | `.mel-buyer-mobile` field hooks | Checkout mobile fields | **Active** |
| `myeventlane_theme/js/event-full-trust-rotator.js` | `prefers-reduced-motion` only | A11y, not layout | N/A |

---

## 6. Classification matrix

| System | Owner | sm | md | lg | Status |
|--------|-------|----|----|-----|--------|
| `$mel-breakpoints` + `mel-break()` | `myeventlane_theme` | 480px | 768px | 1024px | **Canonical candidate (public)** |
| `$breakpoint-*` + `respond-to()` | `myeventlane_vendor_theme` | 640px | 768px | 1024px | **Duplicate / conflicting sm** |
| Hardcoded ad-hoc | Both themes + modules | 479–959px range | — | — | **Conflicting** |
| Drupal breakpoints.yml | — | — | — | — | **Evidence not found** |

---

## 7. Phase 2A conclusions

1. **Two parallel breakpoint systems** exist (public vs vendor) with different `sm` values (480 vs 640).
2. **Module CSS** (especially `mel-event-studio-shell.css`) defines its own responsive rules independent of theme tokens.
3. **390px style-guide baseline** is not represented in repository tokens; closest hardcoded mobile gates are 479px and 480px.
4. **No Drupal `breakpoints.yml`** — responsive image breakpoints may rely on core/contrib defaults; not confirmed in this audit.

**Recommended Phase 2B entry:** Unify breakpoint tokens before component/CSS consolidation.
