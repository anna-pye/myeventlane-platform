# Task 7 — Vendor dashboard, attendee/order visibility audit

**Branch:** `cursor/onboard-storage-fix-128b4`  
**Audit date:** 2026-04-29  
**Scope:** Diagnostic verification only (no code changes). Stripe onboarding, checkout architecture, payment gateway config, and config export were not modified.

---

## Phase 1 — Preflight

### Commands run

- `git branch --show-current`
- `git status --short`
- `git log -10 --oneline`
- `git log -5 --oneline -- docs/audits/mel-booking-checkout-verification.md`
- `git ls-files docs/audits/mel-booking-checkout-verification.md`
- `composer validate`
- `ddev drush cr`

### Results

| Item | Value |
|------|--------|
| Branch | `cursor/onboard-storage-fix-128b4` |
| Latest commit | `39f40eae` — `fix(event-studio): update ticket reference reconciliation logic` |
| Dirty files | None (clean working tree at audit time) |
| `composer.json` | Valid |

### Task 6 / booking checkout audit document

- **Path:** `docs/audits/mel-booking-checkout-verification.md`
- **Tracked:** Yes  
- **Last commit touching file:** `39f40eae` (same as HEAD at audit time). The change list for that commit includes Event Studio ticket work; the booking verification doc is included in the repository history.

---

## Phase 2 — Routes and controllers (summary)

### Main vendor dashboard

| Route name | Path | Controller | Access |
|------------|------|------------|--------|
| `myeventlane_vendor.console.dashboard` | `/vendor/dashboard` | `myeventlane_vendor.controller.vendor_dashboard:dashboard` → [`VendorDashboardController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php) | `myeventlane_vendor.access.vendor_console:access` plus **permission** `access vendor dashboard` for this route (see [`VendorConsoleAccess`](web/modules/custom/myeventlane_vendor/src/Access/VendorConsoleAccess.php)) |
| `myeventlane_vendor.shell.dashboard` | `/dashboard` | `entrypointRedirect` | Redirect |
| `myeventlane_vendor.shell.vendor_root` | `/vendor` | `entrypointRedirect` | Redirect |

**Event card / KPI data (source code):** [`VendorDashboardController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php) composes UI data using services including [`RsvpStatsService`](web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php), [`TicketSalesService`](web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php), [`VendorKpiService`](web/modules/custom/myeventlane_vendor_analytics), [`MetricsAggregator`](web/modules/custom/myeventlane_vendor/src/Service/MetricsAggregator.php), optional [`VendorMetricsReadModel`](web/modules/custom/myeventlane_domain_events), [`EventRepository`](web/modules/custom/myeventlane_event_studio) (Event Studio read model).

### Event workspace (ownership enforced in controller)

| Route name | Path | Notes |
|------------|------|--------|
| `myeventlane_vendor.console.event_workspace` | `/vendor/events/{event}` | [`EventWorkspaceController`](web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php) — `assertEventOwnership($event)` |
| `myeventlane_vendor.console.event_orders` | `/vendor/events/{event}/orders` | [`VendorEventOrdersController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventOrdersController.php) — ownership + order items scoped to event |
| `myeventlane_vendor.console.event_order_view` | `/vendor/events/{event}/orders/{order}` | [`VendorEventOrderViewController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventOrderViewController.php) — ownership + store/item assertions |
| `myeventlane_vendor.console.event_rsvps` | `/vendor/events/{event}/rsvps` | [`VendorEventRsvpController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventRsvpController.php) — `assertEventOwnership` |
| `myeventlane_vendor.console.event_analytics` | `/vendor/events/{event}/analytics` | [`VendorEventAnalyticsController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php) — `assertEventOwnership` + permission `use pro financial analytics` + `_myeventlane_pro_access` |

Shared helper: [`VendorConsoleBaseController::assertEventOwnership`](web/modules/custom/myeventlane_vendor/src/Controller/VendorConsoleBaseController.php) — allows `administer nodes`, **node author**, or users listed on **`field_event_vendor` → `field_vendor_users`**.

### RSVP module routes (parallel URLs)

| Route name | Path | Access |
|------------|------|--------|
| `myeventlane_rsvp.vendor_event_rsvps` | `/vendor/event/{event}/rsvps` | `_custom_access`: [`VendorEventAccess::checkAccess`](web/modules/custom/myeventlane_rsvp/src/Access/VendorEventAccess.php) |
| `myeventlane_rsvp.export_csv` | `/vendor/event/{event}/rsvps/export` | Same |
| Other RSVP vendor routes | check-in, PDF, scan | Same |

