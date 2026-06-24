# MEL Vendor Experience — Audit + v3 Design

Date: 2026-06-23
Scope: `myeventlane_vendor` module + `myeventlane_vendor_theme`
Method: repository inspection only. No live environment was booted, so no runtime
screenshots, route-dump, or render validation were performed. Items that need a
running site are marked **[needs runtime]**.

---

## 0. Evidence base

- Routes: `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`
- Menu/tasks: `myeventlane_vendor.links.menu.yml`, `myeventlane_vendor.links.task.yml`
- Sidebar generation: `myeventlane_vendor_theme.theme` (2,815 lines) +
  `templates/includes/sidebar.html.twig`
- Layouts: `templates/layout/{page,console-page,vendor-workspace}.html.twig`,
  `templates/page*.html.twig`, `templates/dashboard/dashboard.html.twig`
- SCSS: `src/scss/` (22,887 lines), entry `src/scss/main.scss`
- Dashboard data: `src/Controller/VendorDashboardController.php`,
  `src/Service/VendorDashboardViewModelBuilder.php`

Where this document cannot confirm a claim from the repository it says so
explicitly rather than guessing.

---

## 1. Route inventory

### 1.1 Public / marketplace (render in `myeventlane_theme`, not console)
| Route | Path | Access |
|---|---|---|
| `entity.myeventlane_vendor.canonical` | `/vendor/{vendor}` | `_entity_access view` |
| `myeventlane_vendor.public_list` | `/vendors` | `access content` |
| `myeventlane_vendor.organisers` | `/organisers` | `access content` |
| `myeventlane_vendor.login_alias` | `/vendor/login` | public redirect |
| `myeventlane_vendor.create_event_gateway` | `/create-event` | public gateway |

### 1.2 Admin (Drupal admin theme)
| Route | Path |
|---|---|
| `entity.myeventlane_vendor.collection` | `/admin/structure/myeventlane/vendor` |
| `.add_form` / `.edit_form` / `.delete_form` | `…/vendor/{…}` |
| `myeventlane_vendor.settings` | `/admin/structure/myeventlane/vendor/settings` |

### 1.3 Onboarding flow (theme: `myeventlane_vendor_theme`)
`/vendor/onboard` → `.account` → `.profile` → `.stripe` → `.branding` →
`.first_event` → `.boost` → `.complete` (8 routes).

### 1.4 Stripe Connect
`/stripe/connect`, `/stripe/callback`, `/stripe/connect/callback` (legacy),
`/vendor/onboard/stripe-return`, `/vendor/onboard/stripe-refresh`,
`/stripe/manage` (6 routes).

### 1.5 Console shell
| Route | Path | Notes |
|---|---|---|
| `.shell.dashboard` | `/dashboard` | entrypoint redirect |
| `.shell.vendor_root` | `/vendor` | entrypoint redirect |
| `.console.dashboard` | `/vendor/dashboard` | mission-control dashboard |
| `.console.studio` | `/vendor/studio` | redirect → Event Studio |
| `.console.events` | `/vendor/events` | events list |
| `.console.events_add` | `/vendor/events/add` | create |
| `.console.payouts` | `/vendor/payouts` | |
| `.console.boost` | `/vendor/boost` | "Promote event" |
| `.console.audience` | `/vendor/audience` | |
| `.console.messaging_brand` | `/vendor/dashboard/messaging/brand` | |
| `.console.boost_vendor_export` | `/vendor/dashboard/boost/export` | |

### 1.6 Event workspace — **plural family** `/vendor/events/{event}/…`
`event_workspace`, `event_overview`, `event_orders`, `event_operational_addon_orders`,
`event_order_view`, `event_tickets`, `event_rsvps`, `event_analytics` (Pro-gated),
`event_settings`, `event_archive` (POST), `event_unpublish`, `event_promotion`,
`event_publish`, `boost_event_export`, `event_editor`, plus
`myeventlane_vendor_comms.branding`. (~16 routes)

