# MEL Phase 2 Audit — Event Studio Consolidation + Mobile-First Architecture

**Project:** MyEventLane (MEL) — Drupal 11 / Commerce 3
**Audited repository:** `/Users/anna/myeventlane` (active git repo only)
**Branch:** `feature/klaro-consent-ux`
**Date:** 2026-06-13
**Mode:** Audit only. No code changes. No implementation. Evidence-based findings drawn from the repository.

---

## 0. Method, scope and honesty notes

- This is a **static (code-evidence) audit**. Routes, controllers, subscribers, templates, SCSS and library files were read directly. File paths and route names below are quoted from the repository.
- Backup/sibling directories (`myeventlane_backup_*`, `myeventlane-archives`, `myeventlane-booking-polish`, `myeventlane-event-polish`, `mel-git-safety-backups`, `myeventlane-v1-backup`, `myeventlane-bugbot-fix`, `/Users/anna/custom/*`) were **excluded** per instruction.
- **Runtime behaviour was not exercised.** Mobile usability/accessibility scores in Part 3 are derived from template structure, SCSS, breakpoint usage and CTA markup — **not** from a running browser, device lab, Lighthouse or axe run. Where a score depends on runtime rendering it is marked accordingly.
- Where the repository does not confirm something, the audit states **"Evidence not found in repository."** rather than guessing.

### Enabled themes (evidence: `_myeventlane_audit/core.extension.yml`)
`myeventlane_theme`, `myeventlane_vendor_theme`, `myeventlane_admin` are present in `core.extension`. `myeventlane_radix` exists on disk (`web/themes/custom/myeventlane_radix`) but is **not listed as enabled** — treated as non-canonical. Both primary themes declare `base theme: stable9`.

---

## PART 1 — Executive Summary: Top 10 issues (ranked by impact)

| # | Issue | Evidence | Impact | Severity |
|---|-------|----------|--------|----------|
| 1 | **Five overlapping event create/edit/manage surfaces coexist** under `/vendor/*`. | `myeventlane_event_studio.routing.yml`, `myeventlane_vendor.routing.yml`, `myeventlane_event.routing.yml` | Violates "single event workflow / no parallel experiences". Maintenance + UX confusion. | **P1 / High** |
| 2 | **Event Studio itself has two generations of edit UI**: legacy per-section step forms (`/vendor/events/{node}/edit/{basic,datetime,tickets,description,preview,publish}`) superseded by the unified `/studio/{section}` workspace — and the legacy forms are now redirect-only. | `VendorLegacyWizardRedirectSubscriber.php:41-51` | Dead/legacy routes still registered; double mental model. | **P1 / Medium** |
| 3 | **A second, partially-migrated "Vendor Studio"** (`/vendor/studio`, `/vendor/studio/event/{event}/*`) still exposes live JSON save/publish endpoints (`VendorStudioController::saveEvent/publishEvent/eventData/submitReview`). Entry points redirect to Event Studio, but the write API remains. | `myeventlane_vendor.routing.yml:322-479`, `VendorStudioController.php:187-959` | Zombie write-path to event data outside the canonical editor; data-integrity + security surface. | **P1 / High** |
| 4 | **Two divergent navigation entry points for "Create event"** point to two different routes. | `myeventlane_core.links.menu.yml:64` → `myeventlane_vendor.create_event_gateway`; `myeventlane_vendor.links.menu.yml:33` → `myeventlane_event_studio.create` | Duplicate navigation; inconsistent onboarding/gateway logic. | **P1 / Medium** |
| 5 | **Two divergent SCSS breakpoint systems** with different APIs and no `breakpoints.yml`. | `myeventlane_theme/.../tokens/_breakpoints.scss` (`$mel-breakpoints` map + `width >=` range syntax) vs `myeventlane_vendor_theme/.../tokens/_breakpoints.scss` (`$breakpoint-sm/md/lg/xl` + `respond-to`/`respond-down` mixins) | No single source of truth for responsive behaviour; mobile-first not enforceable. | **P1 / High** |
| 6 | **Main theme skews desktop-first**: 337 `max-width` vs 286 `min-width` media queries; many hardcoded `@media (max-width: 768px)` bypass the mixin layer. | `grep` counts in `myeventlane_theme/src/scss`, `myeventlane_vendor_theme/.../_vendor-wizard.scss:19/35/156` | Contradicts the 390px-up mobile-first baseline. | **P1 / High** |
| 7 | **Massive component duplication within and across themes.** Main theme has both `_buttons.scss` and `_mel-buttons.scss`; `_cards.scss` + `_mel-cards.scss` + 7 more card partials; 7 form partials; `_tables.scss` + `_mel-tables.scss`; 3 nav partials. Vendor theme re-implements buttons/cards/forms/tables/tabs/badges. | component partial inventory, Part 3.2 | No single component system; style drift; high regression risk. | **P1 / High** |
| 8 | **Event Studio ships its own 14-file CSS system (10,159 lines, 68 `!important`)** including its own nav and shell, instead of consuming vendor-theme components. | `myeventlane_event_studio/css/*`, `myeventlane_event_studio.libraries.yml` | Module owns presentation that should live in the theme; duplicates vendor nav/cards/forms. | **P1 / High** |
| 9 | **High `!important` load: 381 total** (229 in theme SCSS, 152 in module CSS/SCSS, +68 in Event Studio CSS). | grep counts | Specificity wars; fragile overrides; symptom of competing component systems. | **P2 / Medium** |
| 10 | **Dead legacy node-form theming + placeholder management routes.** Bare `entity.node.add_form`/`edit_form` for events are access-denied (`EventNodeFormAccessSubscriber.php:8-23`) yet theme still ships `page--node--add--event.html.twig`, `form--node--event--form.html.twig`, `page--node--%--edit.html.twig`; `/vendor/event/{event}/{promote,payments,comms,advanced}` all resolve to `ManageEventPlaceholderController::placeholder`. | routing + templates | Dead code; misleads contributors; confuses Cursor. | **P2 / Low-Med** |

