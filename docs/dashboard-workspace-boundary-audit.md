# Dashboard / Event Workspace Boundary Audit

Date: 2026-05-10

Scope: `/vendor/dashboard` organiser mission control and `/vendor/events/{event}` event workspace.

## Code Owners Inspected

- Dashboard controller: `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`
- Dashboard view model: `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`
- Dashboard template: `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`
- Event workspace controller: `web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php`
- Event workspace view model: `web/modules/custom/myeventlane_vendor/src/Service/VendorEventWorkspaceViewModelBuilder.php`
- Event workspace template: `web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig`
- Readiness/lifecycle source: `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php`

## Ownership Matrix

| Capability | Dashboard | Event Workspace | Shared | Wrong Location |
| --- | --- | --- | --- | --- |
| Organiser identity | `VendorDashboardViewModelBuilder::buildVendorPayload()` provides organiser/vendor name, profile URL, settings URL. | Event workspace receives a single event, not organiser identity as the primary surface. | Vendor shell/chrome. | Event workspace should not become organiser home. |
| Mission Control hero | Dashboard template owns organiser-level hero using `vendor`, `organiser_overview`, `organiser_actions`, and `hero_shell_hint`. | No. Workspace hero owns the event title/status/date/type. | Shared live-ops visual primitives only. | Previous dashboard hero used `current_event` as the dominant surface. |
| Current event hero | Not owned. `current_event` may remain as compatibility data but must not drive the dashboard hero. | Workspace owns single-event hero and controls. | Event rows can link to workspace. | Dashboard hero dominance was wrong. |
| Event readiness | Dashboard should not present event readiness stacks. It may show compact attention shortcuts from existing rows. | `VendorEventWorkspaceViewModelBuilder::buildReadinessItems()` owns event title/date/booking/published/banner readiness for the single event. | `MelReadinessHelper` vocabulary. | Event readiness details on dashboard. |
| Organiser readiness | Dashboard owns vendor profile, public profile, Stripe readiness via `buildReadiness()`. | No. | `MelReadinessHelper` labels. | Event workspace should not become account setup home. |
| Lifecycle guidance | Dashboard may only use organiser-level lifecycle summaries as secondary account guidance. | `vendorEventWorkspaceLifecycleSummary()` owns event lifecycle, publishing, visibility, promotion context. | `MelReadinessHelper` methods. | Event-specific lifecycle stacks on dashboard. |
| Operational readiness | Dashboard owns account-level operational awareness: profile, payouts, first event, cross-event state. | `vendorEventWorkspaceOperationalSummary()` owns event booking/attendee/order state. | `MelReadinessHelper` copy and severity patterns. | Event operational cards on dashboard. |
| Action queue | Dashboard owns organiser priority action from `VendorActionQueueBuilder`. | Workspace owns `next_action` for the current event. | Button/card primitives. | Event next-action should not replace dashboard mission control. |
| Events needing attention | Dashboard owns compact cross-event cards derived from existing event rows and `attention_reasons`. | Workspace owns resolving each event's detail after the card is opened. | Event row links/status labels. | Large single-event dashboard module. |
| Upcoming events | Dashboard owns scannable multi-event strip from `model.upcoming_events`, now including all non-past rows in existing ordering. | Workspace does not list all organiser events. | Event row state chips and workspace links. | Hiding the first/focus event from upcoming awareness. |
| Activity stream | Dashboard owns sparse operational activity from existing dashboard activity payloads and event summaries. | Workspace owns event-specific metrics, attendee links, check-in, orders, RSVPs. | Booking/RSVP/order services may feed both builders through existing methods. | Fake social/noisy feeds, or event-only activity as dashboard primary. |
| Event summaries | Dashboard owns compact rows/cards for cross-event awareness. | Workspace owns detailed event readiness, metrics, shortcuts, alerts. | Existing event row payloads. | Event-specific setup guidance on dashboard. |
| Organiser KPIs | Dashboard owns organiser metrics via `MetricsAggregator`, `TicketSalesService`, `RsvpStatsService`, and decorated KPI strip. | Workspace owns event metrics from `TicketSalesService` and `RsvpStatsService` for the single event. | Metric visual primitives. | Event metrics as dashboard hero metrics. |
| Analytics summaries | Dashboard owns high-level availability/summary only. | Event analytics route owns detailed event analytics. | Existing analytics routes. | Detailed event analytics on dashboard. |
| Stripe/payout awareness | Dashboard owns high-level payout readiness/status. | Workspace does not change Stripe flow. | Existing Stripe readiness fields and dashboard controller status. | Stripe flow changes were out of scope. |
| Access control | Dashboard relies on vendor console access and route access checks for action URLs. | `EventWorkspaceController::workspace()` calls `assertEventOwnership()` before building the page. | `AccessManagerInterface` checks in builders. | UI-only hiding without server-side checks. |

## Findings

- The dashboard drift was primarily in `dashboard.html.twig`: the hero, metrics intro, and quick actions were driven by `model.current_event`.
- `VendorDashboardViewModelBuilder` already built organiser-aware primitives: `organiser_overview`, `upcoming_events`, `activity_items`, `action_queue`, and KPI summaries.
- `VendorEventWorkspaceViewModelBuilder` already owns event readiness, operational readiness, lifecycle guidance, next action, event metrics, tabs, and event actions.
- No new readiness system, lifecycle system, event query, Stripe flow, Commerce flow, AI flow, or access model is required.

## Boundary Correction Applied

- Dashboard hero now uses organiser identity, organiser overview, organiser actions, and account-level summary copy.
- Former current-event dominance is replaced by compact `attention_events` cards derived from existing event rows.
- Upcoming events are promoted as a primary multi-event strip and no longer skip the former focus event.
- Event workspace presentation is strengthened with a compact event operational state strip using existing status, type, readiness score, check-in, and preview payloads.
