# Readiness Principles

Operational readiness intelligence explains existing system state in calm organiser language. It is not an onboarding engine, dashboard rewrite, notification system, AI overlay, workflow state, or payment/Commerce change.

# Readiness Categories

The canonical categories are Event Setup, Tickets & RSVP, Payments & Payouts, Publishing, Attendee Experience, Day-of-Event Readiness, Support & Trust, and Promotion & Discovery. Each category must reuse existing signals and checks.

# Dashboard Guidance Standards

Dashboard guidance must show what is ready, incomplete, and useful next without alarmist language. It must reuse `VendorDashboardViewModelBuilder`, `VendorActionQueueBuilder`, `MelReadinessHelper`, and existing MEL card classes.

# Publishing Guidance Standards

Publishing guidance must explain visibility and missing setup without adding new blocks. Enforcement stays in `VendorPublishRequirementsGate`, `PaidPublishStripeGate`, and Event Studio save/publish logic.

# Payout Guidance Standards

Payout copy may say that Stripe needs to be connected for paid tickets. It must not expose account internals, verification details beyond existing product language, or alter Stripe/Connect state.

# Event Visibility Standards

Draft events are described as not public. Published events are described as visible to attendees. Moderation and review wording must stay external and non-diagnostic.

# Attendee Readiness Standards

Attendee guidance must explain that ticket holders, RSVPs, and check-ins appear after bookings begin. Row loading, filtering, attendee states, and check-in eligibility remain in attendee operations services.

# Day-of-Event Standards

Day-of-event guidance must reuse check-in surfaces and explain validation calmly. It must not create new scanner routes, duplicate check-in logic, or bypass event ownership checks.

# Promotion Guidance Standards

Promotion guidance may mention banner images, sharing event links, promoted placement, and discovery fit. It must avoid urgency, scarcity pressure, or growth-hack language.

# Accessibility Standards

Cards must keep semantic headings, readable concise copy, non-colour status indicators, and existing MEL responsive classes. New guidance should stack inside existing live-ops panels on mobile.

# AI Alignment Rules

Operational readiness copy may align with Help Centre language, but it must not widen AI retrieval, expose internal readiness internals to AI, or add organiser AI prompts unless separately approved.

# What Must Never Be Exposed

Never expose staff-only diagnostics, internal SLAs, escalation levels, moderation internals, private Stripe/Connect identifiers, raw webhook data, hidden Commerce internals, attendee PII outside existing access-controlled surfaces, or vendor data across ownership boundaries.

# Future Readiness Governance

Future readiness work must add presentation copy or summary payloads through `MelReadinessHelper` and reuse existing enforcement services. If a new product rule is needed, it belongs in the owning domain service first, then readiness presentation can explain it.

## Reused Systems

- `MelReadinessHelper` for canonical readiness and guidance copy.
- Organiser dashboard view model and action queue.
- Event workspace view model and live-ops panels.
- Event Studio publish readiness flags.
- Stripe and paid publishing gates.
- Ticket and RSVP state services.
- Attendee operations and check-in services.
- Existing MEL card, chip, and shell classes.
- Existing Help Centre and Help Assistant architecture without retrieval changes.

## Canonical Logic Location

Readiness presentation lives in `MelReadinessHelper`. Business logic remains in the existing service that owns the rule. Twig renders supplied payloads only.
