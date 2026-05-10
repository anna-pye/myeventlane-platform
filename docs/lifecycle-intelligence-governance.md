# Lifecycle Intelligence Governance

Lifecycle intelligence explains existing organiser and event state. It is presentational, translation-safe, cache-aware, and grounded in the services that already own setup, publishing, booking, discovery, attendee, day-of-event, analytics, and support state.

## Canonical Owner

`MelReadinessHelper` owns lifecycle guidance copy and reusable summary payloads. Dashboard and workspace builders may compose existing signals and pass payloads to Twig. Twig renders supplied arrays only.

## Canonical Lifecycle Stages

1. **Event Setup** reuses organiser dashboard models, event workspace models, Event Studio state, title/date/description/banner readiness, and existing create/edit routes.
2. **Publishing Readiness** reuses node publication state, workspace status resolution, `VendorPublishRequirementsGate`, `PaidPublishStripeGate`, and Event Studio publish readiness flags.
3. **Booking Readiness** reuses `BookingFlowResolver`, core event domain state, ticket product state, RSVP state, `TicketAvailabilityService` presentation-safe tier mapping, and vendor ticket presentation alerts.
4. **Discovery Readiness** reuses `field_event_image`, `field_category`, `field_tags`, `field_promoted`, public visibility state, and existing promote-event surfaces.
5. **Attendee Activity** reuses ticket sales summaries, RSVP summaries, attendee operations, and event overview metrics already available to the organiser.
6. **Day-of-Event Readiness** reuses attendee and check-in surfaces. It explains availability without changing scanner or validation logic.
7. **Event Momentum** reuses existing bookings, RSVPs, analytics availability, operational readiness, and event overview metrics. It must not create pressure or arbitrary scores.
8. **Post-Event Follow-up** reuses event end/past state, attendee tools, order/RSVP surfaces, analytics pages, and support entry points.

## Presentation Rules

- Explain what existing systems already know.
- Prefer calm copy: ready, visible, draft, available, active, appears after bookings begin.
- Avoid fake urgency, scarcity, growth-hack language, addiction loops, and arbitrary quality scores.
- Do not duplicate readiness, publishing, Commerce, Stripe, checkout, attendee, or event-state logic.
- Do not calculate analytics in Twig.
- Do not hardcode route access in Twig.
- Do not expose internal diagnostics, moderation internals, Stripe identifiers, Commerce internals, support queue state, staff playbooks, or attendee PII.

## Stage Boundaries

- **Event Setup** can say details are ready or still editable. It cannot decide whether an event is operationally valid.
- **Publishing Readiness** can explain draft/live/review state. It cannot add blocking rules.
- **Booking Readiness** can explain RSVP, paid ticket, hybrid, or unavailable states from existing resolvers. It cannot change checkout paths.
- **Discovery Readiness** can suggest banner, category, tags, visibility, sharing, or Promote event. It cannot add SEO or quality scoring.
- **Attendee Activity** can explain where bookings, RSVPs, attendees, and check-in appear. It cannot expose attendee details outside existing access-controlled surfaces.
- **Day-of-Event Readiness** can point to check-in tools. It cannot duplicate validation.
- **Event Momentum** can explain current activity. It cannot predict demand or imply underperformance.
- **Post-Event Follow-up** can explain that records remain available. It cannot create campaigns, CRM workflows, or marketing automation.

## AI and Help Alignment

Lifecycle guidance may align with Help Centre and organiser AI tone. It must not widen Help Assistant retrieval, alter audience filtering, add organiser AI prompts, expose lifecycle internals to AI, or include staff-only help in vendor/public surfaces.

## Accessibility and Mobile Standards

Guidance renders in existing MEL live-ops cards and chips. Cards must stack on mobile, keep semantic headings, use text in addition to colour, preserve aria labels, and avoid notification overload.
