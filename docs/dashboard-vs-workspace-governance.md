# Dashboard vs Event Workspace Governance

## Dashboard Purpose

`/vendor/dashboard` is Organiser Mission Control. It answers what is happening across the organiser account, which events need attention, what recent operational activity occurred, whether bookings and payouts are healthy, and what the organiser should review next.

Canonical owner: `VendorDashboardController`, `VendorDashboardViewModelBuilder`, and `dashboard.html.twig`.

## Event Workspace Purpose

`/vendor/events/{event}` is the event operational home. It answers whether this event is ready, whether attendees are booking, whether check-in is ready, whether tickets/RSVPs are configured, whether publishing and visibility are healthy, and what this event needs next.

Canonical owner: `EventWorkspaceController`, `VendorEventWorkspaceViewModelBuilder`, and `mel-event-workspace.html.twig`.

## Organiser-Level Intelligence Rules

- Dashboard intelligence must be account-level or cross-event.
- Use existing dashboard KPIs, organiser overview rows, activity payloads, event summaries, and action queue items.
- Dashboard may link to an event workspace when an event needs attention, but the dashboard must not explain the full event readiness or lifecycle state inline.
- Dashboard payout copy must remain high-level and must not alter Stripe or Commerce flows.

## Event-Level Intelligence Rules

- Event readiness belongs in `VendorEventWorkspaceViewModelBuilder::buildReadinessItems()`.
- Event operational readiness belongs in `MelReadinessHelper::vendorEventWorkspaceOperationalSummary()`.
- Event lifecycle guidance belongs in `MelReadinessHelper::vendorEventWorkspaceLifecycleSummary()`.
- Event attendee, ticket, RSVP, order, check-in, analytics, publishing, visibility, and promotion details belong in the workspace or its child event routes.

## Activity Stream Rules

- Dashboard activity must stay sparse, operational, and meaningful.
- Valid dashboard activity includes booking, RSVP, check-in, event update, publish, Stripe readiness, refund/support, and comparable operational summaries when supplied by existing payloads.
- Do not create fake social feeds, noisy notification streams, or real-time dashboards.
- Event-specific activity details should link to the event workspace or event child route.

## Upcoming Event Rules

- Dashboard upcoming events are primary multi-event awareness.
- Cards should be compact: title, date, state, booking state, and quick workspace link.
- Use existing event ordering and existing event row state labels.
- Do not hide the first/focus event from upcoming awareness.

## Mission Control Principles

- The dashboard hero is organiser-level, not event-level.
- Prefer lightweight account summary: live events, upcoming events, booking activity, priority items, and payout readiness.
- The dashboard should be scannable on mobile: priority alert, mission control hero, organiser overview, activity, upcoming events, then secondary account details.
- Dashboard visual hierarchy should be lighter and overview-oriented.

## Workspace Principles

- The workspace hero is event-level and may be more detailed than the dashboard.
- Readiness, lifecycle, attendee state, check-in, publishing, visibility, ticketing, RSVP, promotion, and event metrics are allowed here.
- Reuse existing readiness and lifecycle payloads; do not duplicate the logic in Twig or another builder.
- Workspace visual hierarchy may be richer because the user has chosen a specific event.

## Mobile IA Rules

- Mobile dashboard order: priority alert, Mission Control hero, organiser overview strip, activity stream, upcoming events, then collapsed secondary details.
- Mobile dashboard event-level cards must remain compact, contextual, and horizontally scannable where appropriate.
- Mobile workspace order: event hero, event operational state, event alerts, next action, event readiness/lifecycle/metrics, tabs, shortcuts, then page content.
- Do not make dashboard and workspace look identical on mobile.

## Shared Payload Rules

- Shared visual primitives are allowed.
- Shared helper methods are allowed when they are already canonical, especially `MelReadinessHelper` and `EventStateResolver`.
- Event rows may be reused by the dashboard for shortcuts, state chips, and compact summaries.
- Do not duplicate event queries, readiness calculations, lifecycle calculations, Stripe calculations, Commerce calculations, or AI grounding.

## What Must Never Return To Dashboard

- Giant current-event hero surfaces.
- Giant single-event imagery.
- Event readiness stacks.
- Event lifecycle stacks.
- Event-level attendee readiness detail.
- Event-specific ticket, visibility, publishing, promotion, or check-in guidance.
- Detailed event analytics or momentum panels.

## What Must Never Leave Event Workspace

- Event readiness presentation.
- Event lifecycle presentation.
- Event next action.
- Ticket/RSVP setup detail.
- Publishing and visibility guidance.
- Check-in readiness.
- Attendee/order/RSVP event metrics.
- Event operational shortcuts and child-route navigation.

## Operational Boundary Rules

- Dashboard asks: what needs attention across the organiser account?
- Workspace asks: what does this event need?
- If a card needs more than one sentence of event-specific explanation, it belongs in the workspace.
- If a dashboard link opens an event, the destination should be the workspace or an event child route guarded by existing access checks.

## Security And Access

- Do not rely on UI hiding for vendor isolation.
- Preserve `EventWorkspaceController::workspace()` ownership enforcement.
- Preserve route access checks when creating dashboard or workspace links.
- Do not introduce new access rewrites as part of IA work.
