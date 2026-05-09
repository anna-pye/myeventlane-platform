# MEL vendor workspace convergence — design plan (Plan-mode artifact)

> **Status:** Plan only. **No design code in this slice.** Do not begin per-page work without explicit slice sign-off from product/lead.
>
> Companion to:
> - `docs/adoption/mel-live-operations-convergence-implementation.md`
> - `docs/adoption/mel-live-operations-convergence-map.md`

## 1. What is already convergent

Audited at `web/themes/custom/myeventlane_vendor_theme` and `web/modules/custom/myeventlane_vendor`:

| Layer | State | Evidence |
|-------|-------|----------|
| Body class | Convergent | `myeventlane_vendor_theme.theme` line 625 always adds `mel-vendor` and `mel-vendor-theme`. |
| Global CSS bundle | Convergent | Every `buildVendorPage()` call attaches `myeventlane_vendor_theme/global-styling` → `myeventlane_vendor_theme/global` → `dist/main.css`. |
| Live-operations component family availability | Convergent | `myeventlane_vendor_theme/src/scss/main.scss` line 102 already loads `@mel-theme/components/live-operations` inside the `.mel-vendor` scope. The selectors are present on every vendor page; no per-page `attachLibrary` is needed. |
| Outer event shell | Convergent | All event-scoped controllers call `buildVendorPage('mel_event_workspace', …)` which renders `templates/mel-event/mel-event-workspace.html.twig`. |
| Console shell (non-event) | Convergent | Payouts / audience / events list / messaging brand call `buildVendorPage('myeventlane_vendor_console_page', …)` → `templates/layout/console-page.html.twig`. |

**The shell is shared. The CSS is loaded. The gap is exclusively at the per-page CONTENT-template + view-model layer.**

## 2. What still differs between Operations and the rest

The "Operations design language" is composed of these primitives, all defined in `web/themes/custom/myeventlane_theme/src/scss/components/_live-operations.scss`:

- `.mel-live-ops__hero` + `.mel-live-ops__hero-aside` + progress ring (`__progress-shell`, `__progress-ring`, `__progress-percent`, `__progress-fill`)
- `.mel-live-ops__metric-board` + `.mel-live-ops__stat-card[--total|--in|--remain|--ready|--blocked|--capacity]`
- `.mel-live-ops__split` + `.mel-live-ops__stack` two-column layout
- `.mel-live-ops__quick-bar` action toolbar
- `.mel-live-ops__attendees` + `.mel-live-ops__attendee-row` cards (with `state-chip`, `done-pill`, operational badges)
- `.mel-live-ops__timeline` recent activity feed
- `.mel-live-ops__chip` system (`--success`, `--warning`, `--info`, `--calm`, …)
- `.mel-live-ops__panel-title` / `__panel-intro` / `__eyebrow` typography rhythm
- `.mel-live-ops__search-card` panelled search input

These are NOT typography rules — they're component layouts. The reason "operations looks different" is structural: that template uses these primitives, the others don't. Renaming `.mel-live-operations` → `.mel-vendor` does not move metric boards or progress rings onto other pages.

## 3. Per-page audit + slice plan

For each page, this section lists:

- **Controller** that calls `buildVendorPage(...)`.
- **Current content template** (the part that renders inside `mel-event-workspace` / `console-page` / `dashboard`).
- **Current view-model / data shape**.
- **Live-ops primitives that map cleanly onto the page**.
- **Live-ops primitives that DO NOT map** (page has structurally different content).
- **Slice size estimate** (S = ≤1d UI, M = 1–3d, L = 3+d) and **dependencies** (what backend / view-model work is required first).

> Slice estimates assume: a single bounded slice = 1 controller + 1 content template + 1 SCSS partial + view-model adjustment + tests. They do **NOT** include legacy template removal until the new template is live in production.

### 3.1 Vendor dashboard (`/vendor/dashboard`)