### 1.7 Event management — **singular family** `/vendor/event/{event}/…`
`manage_event.edit`, `.design`, `.content`, `.tickets` (redirects to canonical),
`.checkout_questions`, `.series`, and **placeholder stubs**: `.promote`, `.payments`,
`.comms`, `.advanced` (all `ManageEventPlaceholderController`, noindex). (~10 routes)

> **Finding R-1 (high).** Three overlapping event-editing surfaces exist:
> (a) singular `/vendor/event/{event}/*` (`ManageEvent*Controller`), (b) plural
> `/vendor/events/{event}/*` (console workspace), (c) Event Studio
> (`myeventlane_event_studio.*`) which `.console.studio` and the sidebar redirect
> vendors into. The singular family is still live — referenced by
> `VendorDashboardController.php:1532` (`manage_event.series`) and
> `VendorDashboardViewModelBuilder.php:1016` (`manage_event.promote`) — yet four of
> its routes are non-functional placeholders. This is the single largest source of
> IA confusion and template debt.

---

## 2. Layout inventory

| Template | Role |
|---|---|
| `templates/page.html.twig` | thin `{% include layout/page %}` |
| `templates/page--vendor.html.twig` | thin include of `layout/page` |
| `templates/page--vendor-dashboard.html.twig` | thin include of `layout/page` |
| `templates/page--vendor--event-wizard.html.twig` | `extends page.html.twig`, wizard chrome |
| `templates/page--node--add--event.html.twig` | `extends page.html.twig`, static stepper |
| `templates/layout/page.html.twig` | **canonical shell** (sidebar + header + main + footer) |
| `templates/layout/console-page.html.twig` | inner console body (title/meta/actions/tabs/body) |
| `templates/layout/vendor-workspace.html.twig` | **separate** 3-column Studio shell (topbar/sidebar/main/inspector) |
| `templates/dashboard/dashboard.html.twig` | dashboard content (view-model driven) |
| `templates/mel-event/mel-event-workspace.html.twig` | event workspace content shell |

**Positive:** the `page--*` variants were already consolidated to delegate to one
`layout/page.html.twig` — earlier duplicated wrappers are gone.

> **Finding L-1 (high).** Two competing *shell* layouts coexist:
> `layout/page.html.twig` (sidebar + topbar header) and
> `layout/vendor-workspace.html.twig` (topbar + 300/1fr/320 grid with insights
> rail). They have different sidebars, headers, and spacing systems, so the same
> "vendor console" reads differently depending on which surface you land on.

> **Finding L-2 (med).** `layout/page.html.twig` carries heavy conditional branching
> (`is_dashboard_route`, `mel_vendor_route_tabs`, `sidebar_help`) inline. The
> dashboard governance `<details>` block is duplicated across both branches of the
> `page.sidebar_help` if/else — same markup written twice.

> **Finding L-3 (med).** `page--node--add--event.html.twig` hardcodes a 7-step
> stepper (Basics→Publish) that is always rendered with step 1 active and is not
> bound to wizard/form state. It is presentational fiction.

---

## 3. Sidebar audit

**Generation:** `templates/includes/sidebar.html.twig` is a dumb renderer; the IA
is built in PHP by `_myeventlane_vendor_theme_build_*_shell_nav_items()` inside the
2,815-line `.theme` file. Two variants: onboarding-restricted and full.

**Full nav definition (hardcoded array)** spans routes from **eight** modules:
`myeventlane_vendor.console.*`, `myeventlane_event_studio.create`,
`myeventlane_checkout_flow.vendor_attendees`, `myeventlane_tickets.ticket_checkin`,
`myeventlane_analytics.dashboard`, `myeventlane_escalations_portal.vendor_list`,
`myeventlane_refunds.vendor_refund_requests`, `myeventlane_vendor.console.settings`.

