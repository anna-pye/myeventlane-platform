# Mission Control Dashboard Governance

The organiser dashboard is Mission Control: a calm operational home that keeps one event in focus while showing lightweight awareness across the organiser's event set.

It is not an analytics wall, onboarding wizard, admin console, lifecycle dashboard, Stripe console, Commerce console, refund console, or AI surface.

# Canonical Dashboard Hierarchy

1. Priority Attention
2. Current Event Hero
3. Organiser Overview Strip
4. Quick Metrics
5. Quick Actions
6. Operational Activity Stream
7. Upcoming Events
8. Expandable Operational Panels

# Dashboard Rules

- Keep one primary event focus.
- Keep multi-event awareness lightweight and compact.
- Use `VendorDashboardViewModelBuilder` as the canonical model owner.
- Use `VendorActionQueueBuilder` for priority attention.
- Use `MelReadinessHelper` for readiness and lifecycle language.
- Use `EventStateResolver`, `TicketSalesService`, `RsvpStatsService`, and existing event row payloads for event state, booking, RSVP, attendee, and capacity signals.
- Keep deep analytics off the dashboard homepage.
- Keep lifecycle and readiness intelligence collapsed.

# Priority Attention

Priority attention shows one item only. It must come from the existing action queue and must not rebuild scoring in Twig.

Secondary action queue items belong inside expandable operational panels.

# Current Event Hero

The hero shows the primary event selected by existing dashboard event ranking. It may show title, date, status, booking state, attendee state, image, and event-level actions.

It must not show readiness percentages, readiness rings, giant setup walls, analytics graphs, or a full event roster.

# Organiser Overview Strip

The organiser overview strip provides compact cross-organiser awareness. It may include live events, draft events, upcoming events, bookings, priority items, and payout readiness when those values are available from existing dashboard payloads.

It must remain:

- Compact.
- Count-based or short state-based.
- Operational.
- Glanceable.
- Derived from existing model data.

It must not introduce new event queries, refund engines, payout engines, charts, trend graphs, or analytics cards.

# Quick Metrics

Quick metrics are lightweight dashboard signals. Prefer current-event metrics when a focus event exists; otherwise use existing decorated vendor KPIs.

Graphs, trend analysis, cohort analysis, and deep revenue intelligence belong on analytics surfaces.

# Quick Actions

Quick actions stay scoped to the current event. They must continue to use existing route/access-safe URLs from the dashboard model.

# Operational Activity Stream

The activity stream is sparse operational intelligence sourced from existing event summary payloads and readiness state. It is not a social feed, notification centre, infinite timeline, polling surface, or alert system.

If no canonical activity service exists, the dashboard may derive short messages from existing event summaries only.

# Upcoming Events

Upcoming events are shown as a compact row. Each item may include title, date, status, booking state, lightweight metrics, and an event link from the existing event row.

The row must not duplicate event teaser systems, use large cards, or expose analytics.

# Expandable Operational Panels

Readiness details, operational readiness, lifecycle guidance, secondary actions, event roster, promotion guidance, contribution prompts, billing nudges, and boost surfaces must stay secondary.

Use existing details/accordion patterns and MEL live-ops styling.

# Contextual Intelligence Boundaries

- Event editor owns publishing guidance, visibility guidance, and ticket setup guidance.
- Attendees owns check-in readiness, attendee momentum, and door guidance.
- Promote event owns discovery guidance, banner suggestions, and momentum guidance.
- Analytics owns deep metrics, graphs, and trend analysis.
- Payouts/settings/Stripe surfaces own payout detail.
- Event workspace refund requests and escalation refund summaries own refund detail.
- Dashboard owns operational awareness only.

# Mobile Mission Control

On mobile, the initial dashboard should show:

1. Priority alert
2. Current event
3. Organiser overview strip
4. Quick actions
5. Activity stream

Everything else should be collapsed, compact, or horizontally scrollable. Do not stack large cards into long-scroll fatigue.

# Visual Hierarchy

Use white surfaces, subtle borders, restrained pastel accents, and compact rhythm. Pastels should indicate action, alert, or active state, not every large container.

Avoid oversized pastel blocks, competing card containers, oversized event cards, and giant whitespace gaps.
