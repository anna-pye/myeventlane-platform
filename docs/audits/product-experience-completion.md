# Product Experience Completion Pass — Audit

**Date:** 2026-06-23
**Branch:** `feature/mel-workflow-trust-confirmation`
**Scope:** Consolidated completion pass across Workflow, Trust & Conversion, Confirmation & First Event, and Event Health & Insights audits.
**Discipline:** Repository-first. No code in this phase. Findings flagged **CONFIRMED** (verified in repo) or **NEEDS-CONFIRMATION** (requires a runtime/usage check before any change).

---

## 0. Executive summary

MEL is **already far along** on every primary objective. The guidance, trust, and continuity layers are built and well-structured:

- **Organiser guidance:** `GrowthInsightService` (deterministic, human, action-linked cards), `VendorDashboardViewModelBuilder` empty states ("Create your first event").
- **Customer reassurance/continuity:** `MelCustomerContinuityPresenter` (governed CTA ordering for RSVP thank-you + checkout completion, single primary action, calendar links, organiser-trust band).
- **Copy governance:** `MelReadinessHelper` centralises customer/organiser microcopy (no scattered strings).
- **Mobile nav:** `MobileBottomNavigationBuilder` is a single canonical model (routes/active-state in PHP, labels/icons in Twig), correctly suppressed on checkout.
- **Insights:** event-health line, empty states, chart text-alternative, and dashboard growth cards were added in the Event Health pass (commits `ba54344c7`, `cdf702779`).

**Therefore this is a *completion* pass, not a build.** The remaining genuine work is: (1) resolve duplicate header/drawer template sets, (2) verify single navigation model end-to-end, (3) close a small number of organiser/empty-state guidance gaps, (4) WCAG 2.1 AA verification with targeted fixes. Most value is **verification + small cleanups**, not new components.

---

## 1. Systems reviewed (CONFIRMED inventory)

| System | Location | State |
|---|---|---|
| `GrowthInsightService` | `myeventlane_growth/src/Service/GrowthInsightService.php` | Mature; deterministic; CTAs to existing routes; config-driven thresholds (`myeventlane_growth.settings`). Reuse — do not extend. |
| `MelReadinessHelper` | `myeventlane_core/src/MelReadinessHelper.php` | Central microcopy source (customer + organiser). Reuse for any new copy. |
| `MelCustomerContinuityPresenter` | `myeventlane_surface/src/MelCustomerContinuityPresenter.php` | Governs post-RSVP and post-checkout CTA ordering; enforces one primary action; calendar + organiser-trust + discovery bridge. |
| `MobileBottomNavigationBuilder` | `myeventlane_front/src/Service/MobileBottomNavigationBuilder.php` | Canonical 5-tab model (Home, Events, Calendar, Community, Account/Sign in). Hidden on `commerce_checkout.*`. |
| Vendor dashboard view models | `myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php` + `VendorActionQueueBuilder`, `VendorEventWorkspaceViewModelBuilder`, `VendorEventIndexViewModelBuilder` | Provide `empty_state`, KPIs, action queue. |
| Analytics insights | `myeventlane_analytics` (event-health line, growth cards, chart table-alt) | Completed in prior pass. |

---

## 2. Phase 1 — current-state findings

### 2.1 Mobile header / drawer — **DUPLICATE TEMPLATE SETS (key finding)**

Two parallel sets exist:

| Path | Lines | Status |
|---|---|---|
| `templates/components/site-header/site-header.html.twig` | 126 | **ACTIVE** — registered in `hook_theme` (`myeventlane_theme.theme:478`, `template => 'components/site-header/site-header'`), preprocessed by `myeventlane_theme_preprocess_site_header`. |
| `components/site-header/site-header.twig` | 76 | **CANDIDATE DEAD** — no `.component.yml` (not a real SDC), no `.html.twig` (not a Drupal template), no `include`/`embed` references found. |
| `templates/components/mobile-drawer/mobile-drawer.html.twig` | 22 | **ACTIVE** (registered). |
| `components/mobile-drawer/mobile-drawer.twig` | 22 | **CANDIDATE DEAD** (same reasoning). |

- The `components/` dir (`browse, card-carousel, featured-events, hero, mobile-drawer, site-header, vibe-mixer`) contains **no `.component.yml` files** → SDC is not in use; these bare `.twig` files are not loaded by Drupal.
- **NEEDS-CONFIRMATION:** the `main.scss` line `@use 'components/site-header'` refers to the **SCSS partial** `src/scss/components/_site-header.scss`, *not* the twig — unrelated; confirm the dead twigs have zero runtime role before removal.
- **Risk if left:** maintainers edit the wrong (dead) file — this exact failure mode already bit the Event Health pass (edited dead `.theme` instead of `.module`). High-value cleanup.