Items: Home, Events (+ Event Editor submenu), Event Editor, Orders*, Ticket holders,
Check-in*, Payouts, Grow event, Messaging, Insights, Support, Organiser settings
(+ conditional Refund requests). `*` = event-scoped, disabled until an event is in
route context.

**Strengths**
- Per-item access is real: `_named_route_accessible()` + `_safe_route_url()` drop
  inaccessible items (correct for menu vs URL access parity).
- Disabled event-scoped items degrade gracefully with "Open this from an event".
- `aria-current="page"`, `aria-disabled`, `aria-label` present.

> **Finding S-1 (high).** Navigation IA lives in ~300 lines of procedural PHP in a
> theme file. It is not a menu, not config, not a service — untestable, not
> overridable, and couples the theme to eight modules' route names. Any IA change
> is a theme-code change.

> **Finding S-2 (med).** Two near-identical builders (`onboarding` vs `full`) plus
> `decorate`/`safe_url`/`accessible`/`resolve_event_id` helpers duplicate logic that
> belongs in one service with one definition source.

> **Finding S-3 (med).** "Events" and "Event Editor" are sibling top-level items
> *and* "Event Editor" is also the submenu child of "Events" — redundant entry
> points that compound the R-1 three-surfaces problem.

> **Finding S-4 (low).** Brand mark is a hardcoded `<span>M</span>` glyph in
> `sidebar.html.twig`, not the WebP/SVG brand logo used elsewhere — inconsistent
> with the recent public-surface logo work.

---

## 4. Menu hierarchy audit

- `links.menu.yml` only defines the **account menu** (Home / My events / Create
  event / Settings) and admin-structure links. The console left-nav is *not* a
  Drupal menu — see S-1.
- `links.task.yml` defines event-workspace **local tasks** (Manage event / Tickets /
  Orders / Add-on orders / Attendees / RSVPs / Analytics / Settings) bound to
  `base_route: …event_workspace`. Attendees points at a *different* module's route
  (`myeventlane_event_attendees.vendor_list`) as its own base_route — tab grouping
  is split across two base routes.

> **Finding M-1 (med).** There are three parallel navigation systems for the same
> user: account menu (YAML), workspace local tasks (YAML), and the shell sidebar
> (PHP). They must be kept in sync by hand; "Settings" appears in two of them with
> different routes (`console.settings` vs task `event_settings`).

---

## 5. Component audit

Counts: **32** Twig components in `templates/components/`, **55** SCSS partials in
`src/scss/components/`.

Reusable, well-formed components: `kpi-card`, `empty-state`, `status-badge`,
`alert-box`, `section-heading`, `event-table`, `account-summary`, `tooltip`,
`sidebar-icon`, `notifications`, `onboarding-progress`.

> **Finding C-1 (med).** 55 SCSS component partials vs 32 Twig components → ~23
> style files with no matching component template (e.g. many `mel-boost-*`,
> `_attendees-*`, `_refund`, `_charts`). Styles are attached to markup emitted
> elsewhere (controllers/other modules) — orphaned/under-documented styling.

> **Finding C-2 (med).** Boost has ~10 separate component templates
> (`mel-boost-trend-intelligence`, `-action-engine`, `-insights-panel`,
> `-status-panel`, `-decision-support`, `-performance-level`, `-extension-card`,
> `mel-top-boost-opportunity`, `mel-dashboard-event-boost`, …). High fragmentation
> for one feature; likely consolidation opportunity.

> **Finding C-3 (low).** `kpi-card.html.twig` inlines six SVG icon paths via
> if/elseif; `sidebar-icon.html.twig` is a separate icon include. No single icon
> system — icons live in ≥3 places (kpi-card inline, sidebar-icon, event-form SVGs).

---

## 6. SCSS architecture audit

- Single scoped entry `main.scss` wraps everything under `.mel-vendor` via
  `meta.load-css` (good isolation). Separate bundles: `workspace.scss`,
  `vendor-wizard.scss`. Tokens: `tokens/{colors,typography,shadows,radii,
  breakpoints,spacing}`.
