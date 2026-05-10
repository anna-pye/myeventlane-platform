# Operational Readiness Governance

The operational readiness model is presentation-only. It explains existing readiness, publishing, payout, attendee, and discovery signals without introducing new databases, entity types, workflow states, notification systems, or duplicated business logic.

## Canonical Categories

1. Event Setup
   - Reuses `EventStateResolver`, workspace readiness items, Event Studio fields, and existing dashboard event rows.
   - Must not create alternate event completion scores outside `MelReadinessHelper` presentation summaries.

2. Tickets & RSVP
   - Reuses `EventStateResolver`, `BookingFlowResolver`, `TicketTypeManager`, `TicketAvailabilityService`, RSVP state, and vendor presentation alerts.
   - Must not calculate ticket purchasability in Twig.

3. Payments & Payouts
   - Reuses vendor store Stripe fields, `VendorPublishRequirementsGate`, `PaidPublishStripeGate`, and existing Stripe Connect routes.
   - Must not change Connect account type, charge model, payout rules, or Commerce checkout flow.

4. Publishing
   - Reuses node publication status, moderation-state checks already present, Event Studio save/publish handling, and publishing gates.
   - Must not add new moderation states or block publishing beyond existing gates.

5. Attendee Experience
   - Reuses public event visibility, booking mode, checkout trust copy, attendee operations presenter, and existing empty-state slots.
   - Must not expose attendee PII outside existing access-controlled views.

6. Day-of-Event Readiness
   - Reuses `MelAttendeeCheckinManager`, `MelVenueOperationsViewModelBuilder`, and attendee operation rows.
   - Must not duplicate check-in eligibility logic or bypass check-in access.

7. Support & Trust
   - Reuses Help Centre, support links, checkout trust copy, refund messaging, and governed empty states.
   - Must not expose internal SLA, escalation, moderation, or staff-only data.

8. Promotion & Discovery
   - Reuses existing event image/category/promoted-field surfaces, boost/promote surfaces, analytics summaries, and discovery systems.
   - Must use calm, non-manipulative copy.

## Canonical Owner

`MelReadinessHelper` owns readiness presentation copy and reusable summary payloads. Enforcement remains with the existing services that already own the rule:

- Publishing: `VendorPublishRequirementsGate`, Event Studio save services, node publication.
- Paid publishing: `PaidPublishStripeGate`.
- Ticket integrity: `VendorEventPresentationAlertsBuilder`, Commerce/ticket services.
- Check-in: `MelAttendeeCheckinManager` and attendee operations services.
- Access: route access, vendor console controllers, ownership guards, and existing access managers.

## Rules

- Keep readiness summaries cache-safe and derived from data already loaded for the surface where possible.
- Keep Twig presentation-only: no business rules, field probing, route hardcoding, or duplicate readiness checks.
- Keep copy translation-safe through PHP helpers or Twig `|t` fallbacks.
- Use existing MEL live-ops cards, chips, panels, and spacing.
- Do not widen Help Assistant retrieval or AI prompts for operational readiness.