---

## PART 2 — Event Studio Consolidation

### 2.1 Route inventory

**Canonical: `myeventlane_event_studio`** (file: `myeventlane_event_studio.routing.yml`)

| Route | Path | Purpose | Active | Used (linked) | Duplicate of | Recommended action |
|-------|------|---------|--------|---------------|--------------|--------------------|
| `…create` | `/vendor/events/create` | Create event (canonical) | Yes | Yes — `myeventlane_vendor.links.menu.yml:33` | — | **Keep (canonical create).** |
| `…edit` | `/vendor/events/{node}/edit` | Edit entry (canonical) | Yes | Yes — redirect target from legacy | — | **Keep (canonical edit).** |
| `…workspace` + `…workspace_{information,branding,content,tickets,questions,capacity,extras,messaging,attendees,fulfilment,orders,analytics,settings}` | `/vendor/events/{node}/studio[/section]` | Unified studio workspace | Yes | Yes | — | **Keep (canonical management).** |
| `…workspace_{merchandise,addons,add_ons}` | `/studio/{merchandise,addons,add-ons}` | Redirect → `extras` | Yes (redirect) | n/a | — | **Keep as alias** (`redirectToExtrasWorkspace`). |
| `…workspace_promotions` | `/studio/promotions` | Redirect → `messaging` | Yes (redirect) | n/a | — | **Keep as alias** (documented legacy bookmark). |
| `…edit_{basic,datetime,tickets,description,preview,publish}` | `/vendor/events/{node}/edit/{…}` | Legacy per-section step forms | **Redirect-only** | No | Superseded by `workspace_*` | **Deprecate → confirm redirect, then remove forms** (see WP-2). |
| `…autosave`, `…publish`, `…governance_refresh`, `…governance_component`, `…ai_assist`, `…ticket_link_suggestions` | POST endpoints | Studio AJAX/JSON | Yes | Yes (Studio JS) | — | **Keep.** |

**Legacy wizard: `myeventlane_event`** (file: `myeventlane_event.routing.yml`)

| Route | Path | Purpose | Active | Used | Duplicate of | Recommended action |
|-------|------|---------|--------|------|--------------|--------------------|
| `…wizard.{basics,when_where,tickets,details,review,publish,success}` | `/vendor/events/{event}/build/{…}` | Form-API step wizard | **Redirect-only for vendors** | No (vendors redirected) | Event Studio create/edit | **Deprecate.** Staff-only per file header comment; confirm + remove or gate behind `administer nodes`. See WP-1. |
| `…duplicate` | `/vendor/events/{node}/duplicate` | Duplicate/rebook event | Yes | Likely (overview action) | — | **Keep** (distinct feature). |
| `…calendar_ics`, `…checkin_door`, `…checkin_validate`, `…generate_series_instances`, `…passcode_gate` | various | Non-authoring features | Yes | Yes | — | **Keep** (out of consolidation scope). |

**Vendor surfaces: `myeventlane_vendor`** (file: `myeventlane_vendor.routing.yml`)