`VendorEventAccess` aligns with ownership + `manage own event rsvps` + admin permissions (`administer rsvps`, `administer nodes`).

### Attendees and sales (Commerce attendees)

| Route name | Path | Controller | Access |
|------------|------|------------|--------|
| `myeventlane_checkout_flow.vendor_attendees` | `/vendor/attendees` | [`VendorAttendeesController::dashboard`](web/modules/custom/myeventlane_checkout_flow/src/Controller/VendorAttendeesController.php) | `_permission: access content` + `checkAccess()` — admins with commerce/bypass **or** user with **store** via `myeventlane_checkout_flow.vendor_ownership_resolver` |

Stats when present: [`AttendeeEventStatsService::buildStatsForEvents`](web/modules/custom/myeventlane_checkout_flow/src/Service/AttendeeEventStatsService.php) — uses optional [`EventStatsService`](web/modules/custom/myeventlane_event), else `TicketSalesService::getSalesSummary`, `RsvpStatsService::getEventRsvpCount`, capacity.

### Customer “My tickets”

| Route name | Path | Access |
|------------|------|--------|
| `myeventlane_checkout_flow.my_tickets` | `/my-tickets` | [`MyTicketsController::checkAccess`](web/modules/custom/myeventlane_checkout_flow/src/Controller/MyTicketsController.php) |
| `myeventlane_checkout_flow.order_detail` | `/my-tickets/order/{commerce_order}` | [`MyTicketsOrderAccess::access`](web/modules/custom/myeventlane_checkout_flow/src/Access/MyTicketsOrderAccess.php) — Commerce `view` access + guest-email match |

### Checkout paragraph vendor exports

| Route name | Path | Notes |
|------------|------|--------|
| `myeventlane_checkout_paragraph.export_csv` | `/vendor/export-attendees/{event}/download` | [`AttendeeExportController`](web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php) — route allows `access content`; **controller** calls `access()` — **owner or `administer nodes` only** (no `field_event_vendor` team parity with `assertEventOwnership`) |
| `myeventlane_checkout_paragraph.vendor_attendees` | `/vendor/orders/{commerce_order}/attendees` | [`VendorOrderController`](web/modules/custom/myeventlane_checkout_paragraph/src/Controller/VendorOrderController.php) — **controller** `access()` allows if **any** order line’s variation `field_event` node owner equals account (admin bypass). Then **all** order rows rendered via `collectRows()` — see findings |

### CSV (Views-backed)

| Route name | Path | Notes |
|------------|------|--------|
| `myeventlane_views.attendee_csv` | `/dashboard/attendees/export` | [`AttendeeCsvController::handle`](web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php) — `_permission: access content`; download path loads `attendee_answer` paragraphs with `accessCheck(FALSE)` then **paragraph `view` access** per row; optional `?download_csv={nid}` filters by event via access resolver |

### Analytics (Pro module)

| Route name | Path | Access |
|------------|------|--------|
| `myeventlane_analytics.dashboard` | `/vendor/analytics` | `myeventlane_vendor.access.vendor_console:access` + `_myeventlane_pro_access` |
| `myeventlane_analytics.event` | `/vendor/analytics/event/{node}` | [`AnalyticsDashboardController::accessEvent`](web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php) — **owner** with `access analytics dashboard` **or** `administer event attendees`; **not** the same as `assertEventOwnership` (vendor team via `field_event_vendor` not covered here) |
| Export PDF/Excel under `/vendor/analytics/event/{node}/…` | Same `accessEvent` | |

### Other vendor dashboards (grep sample)

- `myeventlane_escalations_analytics.vendor_dashboard`: `/vendor/support/analytics` — `view vendor escalations` + Pro access  
- `myeventlane_reporting` vendor insights: `/vendor/insights` (see module routing)  
- **Check-in:** `myeventlane_checkin.*` under `/vendor/events/{node}/check-in/*` — verify `_custom_access` / ownership in those controllers when hardening  

`ddev drush route | grep -Ei "vendor|dashboard|attendee|rsvp|csv|export|analytics|orders|tickets"` was used to enumerate routes (admin Commerce order routes also appear in the filter).

### Files inspected (non-exhaustive list)