- **Controller:** `myeventlane_vendor/src/Controller/VendorDashboardController.php` → `buildVendorPage('myeventlane_vendor_dashboard', $pageVars)` (line 536). Note: dashboard uses its OWN theme hook, not `mel_event_workspace`. Different shell — different convergence work.
- **Content template:** `themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` (273 lines).
- **Current data shape:** `vendor_dashboard_view_model` with `vendor`, `readiness`, `kpis`, `action_queue`, `events`, `empty_state`, `analytics_summary`, plus injected `onboarding_panel`, `is_pro`, `pro_upgrade_url`, etc.
- **Maps cleanly:**
  - `mel-live-ops__hero` (welcome / CTA section already there as `.mel-vendor-dashboard-v2__hero`)
  - `mel-live-ops__metric-board` (KPIs already there as `.mel-kpi-card` / `.mel-vendor-kpi-strip`)
  - `mel-live-ops__quick-bar` (action queue is already a quick-action concept)
- **Does not map:**
  - Multi-event roster (dashboard is across events; ops is single-event)
  - Pro upgrade prompt
  - Onboarding panel
- **Backend deps:** view-model already has all data; no new query work.
- **Slice size:** **M.** Need a new content template plus a `_dashboard-live-ops.scss` partial that re-skins the existing `.mel-vendor-dashboard-v2__*` BEM family with live-ops typography. Or replace with `.mel-live-ops__*`.
- **Risk:** dashboard has many preprocess hooks across modules (`myeventlane_event`, `myeventlane_dashboard`, `myeventlane_automation`, `myeventlane_pro`, `myeventlane_launch`, `myeventlane_help_centre`, `myeventlane_donations`). If we rename the theme hook, every preprocess must move. If we keep the theme hook and just rewrite the template, preprocess hooks stay intact. **Recommendation:** keep `myeventlane_vendor_dashboard` theme hook, rewrite template only.

### 3.2 Event Attendees (`/vendor/events/{node}/attendees`)

- **Controller:** `myeventlane_vendor/src/Controller/VendorEventAttendeesController.php`.
- **Content template:** `themes/custom/myeventlane_vendor_theme/templates/event/attendees.html.twig`.
- **Current data shape:** `event`, `attendees`, `summary`, `is_tickets_enabled`, `public_event_url` (assembled by the controller).
- **Maps cleanly:**
  - `mel-live-ops__metric-board` (summary already has total / checked_in / ticket / capacity).
  - `mel-live-ops__attendees` + `mel-live-ops__attendee-row` (this is literally what the page is).
  - `mel-live-ops__chip` for source / status badges.
- **Does not map:**
  - Bulk export controls (CSV buttons should stay in a header action region).
  - Per-row order link / additional data fields (need to fit into row card layout).
- **Backend deps:** view-model needs to enrich each row to the same shape as `MelAttendeeOperationsPresenter::buildEventViewModel()` produces. **Strong recommendation:** replace this controller with the existing `MelVenueOperationsViewModelBuilder::build()` for the metrics block, and reuse `MelAttendeeOperationsPresenter` for rows. **This is the highest-leverage slice — it converges Attendees with Operations using the canonical presenter we already have.**
- **Slice size:** **M.** ~1 controller change + 1 template rewrite + reuse of existing services. No new SCSS needed (live-ops primitives already cover it).
- **Risk:** Attendees today shows `phone`, `additional_data`, `order_link`, `ticket_code` — these are not all in the operations row VM yet. View-model needs additive fields, **not** replacement. Adding new fields is forward-compatible.

### 3.3 Event Analytics (`/vendor/events/{event}/analytics`)

- **Controller:** `myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php`.
- **Content template:** `themes/custom/myeventlane_vendor_theme/templates/event/analytics.html.twig`.
- **Maps cleanly:**
  - `mel-live-ops__metric-board` for KPIs (revenue, RSVPs, etc.).
  - `mel-live-ops__hero` for headline / period selector.
- **Does not map:**
  - Chart.js canvases (need their own panel container — `mel-live-ops__panel` does not exist; would need a new partial).