| Route | Path | Controller | Active | Duplicate of | Recommended action |
|-------|------|-----------|--------|--------------|--------------------|
| `…create_event_gateway` | `/create-event` | `CreateEventGatewayController::gateway` → redirects to `event_studio.create` after login/onboarding checks | Yes | Entry wrapper for Studio create | **Keep as the single public create entry**, fold the account-menu link into it (WP-3). |
| `…manage_event.edit` | `/vendor/event/{event}/edit` | `ManageEventEditController::edit` → **redirects** to `event_studio.edit` (`:48`) | Yes (redirect) | `event_studio.edit` | **Keep as alias** or remove after link sweep. |
| `…shell.dashboard` `/dashboard`, `…shell.vendor_root` `/vendor`, `…console.dashboard` `/vendor/dashboard` | dashboards | `vendor_dashboard:*` | Yes | — | **Keep** (dashboard, not authoring). |
| `…console.studio` | `/vendor/studio` | `VendorStudioController::studio` → redirects to `event_studio.edit`/`create` (`:101-114`) | Yes (redirect) | Event Studio | **Keep redirect / retire route.** |
| `…console.event_editor` | `/vendor/events/{event}/editor` | `VendorStudioController::eventEditor` → redirects to `event_studio.edit` (`:151`) | Yes (redirect) | Event Studio | **Keep redirect / retire.** |
| `/vendor/studio/event/{event}/{data,save,overview,tickets,attendees,promotion,settings,publish,submit-review}` | JSON write API | `VendorStudioController::{eventData,saveEvent,saveOverview,saveTickets,saveAttendees,savePromotion,saveSettings,publishEvent,submitReview}` | **Yes — live write endpoints** | Parallel to Studio autosave/publish | **P1: retire** — confirm no caller, then remove (WP-4). Zombie write path. |
| `/vendor/events/{event}` + `/overview,/orders,/addons,/orders/{order},/tickets,/rsvps,/analytics,/settings,/archive,/unpublish,/promotion,/promotion/branding,/publish` | Event workspace (renders own UI) | `event_workspace`, `vendor_event_overview`, `vendor_event_orders`, `vendor_event_rsvps`, `vendor_event_analytics`, `vendor_event_settings`, `vendor_event_archive`, forms | **Yes — active, renders own pages** | Overlaps Studio `workspace_*` sections | **Decide ownership** (WP-5): either these become the Studio sub-tabs or redirect into Studio. Currently both exist. |
| `/vendor/event/{event}/{design,content,checkout-questions,series}` | singular mgmt | `ManageEvent{Design,Content,CheckoutQuestions}Controller`, `ManageSeriesInstancesController` | Yes | Overlaps Studio sections | **Fold into Studio sections.** |
| `/vendor/event/{event}/tickets` | redirect | `ManageEventTicketsController::redirectToCanonicalTickets` | Yes (redirect) | — | Keep alias / remove. |
| `/vendor/event/{event}/{promote,payments,comms,advanced}` | placeholders | `ManageEventPlaceholderController::placeholder` | **Dead stubs** | — | **Remove** (WP-6). |

> **Evidence note:** "Used (linked)" reflects menu/task/action link files and in-controller `Url::fromRoute()` references found during the audit. A full link-graph crawl (every Twig `path()`/`url()`) was **not** exhaustively performed — see WP-7 (link sweep) before deletion.

### 2.2 Current workflow maps (as-built)

**Create (multiple entries, converging):**
```
Footer "Create event" (myeventlane_core)
   → /create-event  (CreateEventGatewayController)
        → login / organiser-onboarding checks
        → redirect → myeventlane_event_studio.create  (/vendor/events/create)

Account menu "Create event" (myeventlane_vendor)
   → /vendor/events/create  (DIRECT — bypasses gateway checks)

Onboarding
   → /vendor/onboard/first-event → (Studio create)
```

**Edit / manage (fragmented, partially converging):**
```
/vendor/event/{event}/edit          → 302 → event_studio.edit          (alias OK)
/vendor/studio                       → 302 → event_studio.edit/create   (alias OK)
/vendor/events/{event}/editor        → 302 → event_studio.edit          (alias OK)
/vendor/events/{event}/build/*       → 302 → Studio (vendors)           (legacy wizard)
/vendor/events/{node}/edit/{section} → 302 → unified studio             (legacy step forms)

CANONICAL:
/vendor/events/{node}/edit
/vendor/events/{node}/studio/{section}

STILL-LIVE PARALLELS (not redirecting):
/vendor/events/{event}/{overview,orders,tickets,rsvps,analytics,settings,…}   (renders own UI)
/vendor/event/{event}/{design,content,checkout-questions,series}              (renders own UI)
/vendor/studio/event/{event}/{save,publish,data,…}                            (JSON write API)
```

**Publish:** canonical `myeventlane_event_studio.publish` (`POST /studio/publish`) and `EventStudioPublishForm` (redirects to `entity.node.canonical`). Parallel publish exists at `VendorStudioController::publishEvent` and `EventWorkspaceController::publish`.

### 2.3 Navigation audit

| Navigation system | Location (file) | User type | Duplicate? | Remove/Consolidate? |
|-------------------|-----------------|-----------|------------|---------------------|
| Footer host "Create event" | `myeventlane_core.links.menu.yml:64` → `create_event_gateway` | Vendor/host | **Yes** (2nd create entry) | Make this the single create entry. |
| Account menu "Create event" | `myeventlane_vendor.links.menu.yml:33` → `event_studio.create` | Vendor | **Yes** | Point to gateway (or remove duplication). |
| Event Studio local task tab | `myeventlane_event_studio.links.task.yml:1` ("Event Studio") | Vendor/staff | No | Keep. |
| Vendor dashboard nav | `VendorDashboardController` / vendor theme `layout/_navigation.scss` | Vendor | Partially (links to both Studio + vendor workspace routes) | Normalise targets to Studio. |
| Event Studio internal nav | `myeventlane_event_studio/css/mel-event-studio-nav.css` + `mel-event-studio-nav.html.twig` | Vendor | **Yes** (separate nav system from vendor theme) | Consolidate into one nav component. |
| Vendor "Studio" entry | `/vendor/studio` (`console.studio`) | Vendor | **Yes** (redirects, but still advertised) | Retire route + any link. |

