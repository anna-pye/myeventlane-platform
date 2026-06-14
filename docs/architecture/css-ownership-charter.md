# CSS Ownership Charter — Phase 2B

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Evidence basis:** Existing library attachments, theme SCSS structure, module `*.libraries.yml`, and Phase 2A inventory.

This charter documents **actual ownership** as observed in the repository. It is not aspirational policy.

---

## Layer model

| Layer | Path pattern | Loads via |
|-------|--------------|-----------|
| **Public theme** | `web/themes/custom/myeventlane_theme/src/scss/` | Vite → `dist/`; `myeventlane_theme.info.yml` global library |
| **Vendor theme** | `web/themes/custom/myeventlane_vendor_theme/src/scss/` | Vite → `dist/`; scoped under `.mel-vendor` in `main.scss` |
| **Module CSS** | `web/modules/custom/*/css/*.css` | Module `*.libraries.yml` attached per route/form |
| **Admin theme** | `web/themes/custom/myeventlane_admin_theme/` | Admin-only routes |

Vendor theme also consumes **shared SCSS partials** from the public theme via Vite alias `@mel-theme` (see `myeventlane_vendor_theme/vite.config.js`).

---

## Module CSS ownership

Module CSS owns **behavioural shell, layout contracts, and JS-dependent structures** that must load independently of theme builds.

| Responsibility | Evidence | Example paths |
|----------------|----------|---------------|
| **Layout grid / shell structure** | Comments and selectors in module CSS; attached before theme overrides | `myeventlane_event_studio/css/mel-event-studio-shell.css` (`.mel-es-workspace` grid) |
| **JS-dependent UI states** | Classes toggled by module JS | `mel-event-studio-shell.js` → sidebar collapse classes in shell CSS |
| **Form widget structure** | Attached on specific forms/routes | `myeventlane_event/css/event-wizard.css`, `myeventlane_rsvp/css/rsvp-form.css` |
| **Checkout / operational commerce columns** | Commerce module libraries | `myeventlane_commerce/css/mel-operational-checkout.css` |
| **Admin platform shell** | Core module library | `myeventlane_core/css/studio-layout.css` + `MelAdminShellBuilder` |

**Rule observed in Event Studio:** module shell CSS defines grid, sticky sidebar, step stack rhythm, and accessibility/state classes. Theme SCSS may override width/colour but should not redefine the operational grid (see comment block in `mel-event-studio-shell.css` lines 1–11).

---

## Public theme CSS ownership

Public theme owns the **design system** for customer-facing and shared MEL surfaces.

| Responsibility | Evidence | Example paths |
|----------------|----------|---------------|
| **Design tokens** | `src/scss/tokens/` | `_colors.scss`, `_spacing.scss`, `_breakpoints.scss` |
| **Typography** | `src/scss/base/_typography.scss`, token file | Headings, body scale, font stacks |
| **Spacing / layout rhythm** | `src/scss/layout/`, token spacing | `_container.scss`, `_page-grid.scss` |
| **Buttons** | `src/scss/components/_buttons.scss` | `.mel-btn` variants |
| **Cards** | `src/scss/components/_cards.scss`, `_mel-cards.scss` | `.mel-card` baseline |
| **Forms (visual)** | `src/scss/base/_forms.scss`, `_event-form.scss` | Field spacing, labels, errors |
| **Discovery / marketing pages** | `src/scss/pages/` | `_front-page.scss`, `_search.scss` |
| **Shared vendor-facing partials** | Imported by vendor theme via `@mel-theme` | `_event-studio.scss`, `_live-operations.scss`, `_help-centre.scss` |

Public theme SCSS is **not scoped** globally (except route-specific body classes like `body.mel-event-studio-page`).

---

## Vendor theme CSS ownership

Vendor theme owns **vendor-console-specific overrides and density**, scoped under `.mel-vendor`.

| Responsibility | Evidence | Example paths |
|----------------|----------|---------------|
| **Console density / compact UI** | `.mel-vendor { }` wrapper in `main.scss` | KPI cards, tables, dashboard grids |
| **Vendor-only components** | Not shared via `@mel-theme` | `_mel-builder.scss`, `_mel-event-settings.scss`, `_workspace.scss` |
| **Vendor shell chrome** | `workspace.scss`, layout partials | Header, sidebar, two-column console |
| **Overrides of shared partials** | Loaded after `@mel-theme` imports | Vendor `_event-form.scss` (94 `!important` — form override debt) |

**Rule:** Vendor theme should **not** duplicate public design tokens. It re-exports breakpoints from public theme (Phase 2B). Colour/spacing tokens remain vendor-local in `src/scss/tokens/` with `root-tokens.scss` custom properties on `.mel-vendor`.

---

## Shared SCSS between themes

| Partial | Canonical owner | Vendor consumption |
|---------|-------------------|-------------------|
| Breakpoints | Public `tokens/_breakpoints.scss` | `@forward` re-export |
| Event Studio polish | Public `_event-studio.scss` | `@mel-theme/components/event-studio` |
| Live operations | Public `_live-operations.scss` | `@mel-theme/components/live-operations` |
| Help centre | Public `_help-centre.scss` | `@mel-theme/components/help-centre` |
| MEL cards / nav / empty states | Public `_mel-*.scss` | `@mel-theme/components/mel-*` |

---

## What does NOT belong in theme CSS

| Concern | Correct owner |
|---------|---------------|
| Entity access / vendor isolation | PHP services, route access, entity queries |
| Payment / checkout state | Commerce modules, checkout panes |
| Event Studio section contracts | `myeventlane_event_studio` module plugins + shell CSS |
| Autosave / publish behaviour | Module JS + controllers |

---

## Anti-patterns observed (document, do not fix in 2B)

| Pattern | Location | Risk |
|---------|----------|------|
| Duplicate breakpoint systems | Was vendor + public (resolved in 2B) | Inconsistent responsive behaviour |
| Module + theme both defining Event Studio grid | `mel-event-studio-shell.css` + `_mel-builder.scss` | Specificity wars, `!important` |
| Hardcoded `@media` bypassing tokens | `_site-header.scss` (900px), `_event-gallery.scss` | Drift from canonical breakpoints |
| Legacy staff shell SCSS | Removed in 2B: `_vendor-studio-editor.scss`, `_studio-inspector.scss` | Dead CSS in bundle |

---

## Decision log (Phase 2B)

1. **Canonical breakpoints** live in public theme; vendor re-exports.
2. **Module shell CSS** remains authoritative for Event Studio layout grid.
3. **Theme SCSS** layers colour, typography, and polish on module contracts.
4. **Vendor theme** scopes console overrides under `.mel-vendor`; shared visual language comes from `@mel-theme` partials.

---

## Related documents

- [breakpoint-unification-plan.md](./audits/breakpoint-unification-plan.md)
- [event-studio-css-ownership.md](./audits/event-studio-css-ownership.md)
- [important-debt-register.md](./audits/important-debt-register.md)
- [mel-component-system.md](./architecture/mel-component-system.md)
