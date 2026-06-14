# !important Debt Register — Phase 2B

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** `web/themes/custom` SCSS/CSS only. Inventory only — no removals in Phase 2B.

**Baseline (Phase 2A):** ~300 `!important` declarations under `web/themes/custom`.

---

## Summary by theme

| Theme / package | Approx. count | Primary cause |
|-----------------|---------------|---------------|
| `myeventlane_vendor_theme` | ~165 | Drupal/Gin form overrides, settings, builder |
| `myeventlane_theme` | ~80 | Live ops, checkout, booking |
| `myeventlane_radix` | ~14 | Klaro consent, legacy forms |
| `mel_maintenance` | ~18 | Maintenance/error pages |
| Compiled / fallback CSS | ~10 | Built artifacts |

---

## Top offenders (rg `--count-matches`, 2026-06-13)

| Rank | File | Count | Domain |
|------|------|-------|--------|
| 1 | `myeventlane_vendor_theme/src/scss/components/_event-form.scss` | 94 | **Event forms** |
| 2 | `myeventlane_vendor_theme/src/scss/pages/_settings.scss` | 34 | **Settings** |
| 3 | `myeventlane_theme/src/scss/components/_live-operations.scss` | 31 | **Live operations** |
| 4 | `myeventlane_vendor_theme/src/scss/components/_mel-builder.scss` | 30 | **Builder** |
| 5 | `myeventlane_radix/src/scss/components/_klaro-consent.scss` | 12 | Consent (legacy radix) |
| 6 | `mel_maintenance/scss/maintenance.scss` | 9 | Out of scope |
| 7 | `myeventlane_theme/src/scss/components/_checkout.scss` | 4 | Checkout |
| 8 | `myeventlane_theme/src/scss/components/_booking-page.scss` | 4 | Booking |
| 9 | `myeventlane_theme/templates/html.html.twig` | 3 | Inline critical CSS |
| 10 | `myeventlane_vendor_theme/src/scss/main.scss` | 2 | Theme entry overrides |

**Focus areas (Phase 2B brief):** event forms, settings, builder, live operations = **189 declarations** (~63% of theme SCSS debt).

---

## Root causes (repository evidence)

| Cause | Files affected | Notes |
|-------|----------------|-------|
| **Drupal core / Gin form specificity** | `_event-form.scss`, `_settings.scss` | Overrides `.form-item`, `.js-form-wrapper`, field widgets |
| **Module shell vs theme grid conflict** | `_mel-builder.scss`, module `mel-event-studio-shell.css` | Module uses `!important` on `.mel-builder` grid; theme responds with higher-specificity rules |
| **Cross-surface shared partials** | `_live-operations.scss` | Loaded in both public and vendor builds; fights vendor shell styles |
| **Third-party widget overrides** | `_klaro-consent.scss` | Klaro modal z-index and display |
| **Commerce checkout panes** | `_checkout.scss` | Payment element layout stability |

---

## Sample patterns (do not fix in 2B)

### Event forms (`_event-form.scss`)

```scss
// Typical pattern: override Gin/Claro fieldset and widget layout
.mel-event-studio .form-item { ... !important; }
.mel-event-studio .fieldset { ... !important; }
```

**Retirement approach:** Raise specificity using `.mel-vendor .mel-event-studio` + BEM scoping; replace `!important` with structured layer order once Gin dependency is isolated.

### Settings (`_settings.scss`)

```scss
// Vendor settings tabs and vertical tabs
.mel-vendor-settings ... !important;
```

**Retirement approach:** Extract settings layout to module-owned settings shell CSS (behavioural) + theme tokens (visual).

### Builder (`_mel-builder.scss`)

```scss
// Grid overrides competing with module shell
.mel-event-studio .mel-builder { grid-template-columns: ... !important; }
```

**Retirement approach:** Negotiate single owner for builder grid (see [event-studio-css-ownership.md](./event-studio-css-ownership.md)); remove duplicate grid rules from one layer.

### Live operations (`_live-operations.scss`)

```scss
// Dashboard density and table scroll in vendor context
.mel-live-ops ... !important;
```

**Retirement approach:** Split vendor-only overrides into vendor theme partial; keep public partial free of vendor-specific `!important`.

---

## Retirement plan (Phase 2C → 2D)

### Phase 2C — Foundation (depends on 2B breakpoints)

| Priority | Target | Action | Risk |
|----------|--------|--------|------|
| P1 | `_event-form.scss` | Audit each `!important`; group by widget type; introduce `.mel-vendor .mel-event-studio-form` layer | Medium — form layout regressions |
| P2 | `_mel-builder.scss` | Align with module shell ownership; remove redundant grid `!important` | High — builder layout |
| P3 | `_settings.scss` | Scope under settings route body class; reduce Gin overrides | Medium |
| P4 | `_live-operations.scss` | Split public vs vendor concerns | Low–medium |

### Phase 2D — Consolidation

| Priority | Target | Action |
|----------|--------|--------|
| P5 | Module shell CSS | Remove `!important` from `mel-event-studio-shell.css` once theme grid is retired |
| P6 | Checkout / booking | Replace with token-based specificity after Commerce pane audit |
| P7 | Radix Klaro | Migrate to public theme `_klaro-consent.scss` only; retire radix duplicate |

---

## Metrics to track

| Metric | Phase 2B baseline | Phase 2C target |
|--------|-------------------|-----------------|
| Total `!important` in `web/themes/custom` | ~300 | No increase |
| `_event-form.scss` | 94 | < 40 |
| `_settings.scss` | 34 | < 15 |
| `_mel-builder.scss` | 30 | < 10 |
| `_live-operations.scss` | 31 | < 15 |

---

## Validation command

```bash
rg "!important" web/themes/custom --count-matches | sort -t: -k2 -nr | head -20
```

Re-run after each retirement slice; do not batch-removal without visual QA on Event Studio create/edit, settings, builder preview, and live ops dashboard.