### 2.4 Route usage classification (evidence-based)

- **Active & canonical:** all `myeventlane_event_studio.*` workspace + create/edit + POST endpoints.
- **Active alias (302 → canonical):** `manage_event.edit`, `console.studio`, `console.event_editor`, `/vendor/event/{event}/tickets`, the `edit_*` step forms, the `build/*` wizard (for vendors), the `studio/{promotions,addons,…}` redirects.
- **Active but parallel (renders own UI — true duplication):** `/vendor/events/{event}/{overview,orders,addons,rsvps,analytics,settings,archive,unpublish,promotion}`, `/vendor/event/{event}/{design,content,checkout-questions,series}`.
- **Active write API parallel (highest risk):** `/vendor/studio/event/{event}/{save,overview,tickets,attendees,promotion,settings,publish,submit-review,data}`.
- **Dead code:** `/vendor/event/{event}/{promote,payments,comms,advanced}` (placeholder); themed bare-node-form templates for an access-denied route.
- **Evidence not found in repository:** an explicit, single, documented "canonical route map" config that marks legacy routes deprecated — the deprecation intent lives only in code comments/subscribers.

### 2.5 Consolidation plan

**Target canonical flow:**
```
Create Event  →  Event Studio (/vendor/events/create)
       ↓
Event Studio workspace  (/vendor/events/{node}/studio/{section})
       ↓
Publish  (myeventlane_event_studio.publish)
       ↓
Manage   (same studio workspace sections)
```

Everything else must become **(a) a 301/302 alias**, **(b) a Studio section**, or **(c) removed**.

| Action | Priority | Effort | Risk |
|--------|----------|--------|------|
| Retire the `/vendor/studio/event/{event}/*` JSON write API (after caller sweep) | P1 | M | High |
| Decide ownership of `/vendor/events/{event}/{overview,orders,…}` vs Studio sections; converge | P1 | L | High |
| Remove legacy `edit_*` step-form routes/forms (already redirect-only) | P1 | S | Low |
| Confirm + remove `build/*` wizard for vendors (keep staff path or delete) | P2 | M | Medium |
| Unify "Create event" entries to one gateway | P1 | S | Low |
| Delete placeholder routes `/promote,/payments,/comms,/advanced` | P2 | S | Low |
| Remove dead node-form templates | P3 | S | Low |
| Fold `/vendor/event/{event}/{design,content,checkout-questions,series}` into Studio sections | P2 | L | Medium |

---

## PART 3 — Mobile-First Architecture

### 3.1 Breakpoint inventory

| Location | Definition | Mobile-first? | Notes |
|----------|-----------|---------------|-------|
| `myeventlane_theme/src/scss/tokens/_breakpoints.scss` | `$mel-breakpoints` map; emits `@media (width >= $breakpoint)` and `(width <= upper-bound)` | Mixed (range syntax) | Modern CSS range syntax; its own API. |
| `myeventlane_vendor_theme/src/scss/tokens/_breakpoints.scss` | `$breakpoint-sm:640 / md:768 / lg:1024 / xl:1280 / 2xl`; mixins `respond-to` (min-width, mobile-first), `respond-down` (max-width), `container-query` | Mostly mobile-first via `respond-to` | Different variable names + mixin API from main theme. |
| Hardcoded media queries (both themes) | e.g. `_vendor-wizard.scss:19/35/156` `@media (max-width:768px)`; `workspace.scss:329/343`; `pages/_vendor-events.scss:213/217` | **Desktop-first leakage** | Bypass the mixin layer; duplicate magic numbers. |
| Drupal `*.breakpoints.yml` | **None found** anywhere in `web/themes/custom` or `web/modules/custom` | n/a | No responsive image/breakpoint config; `responsive_image` mappings cannot be driven from a shared source. |

**Media-query direction counts (evidence):**
- `myeventlane_theme/src/scss`: **286 `min-width` vs 337 `max-width`** → net desktop-first lean.
- `myeventlane_vendor_theme/src/scss`: **146 `min-width` vs 136 `max-width`** → roughly balanced, but many raw `max-width:768px`.

