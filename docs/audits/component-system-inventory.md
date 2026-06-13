# Component System Inventory — Phase 2A

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** Reusable UI components across `myeventlane_theme`, `myeventlane_vendor_theme`, and module CSS/Twig. Audit only.

---

## Architecture overview

| Layer | Path pattern | Role |
|-------|--------------|------|
| **MEL governed system** | `myeventlane_theme/src/scss/components/_mel-*.scss` | Surface/governance components (forms, cards, nav, modals, tables, empty states) |
| **Legacy public partials** | `_buttons.scss`, `_cards.scss`, `_checkout.scss`, etc. | Public/marketplace UI predating full `mel-*` layering |
| **Vendor console** | `myeventlane_vendor_theme/src/scss/components/_*.scss` | Vendor-scoped duplicates under `.mel-vendor` |
| **Module CSS** | `web/modules/custom/*/css/*.css` | Feature-specific overrides (Event Studio shell, checkout, RSVP) |

Vendor theme **imports** public `mel-*` partials via `@mel-theme/components/...` in `main.scss` (lines 97–103) while also maintaining local `_buttons.scss`, `_cards.scss`, etc.

---

## Component inventory

### Buttons

| Component | Variants (repository evidence) | Locations | Canonical candidate |
|-----------|-------------------------------|-----------|---------------------|
| `.mel-btn` | `--primary`, `--secondary`, `--cta`, `--destructive`, `--ghost`, `--sm`, `--lg`, `--touch`, `--coral`, `--disabled` / `.is-disabled` | `myeventlane_theme/src/scss/components/_buttons.scss`; `myeventlane_vendor_theme/src/scss/components/_buttons.scss`; module CSS (e.g. `mel-event-studio.css`, `rsvp-thankyou.css`) | **`_buttons.scss` (public)** for global `.mel-btn`; **`_mel-buttons.scss`** for `.mel-form-system` scoped Drupal submit hierarchy |
| Legacy Drupal | `.button--primary`, `.button--secondary`, `.button--rsvp`, `.button--ticket` | Aliased in public `_buttons.scss` | **Duplicate** — alias layer on public buttons |
| Vendor-only | `.mel-button` comment in vendor file header | Vendor `_buttons.scss` | **Duplicate naming** (docblock says mel-button; classes use mel-btn) |

### Cards

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-card` | Base, `--static`, `__header`, `__body`, `__footer`, BEM structure | Vendor `_cards.scss`; public `_cards.scss`; `_event-card.scss`; `_event-cards.scss` | **Public `_cards.scss` + `_mel-cards.scss` modifiers** (`mel-card--governed`, density tokens) |
| Event-specific | `.mel-event-card`, festival variants, vendor card thumb | `_event-card.scss`, `_event-cards-festival.scss`, vendor templates | **Feature extensions** — not canonical base |
| KPI / metric | `.mel-kpi-card`, vendor KPI cards | Vendor `_kpi-cards.scss`, `_mel-metrics.scss` | **Duplicate** (vendor + mel-metrics) |

### Forms

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-form-system` | Governed form shell, actions, field states | `_mel-forms.scss`, `_mel-form-states.scss` | **Canonical (governed forms)** |
| Base forms | Element defaults | `base/_forms.scss`, vendor `base/_forms.scss`, `_forms.scss` | **Duplicate** |
| Event / vendor forms | Heavy overrides, 94× `!important` in vendor `_event-form.scss` | Vendor `_event-form.scss`, `_form.scss`, module `mel-event-studio.css` | **Conflicting ownership** |

### Tables

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-table` / responsive tables | Scroll, stack at `767px` | `_tables.scss`, `_mel-tables.scss`, vendor `_event-table.scss`, `_tables.scss` | **`_mel-tables.scss`** (governed) + public `_tables.scss` legacy |
| Data grids | Commerce / orders | Module CSS (`mel-vendor-operational-addon-orders.css`, vendor order view) | **Feature-owned** |

### Tabs

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-tabs`, workspace tabs | Console tabs, wizard tabs | Vendor `_tabs.scss`; `_wizard.scss`; module `simple-tabs.css` | **Duplicate** — vendor tabs + module simple-tabs |
| Event Studio nav | Section nav in shell | `mel-event-studio-nav.css`, `_event-studio.scss` | **Module-owned** |