- Breakpoints re-export the canonical public system (`mel-break`, `respond-to`,
  xs390/sm640/md768/lg1024/xl1280/2xl1536) — shared, good.

> **Finding SC-1 (high).** `tokens/_colors.scss` defines **two competing palettes**:
> a navy/slate console palette with a **blue** primary (`$primary: #2563EB`), and a
> separate "MEL event workspace" **pastel** palette (`$mel-ws-coral #f26d5b`,
> `$mel-ws-purple #6e7ef2`, `$mel-ws-bg-warm #fef5ec`). The console primary is blue,
> not pastel — diverging from the MEL pastel brand direction in CLAUDE.md/DESIGN_SYSTEM.md.

> **Finding SC-2 (med).** 31 component/page SCSS files contain hardcoded 6-digit
> hex; `workspace.scss` uses raw hex (`#e7e9f1`, `#f7f8fc`, `#ffffff`) instead of
> tokens — token system is bypassed on the Studio shell.

> **Finding SC-3 (med).** Two very large page partials — `pages/_analytics.scss`
> (1,550 lines) and `pages/_dashboard-live-ops.scss` (1,427 lines) — plus
> `components/_mel-builder.scss` (1,268). Page-level SCSS this large signals
> insufficient componentisation.

> **Finding SC-4 (low).** Global `!important` underline reset on every
> `body.mel-vendor a` state — a specificity escape hatch that future links must
> fight.

---

## 7. Mobile audit

- Shell (`layout/page.html.twig`) has a hamburger (`data-sidebar-toggle`),
  overlay, off-canvas sidebar; JS (`src/js/main.js`) toggles `is-open`, syncs
  `aria-expanded`, closes on `Escape`. This is sound mobile-drawer behaviour.

> **Finding MO-1 (high).** `vendor-workspace.scss` Studio shell uses
> `height: calc(100vh - 56px)` with `grid-template-columns: 300px 1fr 320px`
> (or `1fr 320px`). Fixed 100vh + multi-column grid is desktop-first; on mobile the
> 320px inspector and 300px sidebar do not collapse here (this is a different shell
> from `layout/page`, so it does not get the drawer treatment). **[needs runtime]**
> to confirm the exact breakpoint behaviour, but the SCSS as written is not
> mobile-first.

> **Finding MO-2 (med).** `console-page.html.twig` tabs are a horizontal
> `mel-console__tabs` row with no documented overflow/scroll affordance for narrow
> screens. **[needs runtime]** for overflow check.

> **Finding MO-3 (med).** The dashboard mission-control template emits many nested
> `<section>`s with grid modifiers; without runtime testing the small-screen stacking
> order (priority → hero → events) cannot be confirmed. **[needs runtime]**

> **Finding D-7 (med).** `VendorDashboardController::buildDashboardActivity()`
> runs Commerce and entity queries on every dashboard request, but
> `VendorDashboardViewModelBuilder::buildActivityItems()` synthetic rows took
> precedence in Twig — real activity (including timestamps) was discarded.
> **Phase 4B (Option C)** resolves this by separating `dashboard_activity_items`
> (real activity) from `workspace_updates` (organiser setup status) without new
> queries.

---

## 8. Accessibility audit

**Good:** sidebar `aria-current`/`aria-disabled`/`aria-label`; drawer
`aria-expanded`/`aria-controls` + Escape; tooltip pattern documented as
non-focus-trapping; dashboard sections use `aria-labelledby`; empty-state uses
`role="status"`.

> **Finding A-1 (high).** `console-page.html.twig` renders `role="tablist"` with
> `role="tab"` + `aria-selected` on **`<a href>` links**, but there are no
> `role="tabpanel"` elements, no `aria-controls`, and no roving-tabindex keyboard
> handling. This is an incomplete/incorrect ARIA tab pattern — either use real
> tab semantics with panels + keyboard support, or drop the tab roles and treat
> them as a nav landmark of links. As-is it misleads AT users.