**Conclusion:** No single source of truth. Two token systems + ad-hoc media queries. **Canonical recommendation:** one shared `_breakpoints.scss` (vendor theme's `$breakpoint-*` + `respond-to`/`respond-down`/`container-query` is the stronger candidate; add a 390px `xs` baseline), consumed by both themes; ban raw `@media` in component partials via stylelint.

### 3.2 Component inventory

| Component | Variants found (files) | Canonical candidate | Consolidation required |
|-----------|------------------------|---------------------|------------------------|
| Buttons | theme: `_buttons.scss`, `_mel-buttons.scss`; vendor: `_buttons.scss` | `_mel-buttons.scss` (newer `mel-` system) | **Yes — 3 systems** |
| Cards | theme: `_cards.scss`, `_mel-cards.scss`, `_event-card.scss`, `_event-cards.scss`, `_event-cards-festival.scss`, `_account-cards.scss`, `_value-cards.scss`, `_vendor-card.scss`, `_attendees-event-card.scss`; vendor: `_cards.scss`, `_kpi-cards.scss`, `_boost-extension-card.scss`, `_attendees-event-card.scss`; Studio: `mel-event-studio-extra-card` | `_mel-cards.scss` + a shared event-card | **Yes — 13+ variants** |
| Forms | theme: `base/_forms.scss`, `_mel-forms.scss`, `_mel-form-states.scss`, `_event-form.scss`, `pages/_event-form.scss`, `pages/_vendor-form.scss`, `onboarding/_onboarding-forms.scss`; vendor: `base/_forms.scss`, `_forms.scss`, `_form.scss`, `_event-form.scss`, `pages/_event-form.scss` | `_mel-forms.scss` + shared base | **Yes — 12 partials, incl. vendor `_forms.scss` vs `_form.scss`** |
| Tables | theme: `_tables.scss`, `_mel-tables.scss`; vendor: `_tables.scss`, `_event-table.scss` | `_mel-tables.scss` | **Yes — 4** |
| Navigation | theme: `_mel-navigation.scss`, `layout/_navigation.scss`, `_account-nav.scss`; vendor: `layout/_navigation.scss`; Studio: `mel-event-studio-nav.css` | one shared nav | **Yes — 5 nav systems** |
| Tabs | vendor: `_tabs.scss`; theme: none dedicated | vendor `_tabs.scss` | Promote to shared. |
| Modals | theme: `_mel-modals.scss` | `_mel-modals.scss` | Single — promote/share with vendor. |
| Drawers | theme: `_mobile-drawer.scss` | `_mobile-drawer.scss` | Single — share with vendor. |
| Badges | theme: `_featured-badge.scss`, `_sla-badge.scss`; vendor: `_badges.scss` | vendor `_badges.scss` | **Yes — 3** |
| Chips | theme: `_chips.scss` | `_chips.scss` | Single — share. |
| Filters | theme: `_mel-events-filters.scss`, `_search-form.scss` | `_mel-events-filters.scss` | Consolidate with search. |

**Does Event Studio duplicate vendor-theme components?** **Yes.** Event Studio ships its own nav (`mel-event-studio-nav.css`), shell (`mel-event-studio-shell.css`), cards and form styling as a **module-owned** 14-file/10,159-line CSS system (`myeventlane_event_studio/css/*`, attached via `myeventlane_event_studio.libraries.yml`) rather than consuming vendor-theme components.

### 3.3 CSS ownership map

| File / scope | Owns | Conflicts with |
|--------------|------|----------------|
| `myeventlane_theme/src/scss/components/_mel-*` | Public-site buttons/cards/forms/tables/nav/modals | Older `_buttons/_cards/_tables/_navigation` in same theme |
| `myeventlane_vendor_theme/src/scss/components/*` | Vendor console buttons/cards/forms/tables/tabs/badges | Main theme equivalents; Event Studio module CSS |
| `myeventlane_event_studio/css/*` (14 files, 10,159 lines, 68 `!important`) | Studio nav/shell/cards/forms/panels | **Vendor theme nav/cards/forms** (module overriding theme territory) |
| 36 custom modules ship `css/` | Per-feature styling (e.g. `myeventlane_vendor`, `myeventlane_dashboard`, `myeventlane_checkin`, `myeventlane_commerce`, …) | Themes; each other |
| Committed compiled CSS in `themes/.../css/` (`auth-pages.css`, `errors.css`) | Built output checked into repo | Source `src/scss` — drift risk if rebuilt elsewhere |

**`!important` load (specificity-war indicator):** **381 total** — 229 (theme `src/scss`) + 152 (module `css`/`scss`) + 68 inside Event Studio CSS (subset of module count noted separately for emphasis).

**Ownership model recommendation:** Themes own all presentation; modules ship behaviour (JS) + structural Twig only. Migrate `myeventlane_event_studio/css/*` into `myeventlane_vendor_theme` component partials; modules should attach **theme** libraries, not ship component CSS.

### 3.4 Route-by-route mobile audit

> Scores are **code-evidence-based** (template + SCSS + breakpoint + CTA structure), 1–10, **not runtime-tested**. "Evidence not found" is used where no dedicated template/SCSS was located. Recommend confirming with WP-8 (device/Lighthouse pass) before acting on conversion/accessibility numbers.

**Homepage** — `page--front.html.twig`, `node--view--front-featured-events--block-hero.html.twig`, card-carousel, `_mel-events-filters.scss`

| Category | Score | Evidence / note |
|----------|-------|-----------------|
| Mobile usability | 6 | Hero + carousel templates exist; mixed breakpoint direction. |
| Accessibility | 5 | Not verified at runtime; no axe evidence. |
| Consistency | 5 | Multiple card variants in play (`_event-card*`). |
| Conversion | 6 | Single hero CTA present in template. |
| Trust | 5 | Organiser trust not strongly templated on front. |
| Technical debt | 4 | Multiple card/filter partials. |

**Event page** — `page--node--event.html.twig`, `node--event--*`

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 6 | Dedicated page template + event-card system. |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 5 | Competing event-card partials. |
| Conversion | 6 | Ticketing/CTA block themed; sticky mobile footer not confirmed — **Evidence not found**. |
| Trust | 6 | Organiser block present. |
| Technical debt | 5 | Card duplication. |

**Checkout** — `commerce/commerce-checkout-form*.html.twig`, `commerce-checkout-order-summary`, `commerce-checkout-progress`, `mel-checkout-order-summary-grouped`

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 6 | Multiple checkout templates incl. with-sidebar variant. |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 5 | `myeventlane_checkout_flow` ships **no CSS** (relies on theme) — good, but a Radix checkout template also exists (`myeventlane_radix/.../commerce-checkout-form.html.twig`) for a disabled theme = dead. |
| Conversion | 6 | Progress + summary themed; mobile sticky CTA not confirmed — **Evidence not found**. |
| Trust | 5 | Trust signals not strongly evident in templates. |
| Technical debt | 6 | Radix dead template. |

**Vendor dashboard** — `myeventlane-vendor-dashboard.html.twig`, `VendorDashboardController`, vendor `layout/_navigation.scss`, `_quick-actions.scss`

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 6 | `_quick-actions.scss:38` uses `max-width:768px` (desktop-first patch). |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 4 | Links to both Studio + parallel `/vendor/events/{event}/*` surfaces. |
| Conversion | 6 | Quick-actions present. |
| Trust | 5 | n/a |
| Technical debt | 3 | Duplicate CTAs to overlapping management routes. |

**Event Studio** — `mel-event-studio-workspace.html.twig` (+ ~27 templates), `myeventlane_event_studio/css/*`

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 6 | Dedicated shell; `_workspace.scss:151/167` uses `min-width:768px` (mobile-first). |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 4 | Own nav/shell diverges from vendor theme. |
| Conversion | 6 | Step flow + publish CTA present. |
| Trust | 6 | Governance/health components present. |
| Technical debt | 3 | 14 module CSS files, 68 `!important`, two edit generations. |

**Orders** — `myeventlane-order-detail.html.twig`, `vendor/_event-table.scss`, `views-view-table--commerce-checkout-order-summary`

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 5 | Table-based; card fallback for mobile not confirmed — **Evidence not found**. |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 5 | Two table systems (`_tables` vs `_event-table`). |
| Conversion | n/a | — |
| Trust | n/a | — |
| Technical debt | 5 | Table duplication. |

**Attendees** — `myeventlane-vendor-event-attendees.html.twig`, `myeventlane-vendor-attendees-dashboard.html.twig`, `_attendees-event-card.scss` (in **both** themes)

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 5 | Card partial duplicated across themes. |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 4 | Same component in two themes. |
| Conversion | n/a | — |
| Trust | n/a | — |
| Technical debt | 4 | Duplication; export/filter UX not confirmed — **Evidence not found**. |

**Messaging** — Studio `messaging` section + `mel-event-studio-*`; `myeventlane_messaging` module CSS

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 5 | Thread layout template not individually confirmed — **Evidence not found** for a dedicated thread template. |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 5 | Studio-owned. |
| Technical debt | 5 | Module CSS + Studio CSS overlap. |

**Analytics** — `vendor_event_analytics` controller, `myeventlane_analytics` module CSS, `_vendor-event-performance.scss` (`:188 min-width:1024px`)

| Category | Score | Note |
|----------|-------|------|
| Mobile usability | 4 | Performance component gated at `min-width:1024px` → likely thin on mobile. |
| Accessibility | 5 | Runtime not verified. |
| Consistency | 5 | Charts responsiveness not confirmed — **Evidence not found** (no chart lib config inspected). |
| Technical debt | 5 | Module + theme split. |

---

## PART 4 — Cursor Work Packages

> Each package is **implementation-ready specification only** — no code. Validation commands assume the DDEV workflow from `CLAUDE.md`.

### WP-1 — Decommission the legacy `/build/*` event wizard
- **Objective:** Remove (or staff-gate) the `myeventlane_event.wizard.*` step wizard now that vendors are redirected to Studio.
- **Files likely affected:** `myeventlane_event.routing.yml`; `src/Form/EventWizard*Form.php`; `src/Controller/VendorEventWizardController.php`; `EventSubscriber/VendorLegacyWizardRedirectSubscriber.php`; any Twig with `path('myeventlane_event.wizard.*')`.
- **Risks:** Staff still rely on wizard (file header says staff keep it); hidden links; tests referencing wizard routes.
- **Validation:** `ddev drush cr`; `ddev drush route:info | grep build`; `grep -rn "myeventlane_event.wizard" web/` ; `npm run build`; manual: vendor hitting `/vendor/events/{id}/build/basics` → 302 to Studio.
- **Acceptance:** No vendor-reachable `/build/*` route renders a form; either fully removed or returns 403/redirect for non-staff; no broken `path()` references; config export clean.

### WP-2 — Remove redirect-only Event Studio `edit_*` step forms
- **Objective:** Delete `myeventlane_event_studio.edit_{basic,datetime,tickets,description,preview,publish}` routes + `EventStudioBasic/Date/Tickets/Description/Publish` forms now superseded by the unified `/studio` workspace.
- **Files likely affected:** `myeventlane_event_studio.routing.yml`; `src/Form/EventStudio{Basic,Date,Tickets,Description,Publish}Form.php`; `EventStudioPreviewController.php`; `VendorLegacyWizardRedirectSubscriber.php` (`:46-51`).
- **Risks:** Forms reused by the unified workspace controller (verify `EventStudioController::workspace` does not embed these form classes); bookmarked URLs.
- **Validation:** `grep -rn "edit_basic\|EventStudioBasicForm\|edit_datetime\|edit_tickets" web/`; `ddev drush cr`; manual: `/vendor/events/{id}/edit/basic` → 302 to studio section.
- **Acceptance:** Routes removed or 301 to `studio/{section}`; unified workspace unaffected; no orphaned form classes; PHPUnit green.

### WP-3 — Unify "Create event" navigation to a single gateway
- **Objective:** One create entry. Point account-menu "Create event" at `create_event_gateway` (or remove the duplicate), keeping gateway login/onboarding checks authoritative.
- **Files likely affected:** `myeventlane_vendor.links.menu.yml:33`; `myeventlane_core.links.menu.yml:64`; any Twig/button using `event_studio.create` directly.
- **Risks:** Gateway adds redirects/latency for already-onboarded vendors; deep links expecting direct create.
- **Validation:** `grep -rn "event_studio.create\|create_event_gateway" web/`; `ddev drush cr`; manual: both menu items reach Studio create after gateway checks.
- **Acceptance:** Exactly one user-facing create entry; `event_studio.create` only reached via gateway (direct nav links removed); onboarding/Stripe checks still enforced.

### WP-4 — Retire the parallel `/vendor/studio/event/{event}/*` JSON write API
- **Objective:** Remove the zombie write/publish endpoints on `VendorStudioController` after confirming no live caller; canonical writes go through Studio autosave/publish.
- **Files likely affected:** `myeventlane_vendor.routing.yml:357-479`; `src/Controller/VendorStudioController.php` (`eventData/saveEvent/saveOverview/saveTickets/saveAttendees/savePromotion/saveSettings/publishEvent/submitReview`); any JS posting to `/vendor/studio/event/*`.
- **Risks:** **High** — these write event data; an active JS client would silently break; security/data-integrity if left half-removed.
- **Validation:** `grep -rn "/vendor/studio/event" web/ --include=*.js --include=*.twig --include=*.php`; check `myeventlane_vendor.console.event_*` route usages; `ddev drush cr`; smoke-test Studio save/publish still works.
- **Acceptance:** No reachable parallel write endpoints; Studio autosave/publish is the sole write path; no JS 404/405s in network log; tests green.

### WP-5 — Resolve Studio vs `/vendor/events/{event}/*` workspace ownership
- **Objective:** Decide whether `/vendor/events/{event}/{overview,orders,tickets,rsvps,analytics,settings,…}` become Studio sections or redirect into Studio; eliminate the parallel rendered surface.
- **Files likely affected:** `myeventlane_vendor.routing.yml:501-738`; `EventWorkspaceController`, `VendorEventOverviewController`, `VendorEvent{Orders,Rsvps,Analytics,Settings}Controller`; `myeventlane_event_studio` workspace controller/sections; vendor dashboard nav.
- **Risks:** **High** — large surface; orders/analytics data views; deep links; permissions differ between the two.
- **Validation:** route inventory diff; `ddev drush cr`; `grep -rn "vendor.console.event_" web/`; manual walk of each section pre/post.
- **Acceptance:** One rendered management surface per concern; the other redirects; navigation points to a single set of routes; no feature regression in orders/analytics/attendees.

### WP-6 — Delete placeholder management routes
- **Objective:** Remove `/vendor/event/{event}/{promote,payments,comms,advanced}` and `ManageEventPlaceholderController`.
- **Files likely affected:** `myeventlane_vendor.routing.yml:860-910`; `src/Controller/ManageEventPlaceholderController.php`; any nav linking to them.
- **Risks:** Low — stubs; confirm not linked from dashboard nav.
- **Validation:** `grep -rn "ManageEventPlaceholderController\|/promote\|/advanced" web/`; `ddev drush cr`.
- **Acceptance:** Routes + controller removed; no broken links; config export clean.

### WP-7 — Route link-graph sweep (prerequisite for deletions)
- **Objective:** Produce the authoritative list of every reference to legacy/alias routes before any removal, so deletions are safe.
- **Files likely affected:** read-only across `web/modules/custom`, `web/themes/custom`, config.
- **Risks:** Missing dynamic route building (`Url::fromUserInput`, string concatenation) → false "unused".
- **Validation:** `grep -rn "fromRoute('myeventlane_" web/`; `grep -rn "path('myeventlane_\|url('myeventlane_" web/themes web/modules`; `grep -rn "/vendor/event" web/ --include=*.js`.
- **Acceptance:** A reference table (route → callers) committed alongside this audit; WP-1/2/4/5/6 reference it.

### WP-8 — Establish canonical breakpoint + component foundation
- **Objective:** One shared breakpoint system (390px-up) and one component library; ban raw media queries in partials.
- **Files likely affected:** new shared `tokens/_breakpoints.scss`; `myeventlane_theme/src/scss/tokens/_breakpoints.scss`; `myeventlane_vendor_theme/src/scss/tokens/_breakpoints.scss`; all hardcoded `@media` partials (`_vendor-wizard.scss`, `workspace.scss`, `pages/_vendor-events.scss`, `_quick-actions.scss`, …); `.stylelintrc`.
- **Risks:** Visual regressions across many surfaces; build pipeline (Vite) recompilation; two themes consuming one source.
- **Validation:** `npm run lint`; `npm run build`; visual diff of homepage/event/checkout/dashboard/studio at 390/768/1024/1280; Lighthouse + axe pass (currently unverified).
- **Acceptance:** Single `$breakpoint-*` + `respond-to/respond-down/container-query` API used everywhere; stylelint forbids raw `@media (max-width…)` in `components/*`; net `min-width` ≥ `max-width` per theme; no visual regressions.

### WP-9 — Collapse duplicate component partials
- **Objective:** One buttons, one cards (+ event-card), one forms, one tables, one nav, one badges system; share modals/drawers/chips/tabs across themes.
- **Files likely affected:** all partials listed in Part 3.2; Twig referencing removed classes.
- **Risks:** High regression surface; `!important` removal exposes layout bugs.
- **Validation:** `npm run build`; `grep -rn "_mel-buttons\|_buttons\b" web/themes`; visual diff; `!important` count should drop from 381.
- **Acceptance:** One canonical partial per component; deprecated partials removed/`@forward`-aliased; `!important` materially reduced; no visual regressions on the 9 audited surfaces.

### WP-10 — Move Event Studio CSS into the theme
- **Objective:** Relocate `myeventlane_event_studio/css/*` (nav/shell/cards/forms) into `myeventlane_vendor_theme` components so the module ships behaviour + structure only.
- **Files likely affected:** `myeventlane_event_studio.libraries.yml`; `myeventlane_event_studio/css/*` (14 files); vendor theme `components/`; library attachments in Studio controllers/templates.
- **Risks:** High — Studio is a flagship surface; cache/library weight ordering (`mel-event-studio-shell.css: { weight: 200 }`).
- **Validation:** `ddev drush cr`; `npm run build`; manual Studio walk at 390/768/1024; confirm nav/shell render identically.
- **Acceptance:** Studio styling owned by the theme; module CSS files removed or reduced to genuinely module-specific behaviour; Studio visually unchanged; `!important` in Studio CSS (68) reduced.

### WP-11 — Remove dead node-form theming + Radix checkout template
- **Objective:** Delete templates serving access-denied/disabled surfaces.
- **Files likely affected:** `myeventlane_theme/templates/page--node--add--event.html.twig`, `form--node--event--form.html.twig`, `page--node--%--edit.html.twig`; `myeventlane_radix/templates/commerce/commerce-checkout-form.html.twig`.
- **Risks:** Low — confirm `EventNodeFormAccessSubscriber` still denies and Radix stays disabled.
- **Validation:** `ddev drush cr`; confirm `entity.node.add_form` (event) → 403 for vendors; `ddev drush theme:list | grep radix`.
- **Acceptance:** Dead templates removed; no theme-registry warnings; event authoring still routes to Studio only.

---

## Appendix A — Primary evidence files

- Routing: `myeventlane_event_studio.routing.yml`, `myeventlane_event.routing.yml`, `myeventlane_vendor.routing.yml`, `myeventlane_core.links.menu.yml`, `myeventlane_vendor.links.menu.yml`, `myeventlane_event_studio.links.task.yml`
- Controllers: `CreateEventGatewayController.php`, `ManageEventEditController.php`, `VendorStudioController.php`, `EventWorkspaceController.php`, `VendorEventOverviewController.php`, `VendorEventSettingsController.php`, `ManageEventPlaceholderController.php`
- Subscribers: `VendorLegacyWizardRedirectSubscriber.php`, `EventNodeFormAccessSubscriber.php`
- Themes: `myeventlane_theme/src/scss/**`, `myeventlane_vendor_theme/src/scss/**` (tokens, components, pages), `*.info.yml` (both `base theme: stable9`)
- Studio CSS/libraries: `myeventlane_event_studio/css/*` (14 files), `myeventlane_event_studio.libraries.yml`, `myeventlane_event_studio/templates/*`
- Config snapshot: `_myeventlane_audit/core.extension.yml`

## Appendix B — Not verified (recommend follow-up)
- Runtime mobile usability/accessibility (Lighthouse, axe, real-device) — Part 3.4 scores are static only.
- Exhaustive Twig/JS link graph for every legacy route (WP-7 delivers this).
- Chart/analytics responsiveness; messaging thread template; checkout sticky mobile CTA; orders mobile card fallback — marked "Evidence not found in repository" above.