- **Slice size:** **L.** Charts + period filters + per-cohort breakdown. New chart panel partial would be the first new SCSS addition; explicit plan needed.
- **Recommendation:** **DEFER until after Attendees + Dashboard slices land**, so chart panel pattern can be designed against three real consumers (Analytics, Dashboard, Overview).

### 3.4 Event Orders (`/vendor/events/{event}/orders` and `/orders/{order}`)

- **Controllers:** `VendorEventOrdersController`, `VendorEventOrderViewController`.
- **Content templates:** `event/orders.html.twig`, `event/order-view.html.twig`.
- **Maps cleanly:**
  - `mel-live-ops__metric-board` (gross / refunds / fees).
  - `mel-live-ops__attendees`-shaped row pattern works for orders too.
- **Does not map:**
  - Order detail (line items, refund actions, Stripe links). Needs a structurally different "panel + list" layout.
- **Slice size:** **L for both pages.** Detail view especially — touches refunds + Stripe payment touchpoints. **DO NOT TOUCH REFUND/STRIPE BUTTONS without product sign-off** per Stripe Connect safety rule.

### 3.5 Event Tickets (`/vendor/events/{event}/tickets`)

- **Controller:** `VendorEventTicketsController` + `myeventlane_tickets/VendorEventTicketsBaseController`.
- **Content template:** `event/tickets.html.twig` and `myeventlane_tickets`-owned templates.
- **Maps cleanly:** metric board for sold / available / revenue per ticket type.
- **Does not map:** ticket creation / pricing forms — these are wizard surfaces with their own form chrome.
- **Slice size:** **M for read view, L if forms are included.** Recommend split: read view first.

### 3.6 Event RSVPs (`/vendor/events/{event}/rsvps`)

- **Controller:** `VendorEventRsvpController`.
- Mirrors Attendees pattern. **Slice size:** **M**, very similar to 3.2.

### 3.7 Event Settings (`/vendor/events/{event}/settings`)

- **Slice size:** **M-L.** Settings is a form surface — mostly Drupal form API rendering. Live-ops primitives don't apply directly; this would mostly be typography + spacing alignment, not card / metric reuse. **Recommend deferring** — settings page is functionally clear today and has lowest user-facing visibility per session.

### 3.8 Event Overview / Mission Control

- **Controller:** `VendorEventOverviewController`.
- Already uses the `mel-event-workspace-v2` rich layout (readiness / next action / metrics / shortcuts) — closest of any page to live-ops in spirit but different vocabulary.
- **Slice size:** **M.** Mostly a vocabulary alignment between `mel-event-workspace-v2__*` and `mel-live-ops__*`. **Risk:** this template is widely shared; aligning it touches every event-scoped page that goes through `mel_event_workspace`. **Recommend doing this slice EARLY** because it's the outer event chrome — it improves everything downstream "for free".

### 3.9 Payouts / Audience / Events list / Messaging brand (Console-shell pages)

- These use `myeventlane_vendor_console_page` shell, not `mel_event_workspace`.
- The console shell (`templates/layout/console-page.html.twig`) is already 47 lines and very clean. The convergence here is mostly **inside** the page body, where each page renders its own table / list.
- **Slice size each:** **S–M.** Audience and Events list are the highest-traffic; Messaging brand and Boost panel are lower priority.

### 3.10 Profile

- The user query mentions "profile" but there is no top-level vendor profile route in `myeventlane_vendor` controllers. The closest is the user account page, which is rendered by Drupal core under the public theme (`myeventlane_theme`), not the vendor theme. **Action item:** confirm with product whether "profile" means user account, vendor entity edit, or something else, before sizing.

## 4. Proposed slice order

Each slice is a separate task. Each slice ends at: build green, lint green, governance tests green, no other page regressed.