### 2.2 Bottom navigation — **single model CONFIRMED**

- One builder (`MobileBottomNavigationBuilder`), rendered via `mel_mobile_bottom_nav` through `templates/includes/mel-discovery-page-shell.html.twig:50` and discovery page templates. Active-state logic centralised. No duplicate bottom-nav builder found.
- **NEEDS-CONFIRMATION:** that the bottom nav and the drawer don't both expose Account + Create Event in a way that duplicates actions on the same viewport (see 2.3).

### 2.3 Duplicate actions — **NEEDS-CONFIRMATION**

- The active site-header (126-line) renders: burger/drawer, brand, primary nav, **Create Event button**, organiser context, cart, notifications, user menu. The bottom nav renders Account/Sign in. Potential overlap: Account in both drawer and bottom nav; Create Event in header and possibly drawer.
- **Action for implementation phase:** read the active header + drawer fully and map every CTA/account link; remove duplicates *only where the same action appears twice in the same breakpoint*. Do not delete; consolidate.

### 2.4 Organiser dashboard / empty states — **mostly CONFIRMED present**

- `dashboard.html.twig` has `empty_state` (title/message/url/action_label) with sensible defaults ("Create your first event…", "Welcome to your organiser dashboard").
- Growth cards render in a secondary panel ("Grow event guidance").
- **Gap (NEEDS-CONFIRMATION):** the per-state matrix in Phase 4 (no attendees / first attendee / first sale / no insights) — verify each has a "what happened / what it means / what to do" line. Some states may fall back to a generic empty rather than a stateful message.

### 2.5 Customer dashboard / confirmation — **CONFIRMED strong**

- `MelCustomerContinuityPresenter` already enforces one primary action, ordered secondary actions, calendar links, organiser-trust band, and guest-vs-authenticated differences. No gaps identified; do not touch without a specific defect.

### 2.6 Insights pages — **CONFIRMED (prior pass)**

- Event-health line, empty states, chart table-alternative, dashboard growth cards delivered. Currency still shows a hardcoded `$` (documented pre-existing; out of scope — no Commerce changes).

### 2.7 Help links / terminology — **NEEDS-CONFIRMATION**

- Validation step requires `rg "Dashboard|Vendor|Analytics|Promotion"`; remaining user-facing matches to be enumerated and assessed against the agreed event-language (Organiser, Insights, Grow event, Home/Dashboard). Prior commits (`align organiser insights terminology`, `Home→Dashboard`) already moved much of this.

---

## 3. Phase 2 — visitor experience (to verify, not assume)

- Homepage CTA clarity, Create Event visibility, "Organisers" terminology, search visibility, mobile menu clarity — **verify against live pages**; use existing routes only; create no new menus.
- Duplicate account links / navigation — fold into the 2.3 mapping.

## 4. Phase 3 — event health (reuse only)

- Reuse `GrowthInsightService` + existing growth cards + existing calculations. Approved messages ("Your event is gaining interest / live / nearly full", "Share your event…") **only where existing logic supports them** (e.g. `sales_velocity.trend`, `percent_sold`, published state, view counts). No new scoring/thresholds/services.

## 5. Phase 4 — organiser state matrix (close gaps)

| State | Current | Target (existing routes) |
|---|---|---|
| No events | ✅ empty_state "Create your first event" | Confirm CTA → Create event |
| Published event | partial | "Your event is live." + Share/Grow |
| No attendees | NEEDS-CONFIRMATION | "No bookings yet — normal for a new event." + Share |
| First attendee | NEEDS-CONFIRMATION | "You have your first attendee." + View attendees |
| First sale | NEEDS-CONFIRMATION | acknowledgement + View attendees/Insights |
| No insights | ✅ (analytics empty states) | "Nothing needs attention right now." |

Each must answer what happened / what it means / what next, using existing routes (Create event, View event, View attendees, Grow event, Insights).

## 6. Phase 5 — mobile (verify single model + a11y)

- One navigation model (builder) — CONFIRMED for bottom nav; verify drawer doesn't fork it.
- 44×44 touch targets, keyboard access, focus visibility — **verify in CSS/templates**, fix only confirmed failures.
- Dead code removal (2.1) only after confirmation.

## 7. Phase 6 — accessibility (WCAG 2.1 AA targets)

- Chart alternatives — ✅ added for analytics; verify no other chart lacks a text alternative.
- Colour-independent indicators — ✅ trend uses word+caption; verify badges/status chips elsewhere.
- Visible focus, heading hierarchy, keyboard nav, reduced motion — **verify**; `utilities/_motion.scss` already has reduced-motion support per design system.

