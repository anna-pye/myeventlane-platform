# Event Studio CSS Ownership — Phase 2B

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** Event Studio surfaces only. Audit + ownership alignment documentation. No redesign.

---

## Surfaces in scope

| Surface | Route pattern | Primary template |
|---------|---------------|------------------|
| Unified builder | `/vendor/events/{node}/studio` (create/edit) | `mel-event-studio.html.twig` |
| Workspace sections | `/vendor/events/{node}/studio/*` | `mel-event-studio-workspace.html.twig` |
| Onboarding wizard nav | Vendor onboarding routes | `mel-onboard-studio-nav.html.twig` |

---

## CSS sources (repository evidence)

| Source | File(s) | Library | Load context |
|--------|---------|---------|--------------|
| **Module shell** | `myeventlane_event_studio/css/mel-event-studio-shell.css` | `mel_event_studio/event_studio` | All Event Studio pages (weight 200) |
| **Module forms / widgets** | `mel-event-studio.css`, `mel-event-studio-nav.css`, panel CSS files | Same library + section-specific libraries | Per section/feature |
| **Public theme SCSS** | `myeventlane_theme/src/scss/components/_event-studio.scss` | Public + vendor dist via theme build | Event Studio routes (`body.mel-event-studio-page`) |
| **Vendor theme SCSS** | `myeventlane_vendor_theme/src/scss/components/_mel-builder.scss` | Vendor `main.css` under `.mel-vendor` | Vendor console Event Studio |
| **Vendor workspace** | `myeventlane_vendor_theme/src/scss/workspace.scss` | `workspace.css` | Vendor shell wrapper |
| **Shared governance regions** | Inline `#theme` templates in PHP builders | Rendered HTML classes only | Governance sidebar panels |

---

## Ownership matrix

### Layout

| Concern | Owner | Evidence |
|---------|-------|----------|
| Workspace grid (sidebar + main) | **Module** — `mel-event-studio-shell.css` | `.mel-es-workspace`, `.mel-layout` grid-template-columns |
| Builder two-column (form + preview sidebar) | **Module** (base) + **Vendor theme** (visual) | Module sets `.mel-builder { grid-template-columns: 1fr !important }` in shell CSS; vendor `_mel-builder.scss` defines `1fr 280px` grid with 21 `@media` blocks |
| Full-bleed page wrapper | **Public theme** | `body.mel-event-studio-page` in `_event-studio.scss` removes container max-width |
| Sticky sidebar position | **Module** | `.mel-es-workspace__sidebar { position: sticky; top: 100px }` in shell CSS |
| Mobile sidebar collapse | **Module** JS + shell CSS | `mel-event-studio-shell.js`, `@media` rules in shell CSS |

**Conflict note:** Module shell and vendor `_mel-builder.scss` both target `.mel-event-studio .mel-builder`. Module uses `!important` to force single-column in some contexts (shell CSS line 67). Vendor theme adds two-column builder at wider viewports. This is intentional layering but creates specificity debt.

### Typography

| Concern | Owner | Evidence |
|---------|-------|----------|
| Base font / scale | **Vendor theme tokens** (console) + **Public theme tokens** (shared partials) | Vendor `tokens/_typography.scss`; public `base/_typography.scss` |
| Step headings / card titles | **Vendor theme** — `_mel-builder.scss` | `.mel-builder-card__title`, step stack typography |
| Form labels / help text | **Vendor theme** — `_event-form.scss` | Heavy form overrides (94 `!important`) |
| Governance panel headings | **Module** inline templates + minimal shell CSS | `EventStudioGovernanceComponentBuilder` |

### Spacing

| Concern | Owner | Evidence |
|---------|-------|----------|
| Step stack vertical rhythm | **Module** shell CSS | `.mel-es-stack` gap rules |
| Builder card padding/gap | **Vendor theme** — `_mel-builder.scss` | `$spacing-8`, 32px grid gap |
| Form field spacing | **Vendor theme** — `_event-form.scss` | Overrides Drupal/Gin defaults |
| Page horizontal padding | **Public theme** — `_event-studio.scss` | 16px on `body.mel-event-studio-page` containers |

### Navigation

