# Breakpoint Unification Plan — Phase 2B

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Prior audit:** [mobile-breakpoint-inventory.md](./mobile-breakpoint-inventory.md) (Phase 2A)

---

## Summary

Phase 2B establishes a **single canonical token file** in the public theme. Both themes consume it. Legacy `mel-break()` behaviour is preserved via an alias map so existing public-theme responsive rules do not shift breakpoints in this phase.

---

## Current source locations (pre-2B)

| Location | Role | Status |
|----------|------|--------|
| `web/themes/custom/myeventlane_theme/src/scss/tokens/_breakpoints.scss` | Public tokens + `mel-break()` | Was canonical for public |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/tokens/_breakpoints.scss` | Vendor tokens + `respond-to()` / `respond-down()` | Duplicate system |
| `web/themes/custom/myeventlane_theme/src/scss/abstracts/_mixins.scss` | Consumes public breakpoints via `@use '../tokens/breakpoints'` | Consumer |
| Hardcoded `@media` in ~120 SCSS partials | Ad-hoc breakpoints (479–959px range) | Conflicting — deferred to Phase 2C |
| `web/modules/custom/*/css/*.css` | Module-owned responsive rules | Out of scope for token unification |
| `web/themes/custom/**/*.breakpoints.yml` | Drupal Responsive Image breakpoints | **Evidence not found** (0 files) |

---

## Current tokens (Phase 2A evidence)

### Public theme (`$mel-breakpoints` map)

| Key | Value |
|-----|-------|
| xs | 0 |
| sm | 480px |
| md | 768px |
| lg | 1024px |
| xl | 1280px |

### Vendor theme (`$breakpoint-*` variables)

| Variable | Value |
|----------|-------|
| sm | 640px |
| md | 768px |
| lg | 1024px |
| xl | 1280px |
| 2xl | 1536px |

**Conflict:** public `sm` = 480px vs vendor/style-guide `sm` = 640px.

---

## Current mixins

| Mixin | Owner (pre-2B) | Usage count (rg, 2026-06-13) |
|-------|----------------|------------------------------|
| `mel-break($size, min\|max)` | Public theme | ~305 call sites in `myeventlane_theme/src/scss` |
| `respond-to($breakpoint)` | Vendor theme | ~175 call sites combined with `respond-down()` in `myeventlane_vendor_theme/src/scss` |
| `respond-down($breakpoint)` | Vendor theme | (included above) |
| `container-query($min-width)` | Vendor theme only | 0 confirmed call sites in vendor SCSS grep |

---

## Usage counts (Phase 2A baseline)

| Metric | Count |
|--------|-------|
| `@media` under `web/themes/custom` | 479 |
| `!important` under `web/themes/custom` | ~300 |
| Drupal `breakpoints.yml` | 0 |
| Style-guide 390px token in code (pre-2B) | 0 |

---

## Canonical location (Phase 2B decision)

**Single source of truth:**

`web/themes/custom/myeventlane_theme/src/scss/tokens/_breakpoints.scss`

**Vendor consumption:**

`web/themes/custom/myeventlane_vendor_theme/src/scss/tokens/_breakpoints.scss` → `@forward` to public theme file.

**Vite alias (existing):** `@mel-theme` → `../myeventlane_theme/src/scss` in `myeventlane_vendor_theme/vite.config.js`.

---

## Breakpoint mapping

| Breakpoint | Current public (`mel-break`) | Current vendor (`respond-to`) | Canonical value (style guide) | Phase 2B compiled behaviour |
|------------|------------------------------|-------------------------------|-------------------------------|---------------------------|
| xs | 0 (map key) | — | **390px** (`$breakpoint-xs`) | `mel-break(xs)` still **0**; `respond-to(xs)` uses **390px** |
| sm | **480px** | **640px** | **640px** (`$breakpoint-sm`) | `mel-break(sm)` still **480px** via `$mel-breakpoint-sm-legacy`; `respond-to(sm)` uses **640px** |
| md | 768px | 768px | **768px** | Unchanged |
| lg | 1024px | 1024px | **1024px** | Unchanged |
| xl | 1280px | 1280px | **1280px** | Unchanged |
| 2xl | — | 1536px | **1536px** (`$breakpoint-2xl`) | Vendor-only; unchanged |

---

## Backward compatibility layer

| Mechanism | Purpose |
|-----------|---------|
| `$mel-breakpoint-sm-legacy: 480px` | Preserves public `mel-break(sm)` output |
| `$mel-breakpoints` map | Unchanged keys; sm points at legacy alias |
| `$breakpoint-sm: 640px` | Canonical token for vendor mixins and new work |
| Vendor `@forward` | Eliminates duplicate token file content |

---

## Out of scope (Phase 2C+)

- Replacing ~479 hardcoded `@media` rules
- Aligning public `mel-break(sm)` to 640px
- Module CSS breakpoint alignment (`mel-event-studio-shell.css`, etc.)
- Drupal `breakpoints.yml` for Responsive Image
- JS `matchMedia` alignment (e.g. `header.js` at 768px — already matches md)

---

## Phase 2B implementation checklist

- [x] Canonical tokens in public theme `_breakpoints.scss`
- [x] Vendor theme re-exports via `@forward`
- [x] All mixins consolidated in canonical file
- [x] Legacy sm alias documented
- [ ] Phase 2C: migrate `mel-break(sm)` call sites to canonical sm or explicit legacy alias
- [ ] Phase 2C: inventory and retire hardcoded 479/767/900/959px gates
