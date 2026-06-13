# Canonical Design System Proposal — Phase 2A

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Status:** Proposal only — **no implementation** in Phase 2A.

**Inputs:** `mobile-breakpoint-inventory.md`, `component-system-inventory.md`, `css-ownership-map.md`, `mobile-route-priority-map.md`.

**Style guide target:** 390px baseline, mobile-first, one primary action per screen, single component/breakpoint system.

---

## 1. Single breakpoint system (recommended)

### Proposal

Adopt **one token map** in `myeventlane_theme/src/scss/tokens/_breakpoints.scss` as the cross-platform source of truth, consumed by:

- Public theme (already uses `mel-break`)
- Vendor theme (replace `$breakpoint-*` / `respond-to` with `@use '@mel-theme/tokens/breakpoints'` or equivalent shared package)
- Module CSS (Phase 2C+: migrate hardcoded px to documented tokens or CSS custom properties)

### Recommended token map (proposal — requires product sign-off)

| Token | Current public | Current vendor | Proposed | Notes |
|-------|----------------|----------------|----------|-------|
| Base | `xs: 0` | (implicit) | `0` | Mobile-first default |
| **Mobile max** | — | — | **`390px` style guide** | Add explicit `mel-break(max, sm)` at **391px** or define `sm: 390px` after stakeholder approval |
| sm | `480px` | `640px` | **Pick one** — recommend **480px** to align with existing public `mel-break(sm)` usage OR **390px** if style guide is strict | **Conflicting today** |
| md | `768px` | `768px` | `768px` | Already aligned |
| lg | `1024px` | `1024px` | `1024px` | Aligned |
| xl | `1280px` | `1280px` | `1280px` | Aligned |
| 2xl | — | `1536px` | Optional vendor-only wide | Keep or drop |

### Deprecate

- Ad-hoc **`900px`** header/auth breakpoints → map to `md` (768) or new `nav: 900` token if product requires
- Ad-hoc **`767px` / `959px`** module cuts → standardize on `mel-break(md, max)` (767px) or token-derived values
- Vendor **`479px`** KPI rules → derive from unified sm token

### Drupal

- Add `myeventlane_theme.breakpoints.yml` when responsive images are in scope — **Evidence not found** today.

---

## 2. Single button system (recommended)

### Canonical owner

| Layer | File | Responsibility |
|-------|------|----------------|
| **Global `.mel-btn`** | `myeventlane_theme/src/scss/components/_buttons.scss` | All variants: primary, secondary, ghost, destructive, sizes, touch |
| **Form-scoped** | `_mel-buttons.scss` | `.mel-form-system` Drupal submit mapping only |
| **Vendor** | Remove duplicate base in vendor `_buttons.scss`; keep only `.mel-vendor`-specific overrides if needed | Eliminate gradient vs flat divergence |

### Class vocabulary (canonical)

- `.mel-btn`, `.mel-btn--primary`, `.mel-btn--secondary`, `.mel-btn--ghost`, `.mel-btn--destructive`, `.mel-btn--sm`, `.mel-btn--touch`
- Legacy aliases (`.button--primary`) maintained in one alias block until Twig/PHP updated

### Module rule

Module CSS (`mel-event-studio.css`, RSVP, etc.) should **not** redefine `.mel-btn` base — only layout/context wrappers.

---

## 3. Single card system (recommended)

### Canonical owner

| Layer | File |
|-------|------|
| Base structure | `myeventlane_theme/src/scss/components/_cards.scss` |
| Governed modifiers | `_mel-cards.scss` (`mel-card--governed`, density via `[data-mel-density]`) |
| Vendor | Delete redundant base in vendor `_cards.scss`; use tokens imported from public |

### Class vocabulary

- `.mel-card`, `.mel-card__header`, `.mel-card__body`, `.mel-card__footer`
- Feature modifiers: `.mel-card--static`, `.mel-card--governed`, `.mel-card--compact`

### Empty states

Consolidate `.mel-empty` and `.mel-empty-state` under **`_mel-empty-states.scss`** with one BEM block (`.mel-empty-state` recommended).

---

## 4. Single form system (recommended)

### Canonical owner

| Layer | File |
|-------|------|
| Governed forms | `_mel-forms.scss`, `_mel-form-states.scss` |
| Element defaults | `base/_forms.scss` |
| Vendor | Thin wrapper only; **reduce `_event-form.scss` !important** in Phase 2C after markup alignment |

### Principles

- All vendor Event Studio forms use `.mel-form-system` wrapper (already referenced in module templates — verify per section in Phase 2B)
- Touch targets: min 44px (already in `_mel-buttons.scss` for form actions)

---

## 5. Single navigation system (recommended)

### Split by surface (intentional — not one mega-nav)

| Surface | Canonical owner | Mobile pattern |
|---------|-----------------|----------------|
| **Public** | `_site-header.scss` + `header.js` | Overlay mobile nav; **`768px` matchMedia** aligned to md token |
| **Vendor shell** | `myeventlane_vendor_theme.theme` nav builder + `layout/_navigation.scss` | Collapsible sidebar / stack (audit in 2B) |
| **Event Studio** | `mel-event-studio-nav.css` + shell CSS | Section list collapses to drawer/bottom sheet at md max (implementation Phase 2C) |

**Unification goal:** Same breakpoint token triggers public header close and studio sidebar collapse.

---

## 6. Implementation phasing (proposal — not Phase 2A)

| Phase | Focus |
|-------|-------|
| **2B** | Breakpoint token unification; remove dead vendor studio SCSS imports; document CSS custom properties bridge |
| **2C** | Event Studio shell mobile layout (sidebar → drawer); topbar single primary CTA |
| **2D** | Orders/attendees table → card stack pattern at sm/md |
| **2E** | Checkout + public event page breakpoint alignment |
| **2F** | Component dedup (vendor buttons/cards/forms); module CSS slimming |

---

## 7. Non-goals (Phase 2A / early 2B)

- Visual redesign or pastel brand changes
- Event Studio UX feature changes
- Commerce/checkout logic changes
- Removing mission control tables before workspace parity

---

## 8. Acceptance criteria for future “single system” claim

Before MEL can state a single design system is **implemented** (not just proposed):

1. One breakpoint map used by both themes (grep shows no parallel `640` vs `480` sm)
2. One `.mel-btn` definition (vendor does not redeclare base)
3. One `.mel-card` base (vendor imports only)
4. Event Studio shell mobile layout works at **390px** without horizontal scroll (manual QA)
5. `!important` count reduced in `_event-form.scss` and `_mel-builder.scss` by markup/ownership fix, not override wars

**Phase 2A completes the proposal only.**