> **Finding A-2 (med).** Disabled sidebar items are `<span aria-disabled="true">`
> with the reason only in `title=` (not exposed reliably to AT, not on touch). Use
> visible helper text or `aria-describedby`.

> **Finding A-3 (med).** Hamburger button label is the `☰` glyph with an
> `aria-label`; acceptable, but the brand mark `M` glyph and several emoji
> (`💡` tip card, `↑/↓` deltas) carry meaning without text alternatives in some
> components. KPI delta arrows need `aria-hidden` + visually-hidden direction text.

> **Finding A-4 (needs runtime).** Contrast not verified. Blue `#2563EB` on white
> passes; pastel `$mel-ws-coral #f26d5b` and warm bg combinations need a contrast
> pass for WCAG AA, especially small text and status chips. **[needs runtime]**

---

## 9. Brand consistency audit

- DESIGN_SYSTEM.md / CLAUDE.md mandate "MEL pastel" direction. The vendor console
  primary is **blue** (SC-1); the pastel palette exists but is reserved for the
  "event workspace" surface — so the console and the workspace look like two
  products.
- Logo: sidebar uses a text `M` glyph; recent commits standardised public surfaces
  on a WebP brand logo (`f242f8515`). Vendor sidebar not aligned (S-4).
- Footer: separate `footer-internal` / `footer-dashboard-light` includes per
  surface — acceptable, but another per-surface fork to keep on-brand.

---

## 10. Screenshot inventory **[needs runtime — not captured]**

No site was booted, so these are the surfaces to capture (mobile 390px + desktop
1280px each) once an environment is available:

1. `/vendor/dashboard` — mission control (priority, hero, events, signals).
2. `/vendor/events` — events list/grid toggle, empty state.
3. `/vendor/events/add` & `/node/add/event` — create (static stepper).
4. `/vendor/events/{id}` — workspace overview + local tabs.
5. `/vendor/events/{id}/tickets|orders|rsvps|analytics|settings`.
6. `/vendor/event/{id}/edit|design|content|series` — singular family.
7. `/vendor/event/{id}/promote|payments|comms|advanced` — placeholder stubs.
8. `/vendor/studio` + Event Studio editor — 3-column workspace shell.
9. `/vendor/payouts`, `/vendor/boost`, `/vendor/audience`.
10. `/vendor/onboard/*` — all 8 onboarding steps.
11. Sidebar drawer open state (mobile) + disabled event-scoped items.

Capture method when ready: `ddev launch` + a headless browser (Playwright) script;
do not hand-edit production data.

---

## 11. Humanitix parity analysis

| Capability | Humanitix | MEL today | Gap |
|---|---|---|---|
| Single persistent left nav | Yes, one IA | Yes, but PHP-built, 3 nav systems | IA consolidation |
| One event-management surface | Yes (event → tabs) | **Three** (R-1) | High |
| Dashboard = action-first | Yes | Yes (mission control view model) | Good — keep |
| Tickets manager | Yes | `/events/{id}/tickets` form | Parity-ish |
| Orders + attendees + check-in | Yes, in event tabs | Split across modules/tabs | Cohesion |
| Payouts (Stripe) | Yes | Stripe Connect present | Parity |
| Insights/analytics | Yes | Pro-gated analytics | Parity (gated) |
| Empty/first-run states | Strong | Present (`empty-state`, onboarding) | Good |
| Consistent design language | Single | **Two palettes/shells** (SC-1, L-1) | High |
| Mobile-first console | Yes | Drawer shell yes; Studio shell no (MO-1) | Medium |

Net: data/feature parity is largely there; **parity gaps are structural** —
fragmented IA, multiple shells, split palette — not missing features.

---

## 12. Technical debt summary (ranked)