### Navigation

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| Public header | `.mel-nav-*`, mobile wrapper, overlay | `_site-header.scss`, `components/site-header/`, `src/js/header.js` | **Canonical (public)** |
| MEL navigation system | Governed nav patterns | `_mel-navigation.scss` | **Canonical (governed surfaces)** |
| Vendor console nav | Sidebar, shell nav | Vendor `layout/_navigation.scss`, `myeventlane_vendor_theme.theme` shell nav builder | **Vendor-owned** |
| Event Studio sidebar | `.mel-sidebar`, section list | `mel-event-studio-shell.css`, templates | **Module-owned** |

### Badges / status

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-badge` | `--muted`, confidence variants in Twig | Vendor `_badges.scss`; boost decision Twig | **Vendor `_badges.scss`** |
| `.mel-status`, `.mel-status-badge` | Event state, moderation | `_sla-badge.scss`, Event Studio templates | **Duplicate** naming (`mel-status` vs `mel-status-badge`) |
| Governance states | `.mel-state-*` | `_mel-states.scss`, `_mel-readiness.scss` | **Canonical (governed)** |

### Chips / pills

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-chip`, `.mel-chip-group` | Help centre filters | `_help-centre.scss`, `_help-search.scss` | **Public help-centre** |
| `.mel-category-pill` | Discovery filters | `_category-pills.scss`, `_mel-events-filters.scss` | **Duplicate** (pills vs chips naming) |

### Modals / overlays / drawers

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-modal`, overlays | Governed modals | `_mel-modals.scss`, `_mel-overlays.scss`, `_mel-disclosure.scss` | **`_mel-modals.scss` / `_mel-overlays.scss`** |
| Dialog patterns | Event card removal | `mel-event-card-removal-dialog.html.twig`, `_mel-event-card-removal.scss` | **Feature component** |
| Notifications UI | Drawer at `600px` | `mel-notifications-ui.css` | **Module-owned** |

### Alerts / toasts

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-toast`, vendor alert | Toast stack | `_toasts.scss`, vendor `_vendor-alert.scss`, `_notifications.scss` | **Duplicate** |
| Trust / policy banners | Governance | `_mel-trust-policy.scss`, `_mel-reassurance.scss` | **Governed** |

### Empty states

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-empty-state` | `--compact`, `__actions` | Vendor `_empty-states.scss`; `_mel-empty-states.scss` | **Naming split**: `.mel-empty` vs `.mel-empty-state` |
| `.mel-empty` | Browse, calendar empty | `_mel-browse.scss`, `utilities/_empty-states.scss`, page templates | **Duplicate** patterns |

### Search / filters

| Component | Variants | Locations | Canonical candidate |
|-----------|----------|-----------|---------------------|
| `.mel-search-form`, filters | Event discovery | `_search-form.scss`, `_mel-events-filters.scss` | **Public discovery** |
| Help search | Chip filters | `_help-search.scss` | **Feature** |

---

## Duplicate / conflicting patterns (high level)

| Issue | Evidence |
|-------|----------|
| Two button SCSS owners | Public `_buttons.scss` + vendor `_buttons.scss` + `_mel-buttons.scss` scope split |
| Two card base definitions | Public `_cards.scss` vs vendor `_cards.scss` (different tokens: `$mel-color-surface` vs `$ml-vendor-card`) |
| Empty state class split | `.mel-empty` vs `.mel-empty-state` vs `.mel-empty-state--listing` |
| Tabs | Vendor `_tabs.scss` vs module `simple-tabs.css` |
| Event Studio duplicates theme | Module `mel-event-studio-shell.css` + theme `_event-studio.scss` + vendor `_mel-builder.scss` |

---

## Canonical candidate summary (proposal input only)

| Component family | Recommended canonical owner (Phase 2B+) |
|------------------|----------------------------------------|
| Buttons | Public `_buttons.scss` + `_mel-buttons.scss` (form scope) |
| Cards | Public `_cards.scss` + `_mel-cards.scss` modifiers |
| Forms | `_mel-forms.scss` / `_mel-form-states.scss` |
| Tables | `_mel-tables.scss` |
| Navigation (public) | `_site-header.scss` + `_mel-navigation.scss` |
| Navigation (vendor) | Vendor layout nav + Event Studio module nav CSS |
| Modals / overlays | `_mel-modals.scss`, `_mel-overlays.scss` |
| Empty states | Consolidate to `_mel-empty-states.scss` vocabulary |

**No implementation in Phase 2A.**