| Concern | Owner | Evidence |
|---------|-------|----------|
| Wizard step nav structure | **Module** templates | `mel-event-studio-nav.html.twig`, `mel-event-studio-wizard-nav.html.twig` |
| Nav visual styling | **Module** — `mel-event-studio-nav.css` + **Public theme** partial | Shared nav classes `.mel-es-nav`, `.mel-studio-nav` |
| Workspace section sidebar | **Module** — `mel-event-studio-sidebar.html.twig` + shell CSS | Section grouping headings |
| Topbar (save/publish) | **Module** — `mel-event-studio-topbar.html.twig` + shell CSS | Autosave status `#mel-studio-form-state` |

### Cards

| Concern | Owner | Evidence |
|---------|-------|----------|
| Builder step cards | **Vendor theme** — `_mel-builder.scss` | `.mel-builder-card`, `.mel-es-card` styling |
| Governance / status cards | **Public theme** shared partials | `@mel-theme/components/mel-cards`, `mel-status-panels` |
| Publish action card | **Module** template + module CSS | `mel-event-studio.html.twig` publish step; `data-mel-publish-panel` attributes |
| Preview card anchor | **Module** template | `#mel-preview-card`, `data-mel-studio-preview-anchor` |

### Forms

| Concern | Owner | Evidence |
|---------|-------|----------|
| Drupal form structure / widgets | **Module** PHP forms | `EventStudioForm.php`, section forms |
| Form layout (full-width) | **Public theme** — `_event-studio.scss` | `.mel-studio-form-wrapper`, `.mel-event-studio__form-full` width rules |
| Field visual overrides | **Vendor theme** — `_event-form.scss` | Primary owner of form appearance in console |
| Touch targets / mobile form rows | **Module** — `mel-event-studio.css` | Hardcoded 640px/767px gates (not token-linked) |
| Inline validation states | **Module** JS + module CSS | `mel-event-studio.js`, builder-inline-validation class on template |

---

## Legacy / retired (Phase 1E + 2B)

| Asset | Status | Evidence |
|-------|--------|----------|
| Staff shell `studio.html.twig` | Retired | [staff-shell-retirement-audit.md](./staff-shell-retirement-audit.md) |
| `.mel-studio-inspector` DOM | **No Twig/PHP references** | rg: class only in CSS files |
| `.mel-studio-editor-drawer` DOM | **No Twig/PHP references** | `studio-drawer.js` early-returns without drawer element |
| `_vendor-studio-editor.scss` | **Removed** (Phase 2B) | Dead import; no DOM |
| `_studio-inspector.scss` | **Removed** (Phase 2B) | Dead import; no DOM |
| `_vendor-studio-layout.scss` | **Retained** (not in 2B scope) | References `.vendor-workspace--studio` — class not found in Twig/PHP; candidate for Phase 2C cleanup |
| `myeventlane_core/css/studio-layout.css` `.mel-studio-inspector` rules | **Retained** | Used by admin PCC shell layout CSS; separate from Event Studio |

---

## Recommended ownership rules (going forward)

1. **New Event Studio layout changes** → module `mel-event-studio-shell.css` first; theme layers polish only.
2. **New builder card / step visuals** → vendor `_mel-builder.scss` (or extract to shared `@mel-theme` partial if needed on public routes).
3. **Form field appearance** → consolidate toward vendor `_event-form.scss`; retire `!important` incrementally (see [important-debt-register.md](./important-debt-register.md)).
4. **Do not** add grid rules to `_event-studio.scss` that conflict with shell CSS grid contracts.

---

## Phase 2B actions taken

- Documented ownership matrix (this file).
- Removed dead staff-shell SCSS imports (`vendor-studio-editor`, `studio-inspector`).
- No changes to module shell CSS or builder layout in this phase.

---

## Phase 2C recommendations

1. Audit `.vendor-workspace--studio` and `_vendor-studio-layout.scss` for removal (no DOM evidence).
2. Align vendor `_mel-builder.scss` hardcoded `@media` with canonical breakpoint mixins.
3. Reduce module/theme `.mel-builder` specificity conflict (retire shell `!important` where vendor grid is authoritative).
4. Migrate `_event-form.scss` `!important` debt using design-token-based specificity strategy.