1. **R-1** Three event-editing surfaces (singular/plural/Studio) — high.
2. **S-1** Navigation IA hardcoded in 2,815-line theme PHP — high.
3. **SC-1 / L-1** Two palettes + two shells = two visual products — high.
4. **A-1** Broken ARIA tab pattern — high (a11y).
5. **MO-1** Studio shell not mobile-first — high.
6. **L-2 / S-2 / M-1** Duplicated layout branches, duplicated nav builders, three
   nav sources of truth — medium.
7. **C-1 / C-2 / SC-3** Orphaned SCSS, boost component sprawl, oversized page SCSS —
   medium.
8. **D-7** Dashboard activity queries discarded by synthetic view-model feed —
   medium (resolved Phase 4B).
9. **L-3, S-4, A-2, A-3, SC-2, SC-4, C-3** — low/medium polish.

---

# MEL Vendor Experience v3 — Design

## 13. UX strategy

1. **One console, one shell, one nav.** Every authenticated vendor route renders
   inside a single `VendorShell` with a single nav IA. No second shell.
2. **One event = one workspace.** Collapse the three event surfaces into a single
   `/vendor/events/{event}` workspace with tabs; Event Studio becomes the "Edit
   content/design" tab, not a parallel destination.
3. **Action-first home.** Keep the mission-control dashboard (view-model already
   exists) — it is the strongest existing asset.
4. **Mobile-first, AA by default.** Every component ships at 390px first; tab/menu
   patterns are correct ARIA + keyboard from day one.
5. **One pastel system.** Promote the workspace pastel palette to the console
   primary; retire the blue primary. Tokens only — no raw hex.

## 14. Information architecture (v3)

```
VendorShell
├─ Home            /vendor/dashboard
├─ Events          /vendor/events
│   └─ Event workspace  /vendor/events/{event}
│        ├─ Overview
│        ├─ Edit (content + design)   ← Event Studio embedded
│        ├─ Tickets
│        ├─ Orders (+ add-ons)
│        ├─ Attendees / Check-in
│        ├─ RSVPs
│        ├─ Messaging
│        ├─ Insights (Pro)
│        └─ Settings (incl. unpublish/archive)
├─ Payouts         /vendor/payouts
├─ Grow            /vendor/boost
├─ Audience        /vendor/audience
├─ Insights        /vendor/insights
├─ Support         (escalations portal)
└─ Settings        /vendor/settings
```

Single source of truth: a **`VendorNavBuilder` service** (PHP, in
`myeventlane_vendor`) returning a typed nav model; theme only renders it.
Account menu + local tasks derive from the same definition (or are removed in
favour of the shell nav) to kill M-1.

## 15. Component architecture (Twig SDC-style, `@vendor` namespace)

| Component | Props | Replaces |
|---|---|---|
| `VendorShell` | `nav`, `header`, `slots: content, utility` | `layout/page` + `vendor-workspace` (merged) |
| `VendorSidebar` | `items[] {key,label,icon,url,active,disabled,reason,children[]}` | `includes/sidebar` |
| `VendorHeader` | `title`, `subtitle`, `primary_action`, `notifications` | header block in `layout/page` |
| `VendorTabs` | `tabs[] {label,url,active}`, real `tabpanel` semantics | `console-page` tabs (fixes A-1) |
| `StatCard` (KPI) | `label,value,currency,icon,delta,color,meta` | `kpi-card` (icons via shared icon system) |
| `StatGrid` | `stats[]` | bespoke KPI rows |
| `ActivityFeed` | `items[] {icon,title,meta,time,url}` | dashboard signals markup |
| `QuickActions` | `actions[] {label,url,icon,severity}` | `_quick-actions` markup |
| `EmptyState` | `title,message,cta` | `empty-state` (keep) |
| `ProgressSteps` | `steps[] {label,state}`, bound to real state | static stepper (fixes L-3) + onboarding |
| `ContextActions` | `actions[]`, overflow-aware | header_actions in `console-page` |
| `Icon` | `name`, `aria` | unifies kpi/sidebar/form SVGs (C-3) |