- [`myeventlane_vendor.routing.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml)
- [`myeventlane_rsvp.routing.yml`](web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml)
- [`myeventlane_checkout_flow.routing.yml`](web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.routing.yml)
- [`myeventlane_checkout_paragraph.routing.yml`](web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.routing.yml)
- [`myeventlane_views.routing.yml`](web/modules/custom/myeventlane_views/myeventlane_views.routing.yml)
- [`myeventlane_analytics.routing.yml`](web/modules/custom/myeventlane_analytics/myeventlane_analytics.routing.yml)
- Controllers and access classes referenced above  
- [`TicketSalesService`](web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php) (completed-order filtering for revenue/tickets)

---

## Phase 3 — Test entities (sanitized)

Queries used: Drush `php-eval` for vendors/events/orders; SQL only for **counts** on `rsvp_submission` (no personal fields exported).

### Sample vendors (recent by `changed`)

Examples: `vendor=52` with `field_vendor_store=50`; multiple “Test10” / “Organiser” fixtures with `uid` target IDs (see shell output during audit).

### Events checked

| NID | Owner UID | `field_event_vendor` | `field_event_type` | Published |
|-----|-----------|----------------------|--------------------|-----------|
| **1567** | 1 | 36 | paid | yes |
| **1540** | 1 | 1 | rsvp | yes |
| **1377** | 1 | 1 | rsvp | yes |

### Orders spot check (recent)

Examples: order `428` **completed**, line item `field_target_event=1567`, customer uid `72`; order `423` **draft**, same event `1567`; multiple completed orders for event `1547`, etc.

### RSVP counts (database)

Table: `rsvp_submission` (`event_id` column).

| event_id | Row count |
|----------|-----------|
| 1540 | 8 (all `status=confirmed`) |
| 1377 | 1 |
| 1567 | 0 |

Legacy `myeventlane_rsvp`: **0** rows for those `event_nid` values (counts query during audit).

---

## Phase 4 — Dashboard data reconciliation

### Event 1567 (paid test)

- **Completed order items** with `field_target_event=1567`: quantity **1** (order `428`, state `completed`).  
- **Draft** order `423`: qty **1** (same field) — must **not** count toward revenue/ticket sold metrics that use **completed** only.

**Code expectation:** [`TicketSalesService`](web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php) documents **completed** orders (`state === 'completed'`) for counts/revenue; draft excluded.

**Reconciliation:** For this snapshot, dashboard paid metrics for event **1567** should align with **1** completed ticket sold (not 2), unless another code path counts drafts—none found in `TicketSalesService` for completed aggregates.

### Event 1540 (RSVP test)

- **DB count:** 8 confirmed RSVPs (`rsvp_submission`, status `confirmed`).
- **Service expectation:** [`RsvpStatsService::getEventRsvpCount`](web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php) counts **`status = confirmed`** on `rsvp_submission` (then legacy table fallback).

**Reconciliation:** Expected RSVP total for dashboards using this method: **8** for event **1540**.

### Vendor-wide RSVP total caveat

`RsvpStatsService::getVendorRsvpCount(int $vendor_uid)` loads **published events where `uid = $vendor_uid`** only — it does **not** aggregate by **`field_event_vendor`** entity membership. Team accounts whose events are owned by another UID may see **under-counted** vendor RSVP totals compared to events they manage via vendor membership.

---

## Phase 5 — Access matrix (high level)

| Area | Mechanism | Vendor isolation | Admin/staff |
|------|-----------|------------------|-------------|
| `/vendor/dashboard` and most `/vendor/events/*` | `VendorConsoleAccess` + **`assertEventOwnership`** on event controllers | Owner + vendor entity users + `administer nodes` bypass | `administer nodes` bypass in many flows |
| `/vendor/event/{event}/rsvps*` (RSVP module) | `VendorEventAccess` | Same ownership model + permissions | `administer rsvps` / `administer nodes` |
| `/vendor/attendees` | Store resolver + vendor events list | Scoped to vendor store’s events (controller logic) | Commerce admin / bypass permissions |
| `/vendor/export-attendees/{event}/download` | Owner-only in controller | **Gap:** vendor **team** users not included unless owner | Admin via `administer nodes` |
| `/vendor/orders/{commerce_order}/attendees` | Owner match on **any** line item | **Risk:** if one order mixes events from different owners, UI still lists **all** rows—see P1 | Admin |
| `/dashboard/attendees/export` | Paragraph view access | Depends on `attendee_answer` access handler | Depends on handler |
| `/vendor/analytics/event/{node}` | `accessEvent`: **owner** + analytics permission | **Gap:** vendor **team** not covered by `accessEvent` | `administer event attendees` |
| `/vendor/events/{event}/analytics` (vendor console) | `assertEventOwnership` + Pro | Aligned with workspace | `administer nodes` via base |

---

## Phase 6 — Manual browser checks

**Status:** **Pending** (not executed in this automated audit session).

Recommended matrix (unchanged from task brief):

1. Vendor A: `/vendor/dashboard`, event workspace for owned events, RSVP/paid links, CSV exports, Event Studio edit URLs.  
2. Vendor B: deep-link to Vendor A URLs → expect deny or empty safe result.  
3. Admin/staff: admin console and intended oversight routes.  
4. Anonymous: vendor routes → login/forbid.  
5. Customer: `/my-tickets` and order detail.

### Watchdog sample (`ddev drush ws --count=120 | grep -Ei "vendor|dashboard|…"`)

Observed lines related to orders/attendees/checkout (informational/errors from commerce/checkout flows); nothing proving cross-vendor access. Cron/session noise appeared unrelated to this audit.

---

## Phase 7 — Findings classification

### P0

**None confirmed** from code review + local DB spot checks. Anonymous users are blocked from `/vendor/*` by [`VendorConsoleAccess`](web/modules/custom/myeventlane_vendor/src/Access/VendorConsoleAccess.php) (anonymous forbidden on vendor paths). No evidence in this run that another vendor can load `/vendor/events/{event}` for events they do not own (controller throws `AccessDeniedHttpException`).

### P1

1. **Vendor team vs owner parity:** [`AttendeeExportController::access`](web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php) and [`AnalyticsDashboardController::accessEvent`](web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php) use **node author** (or admin), **not** `field_event_vendor` membership — inconsistent with `assertEventOwnership`. Vendor team members may be denied analytics/export despite having workspace access (or conversely export only works for owner—confirm product intent).

2. **`VendorOrderController` multi-line orders:** [`VendorOrderController::access`](web/modules/custom/myeventlane_checkout_paragraph/src/Controller/VendorOrderController.php) grants access if **any** line references an event owned by the user; [`collectRows`](web/modules/custom/myeventlane_checkout_paragraph/src/Controller/VendorOrderController.php) returns **all** ticket holders for **all** lines. If the platform ever creates **one order spanning events with different owners**, this could expose attendee PII across organisers. **Mitigation proof:** confirm Commerce/checkout rules forbid mixed-owner events per order; if not, tighten filtering.

3. **`RsvpStatsService::getVendorRsvpCount`:** Uses **`uid` on published nodes** only — vendor-team-operated events owned by another UID may omit RSVP totals from vendor-wide aggregates.

4. **UID 1-only assumptions:** Not pervasive on vendor routes; `VendorConsoleAccess` uses permissions and onboarding state. **`administer nodes`** remains a broad bypass (documented).

### P2

1. **Dual RSVP entry points:** `/vendor/events/{event}/rsvps` (vendor module) vs `/vendor/event/{event}/rsvps` (RSVP module) — same intent; operators may confuse URLs.  
2. **`AttendeeCsvController`:** Loads all `attendee_answer` IDs then filters in PHP — potential **performance** concern at scale (not a confidentiality finding if access checks hold).  
3. **Copy/UI:** Stripe status panel and dashboard labelling were **not** browser-verified here.

---

## Recommended next task

- **No P0 and no proven cross-vendor leak in this pass:** proceed to **Task 8 — Help centre and staff-only access verification** as scoped in the task brief.

- If product requires **aligned access** for vendor team on analytics and checkout-paragraph exports: open **Task 7B — Align vendor-team access with `assertEventOwnership` on analytics and attendee exports** (narrow scope; touch only access callbacks / export controllers listed above).

---

## Commands reference (audit execution)

```bash
git branch --show-current
git status --short
git log -10 --oneline
git log -5 --oneline -- docs/audits/mel-booking-checkout-verification.md
composer validate
ddev drush cr
ddev drush route | grep -Ei "vendor|dashboard|attendee|rsvp|csv|export|analytics|orders|tickets"
# php-eval snippets: vendors, events, orders (see Phase 3)
ddev drush sqlq "SELECT event_id, COUNT(*) FROM rsvp_submission WHERE event_id IN (1540,1377,1567) GROUP BY event_id"
ddev drush sqlq "SELECT status, COUNT(*) FROM rsvp_submission WHERE event_id=1540 GROUP BY status"
ddev drush ws --count=120 | grep -Ei "vendor|dashboard|attendee|rsvp|csv|export|order|ticket|access denied|forbidden|error|exception"
```

---

## Files changed

- **Added:** `docs/audits/mel-vendor-dashboard-attendee-order-visibility.md` (this document).

No application code or configuration was modified for this audit.
