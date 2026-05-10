# Lifecycle Intelligence Guidance Governance

## Lifecycle Intelligence Principles

Lifecycle intelligence is presentational. It explains existing organiser and event state with calm, concise guidance. It does not enforce publishing, calculate checkout availability, run analytics, send notifications, create onboarding flows, or change AI behaviour.

## Lifecycle Stages

The canonical lifecycle stages are Event Setup, Publishing Readiness, Booking Readiness, Discovery Readiness, Attendee Activity, Day-of-Event Readiness, Event Momentum, and Post-Event Follow-up.

Each stage must reuse the existing owner of the underlying signal. If a stage needs a new operational rule, that rule belongs in the owning domain service first. Lifecycle guidance can explain it only after the source of truth exists.

## Momentum Guidance Standards

- Use neutral awareness language: "ready", "visible", "appears after bookings begin", "available when you need it".
- Avoid pressure language, urgency, countdowns, gamification, rankings, arbitrary scores, and growth-hack framing.
- Do not predict demand unless the data and product governance explicitly support that claim.

## Discovery Guidance Standards

Discovery guidance may mention banner images, categories, tags, promoted placement, public visibility, and sharing readiness. It must not implement SEO scoring, quality scores, ranking advice, or analytics engines.

## Publishing Intelligence Standards

Publishing guidance reuses node publication state, workspace status resolution, Event Studio readiness flags, `VendorPublishRequirementsGate`, and `PaidPublishStripeGate`. It may explain draft, live, review, paid-ticket, Stripe, RSVP, and ticket-sales state. It must not create new workflow states, moderation logic, or blocking rules.

## Attendee Momentum Standards

Attendee guidance reuses existing booking summaries, RSVP summaries, attendee operations, check-in links, and event overview metrics. It may explain that bookings, RSVPs, ticket holders, and check-in tools appear after activity begins. It must not expose attendee PII or duplicate attendee queries.

## Post-Event Standards

Post-event guidance may explain that an event has ended, attendee/order/RSVP records remain available through existing organiser tools, and support is available from organiser support surfaces. It must not create marketing automation, CRM features, or follow-up campaign systems.

## Accessibility Standards

Lifecycle cards use existing MEL live-ops card, timeline, chip, and panel classes. They must preserve semantic headings, render concise text, avoid colour-only meaning, stack cleanly on mobile, and keep decorative icons hidden from assistive technology.

## AI Alignment Rules

Lifecycle guidance may align with Help Centre, organiser AI, readiness governance, trust guidance governance, and product language governance. It must not widen retrieval, change audience filtering, add prompts, expose lifecycle internals, expose analytics internals, or surface staff-only help.

## What Must Never Be Exposed

Never expose staff-only diagnostics, moderation internals, internal SLAs, escalation levels, support queue state, private Stripe or Connect identifiers, webhook payloads, Commerce internals, checkout internals, attendee PII outside existing access-controlled surfaces, cross-vendor data, or raw analytics internals.

## Canonical Lifecycle Owner

`MelReadinessHelper` is the canonical owner for lifecycle guidance copy and reusable summary payloads. Builders may supply already-authorised and already-computed signals. Templates render payloads only.

## Reused Systems

- `MelReadinessHelper`
- `EventStateResolver` implementations
- `VendorDashboardViewModelBuilder`
- `VendorEventWorkspaceViewModelBuilder`
- `VendorActionQueueBuilder`
- `EventStudioForm`
- `VendorPublishRequirementsGate`
- `PaidPublishStripeGate`
- `VendorEventPresentationAlertsBuilder`
- `TicketAvailabilityService`
- `BookingFlowResolver`
- Existing analytics summaries and event overview metrics
- Existing attendee operations and check-in surfaces
- Existing promote-event surfaces
- Existing contextual help and Help Assistant architecture without retrieval changes
- Existing MEL card, chip, timeline, and live-ops styles

## Presentation Boundaries

Lifecycle guidance stays in dashboard and workspace payloads. It does not own vendor isolation, route access, event publishing, Stripe readiness, checkout availability, ticket purchasability, attendee queries, check-in validation, analytics aggregation, help retrieval, or AI grounding.

## What Intentionally Stayed Operational

Publishing gates, paid-ticket gates, Stripe/Connect state, Commerce order and checkout logic, RSVP logic, attendee operations, check-in validation, boost entitlement, analytics aggregation, Help Assistant retrieval, and staff-only support tooling remain operational and unchanged.

## Future Lifecycle Governance

Future lifecycle work must start with an audit of existing signal owners. New guidance should be added as reusable `MelReadinessHelper` payloads and rendered through existing MEL surfaces. Any request for enforcement, prediction, scoring, notification delivery, AI behaviour, CRM workflows, or analytics calculations is outside lifecycle intelligence and requires separate product and technical governance.