SCSS: one partial per component under `components/`, tokens only; delete orphaned
partials (C-1) once their markup is migrated or confirmed external.

## 16. Route migration plan

1. Keep all current paths working (no broken links / SEO).
2. `/vendor/event/{event}/edit|design|content|series` → 301 to the matching
   `/vendor/events/{event}` workspace tab (controllers already exist on the plural
   family + Event Studio).
3. Delete the four placeholder routes (`promote|payments|comms|advanced`) or fold
   into real Settings/Grow tabs; remove the two live links
   (`VendorDashboardController:1532`, `ViewModelBuilder:1016`) that point at the
   singular family.
4. Collapse `/vendor/studio` + `event_editor` into the workspace "Edit" tab;
   keep `/vendor/studio` as a redirect for muscle memory.
5. Reconcile "Settings": one route, surfaced once.

All redirects via a `RedirectResponse` controller or `redirect` module config —
no path duplication.

## 17. Technical risks

- **Commerce / access (high):** event workspace tabs touch orders, tickets,
  payouts, refunds. Every migrated tab must preserve existing
  `_custom_access: myeventlane_vendor.access.vendor_console:access` and Pro/refund
  gates. Do not centralise nav access into the theme — keep route access authoritative.
- **Cacheability:** `VendorNavBuilder` output must carry correct cache contexts
  (`user.permissions`, `route`) since items are access- and event-context-dependent.
- **Theme registry:** routes rely on explicit `_theme: myeventlane_vendor_theme`;
  any new route must set it or inherit via negotiator.
- **Stripe return URLs:** registered in Stripe Dashboard — do not rename
  `/vendor/onboard/stripe-return` or `/stripe/callback`.
- **Regression surface:** SCSS is scoped under `.mel-vendor`; palette change is
  global to the console — needs a full visual pass. **[needs runtime]**

## 18. Implementation phases (small, reviewable)

- **P0 — Foundations (no visual change):** add `VendorNavBuilder` service; move the
  hardcoded nav arrays out of `.theme` into it; sidebar template consumes the
  service. Unit-test access filtering. *Validate:* `drush cr`, nav renders identically.
- **P1 — Tokens:** unify palette (promote pastel, retire blue primary), replace raw
  hex in `workspace.scss` with tokens. *Validate:* `npm run build`, visual pass.
- **P2 — Shell merge:** merge `vendor-workspace.html.twig` into one `VendorShell`;
  make it mobile-first (no fixed 100vh multi-column on small screens).
- **P3 — Tabs/a11y:** `VendorTabs` with correct tabpanel/keyboard semantics; replace
  `console-page` tab roles (fixes A-1, A-2, A-3).
- **P4 — Component extraction:** `StatCard/StatGrid/ActivityFeed/QuickActions/
  ProgressSteps/Icon`; bind stepper to real state (L-3); delete orphaned SCSS (C-1).
- **P5 — Route consolidation:** redirect singular family + Studio into workspace
  tabs; remove placeholders (R-1, S-3).
- **P6 — Nav source unification:** derive account menu + local tasks from
  `VendorNavBuilder` (M-1).
- **P7 — Boost consolidation:** fold ~10 boost components into a smaller set (C-2).

Each phase = one focused PR, no cross-cutting rewrites.

## 19. Validation commands

```bash
# Backend / config
ddev drush cr
ddev drush config:status
ddev composer validate

# Theme build / lint (run from theme dir)
cd web/themes/custom/myeventlane_vendor_theme && npm run build
npm run lint        # if defined in package.json

# Route + access sanity after migration
ddev drush route   # (devel) or inspect mel-routes.json regen
# Manual: visit each route in §10 at 390px and 1280px, run axe/Lighthouse.
```

## 20. Stop conditions honoured

- No screenshots fabricated — capture inventory provided instead (§10).
- No runtime-only claims asserted as fact — flagged **[needs runtime]**.
- No route/service/field invented — every name cited is from the files in §0.