---

## 8. Prompt self-review — missing concerns & risks

**Missing Drupal concerns**
- **Theme registry / template resolution** — the prompt doesn't mention that editing `components/*.twig` vs `templates/components/*.html.twig` resolves differently; this is the #1 footgun here. Plan must verify the live template before editing.
- **Cache & cacheability** — any change to header/drawer/nav must preserve cache contexts (`user`, `route`, `url.path`) and tags; menu/nav is render-cached. Plan must not introduce uncacheable per-request logic.
- **Translation** — all copy via `t()` / `MelReadinessHelper`; no raw strings.
- **Render arrays over markup** — keep logic in builders/preprocess, not Twig (CLAUDE.md).

**Missing Commerce concerns**
- Confirmation surfaces read order/ticket data — must continue using the presenter's access-checked URL building; never query orders in Twig. Bottom nav already hides on `commerce_checkout.*` — preserve that.
- "Sold out / waitlist / guest vs signed-in purchase" QA states touch Commerce-adjacent display only; ensure no capacity/stock/price logic is read or recomputed in presentation.

**Missing security concerns**
- Organiser guidance/insights must remain behind existing access checks (`access analytics dashboard`, vendor workspace parity). Growth cards already gate on Pro where relevant.
- Empty-state CTAs must use access-checked URL builders so we never render a link to a route the user can't reach (the analytics pass used `accessManager->checkNamedRoute`).
- No exposure of attendee/customer/ticket data in any new state message (use counts/labels, not PII).

**Missing accessibility concerns**
- Drawer is a `<details>`/`<summary>` disclosure — verify `aria-expanded`, focus trap behaviour, and Esc-to-close; verify the burger and bottom-nav links meet 44×44 and have visible focus.
- Heading order on dashboards (one `h1`, sequential `h2/h3`).
- The prompt omits **screen-reader live-region** needs for any AJAX (growth dismiss, readiness refresh) — verify existing `role="status"` usage is sufficient; add none new unless a gap is found.

**Deployment risks**
- Removing "dead" templates is irreversible in a deploy — gate on confirmed-unused (grep + theme registry + a render smoke test). The husky pre-commit runs `mel:lint` + `mel:build` + `drush cr`; a broken Twig/SCSS will block commit (good), but a *wrongly removed* live template would only surface at runtime.
- Config: report drift first; export nothing unless strictly required. No checkout/Commerce/menu config changes.

**Maintenance risks**
- The duplicate header/drawer sets are an active maintenance hazard (wrong-file edits). Resolving them is the highest-leverage maintenance win.
- Keep all new copy in `MelReadinessHelper` so it stays governed.

---

## 9. Improved plan (reviewed — for approval before execution)

Ordered by value/risk. Each step is presentation-layer, reuse-only, and individually validatable.

1. **Confirm & remove dead header/drawer twigs** (`components/site-header/site-header.twig`, `components/mobile-drawer/mobile-drawer.twig`) — after a registry + grep + render smoke test proves them unused. *Pure maintenance win; no UX change.*
2. **Map & de-duplicate nav actions** — read active header + drawer + bottom nav fully; remove only same-breakpoint duplicate Account/Create-Event actions. Preserve cacheability.
3. **Close organiser state-matrix gaps (Phase 4)** — add stateful "what/why/next" lines via `MelReadinessHelper` + existing routes, reusing `GrowthInsightService`/view-model data. No new services.
4. **Event-health messages (Phase 3)** — surface approved phrases only where existing data supports them.
5. **WCAG 2.1 AA verification + targeted fixes** — 44×44, focus visibility, heading order, drawer keyboard behaviour, chart alts. Fix only confirmed failures.
6. **Terminology sweep** — enumerate remaining user-facing `Dashboard|Vendor|Analytics|Promotion` matches; align per event-language; report-only where ambiguous.

**Out of scope (hard stop):** schema/entities/permissions/roles, Stripe, Commerce workflow/config, route renames, DB updates, new menus/components/services, new analytics, fabricated metrics.

**Validation per step:** `git status --short`, `composer validate --check-lock`, `ddev drush cr`, `npm run mel:lint`, `npm run mel:build`, `php -l` on changed PHP, plus a render smoke test for any template change.

---

## 10. Recommendation

Execute steps **1–3 first** (highest value, lowest risk: kill the maintenance hazard, de-duplicate nav, close guidance gaps), then **4–6** as a verification sweep. Do not proceed past any step whose assumption the repository fails to confirm.