1. **Slice 1 — Issue 2 fix (this turn).** Manual check-in stays inside Operations. Done.
2. **Slice 2 — Event Attendees → live-ops design** (3.2). Highest leverage: reuses `MelAttendeeOperationsPresenter` + `MelVenueOperationsViewModelBuilder`. No new SCSS. ETA M.
3. **Slice 3 — Event Overview → live-ops vocabulary alignment** (3.8). Improves the outer event chrome for every event-scoped page in one stroke. ETA M.
4. **Slice 4 — Vendor Dashboard rewrite** (3.1). Keep theme hook, rewrite template only, preserve preprocess hooks. ETA M.
5. **Slice 5 — RSVPs** (3.6). Mirror of Attendees. ETA S–M.
6. **Slice 6 — Event Tickets read view** (3.5). ETA M.
7. **Slice 7 — Audience + Events list (console-shell pages)** (3.9). ETA S each.
8. **Slice 8 — Analytics + chart panel partial** (3.3). NEW SCSS partial introduced here, after three consumers exist. ETA L.
9. **Slice 9 — Orders read view** (3.4 part 1). ETA M.
10. **Slice 10 — Order detail / refund view** (3.4 part 2). **REQUIRES STRIPE / REFUND PRODUCT SIGN-OFF.** ETA L.
11. **Deferred:** Event Settings (3.7), Profile (3.10), Messaging brand, Boost panel.

## 5. Hard constraints across every slice

These are non-negotiable, lifted from `.cursor/rules/` and `docs/adoption/mel-live-operations-convergence-map.md`:

1. **No new check-in writers.** `MelAttendeeCheckinManager` stays the only authority. Door Mode + public `/event/{event}/checkin` paths are untouched.
2. **No staff/public help leakage.** Help Centre / playbooks audience boundaries preserved (workspace rule `mel-workflow-verification.mdc`).
3. **No Stripe Connect / payout / refund behaviour change without explicit product sign-off** (`mel-stripe-connect-safety.mdc`).
4. **No new SCSS root partials that duplicate `_live-operations.scss`.** Each slice either re-skins existing classes or extends `_live-operations.scss` additively.
5. **Server-side access control unchanged.** Every controller continues to call `assertEventOwnership()` / `MelAttendeeOperationsAccess`.
6. **No `\Drupal::service()` introductions in business logic** for new code. Use DI in `*.services.yml`.
7. **Each slice must run `composer governance:test`, `npm run mel:lint`, and `npm run mel:build` green before merge.** Add tests for new view-model logic per slice.

## 6. Backend / view-model work required before any UI slice

- Confirm `MelAttendeeOperationsPresenter::buildEventViewModel()` row schema covers everything Attendees template needs today (phone, additional_data, order_link, ticket_code) — additive fields only, no breaking changes.
- Confirm dashboard view-model has metric data shaped for `mel-live-ops__stat-card` (total / in / remain / ready / blocked) at the **vendor** level, not the event level. May need a new aggregator or a wrapper that reuses per-event `readinessForEvent()` and sums.

## 7. Out-of-scope explicitly

- New design tokens (typography scales, color palettes). Tokens stay in `web/themes/custom/myeventlane_theme/src/scss/tokens/`.
- New module additions. All work is within `web/modules/custom/`, `web/themes/custom/myeventlane_theme/src/scss/`, and `web/themes/custom/myeventlane_vendor_theme/src/scss/`.
- Public theme refactor. Convergence is vendor-side only.
- Anything that would touch `config/sync` (no config changes for visual convergence).

## 8. Validation plan per slice

Each slice ends with this evidence pasted into the slice's PR description:

- `git status --short` (touched files only).
- `composer validate` ✓
- `composer governance:test` ✓ (with new tests for view-model changes).
- `composer governance:audit` ✓
- `npm run mel:lint` ✓
- `npm run mel:build` ✓
- `ddev drush cr` (run locally; node by reviewer).
- Manual screenshots: before / after of the affected page, on mobile and desktop breakpoints.
- Confirmation that **other** vendor pages are unchanged.

---

## Sign-off needed before Slice 2 starts

- [ ] Product confirms slice order in §4.
- [ ] Confirmation that "profile" in original ask = user account / vendor entity edit / other.
- [ ] Stripe/refund slices (10) explicitly held until product sign-off.
- [ ] Confirmation that we keep theme hook names where they exist (preprocess hook chains preserved).
