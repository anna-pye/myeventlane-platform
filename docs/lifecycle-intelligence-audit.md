# Lifecycle Intelligence Signal Audit

Lifecycle intelligence is a presentation layer. It explains state already owned by existing services, builders, gates, and governed templates. It does not create new business rules, new workflow states, new analytics calculations, or new enforcement paths.

## Signal Inventory

| Signal | Existing Owner | Reusable? | Presentation Opportunity |
| --- | --- | --- | --- |
| Account readiness summaries | `MelReadinessHelper` | Yes | Keep canonical organiser setup, payout, publishing, attendee, and discovery copy in one translation-safe helper. |
| Public event lifecycle state | `myeventlane_event_state\EventStateResolver` | Yes, when caller already has access | Explain draft, scheduled, live, sold-out, ended, cancelled, and archived states without duplicating resolver logic. |
| Event domain state | `myeventlane_core\Service\EventStateResolver` | Yes | Interpret existing booking, RSVP, product, capacity, and promoted-event facts for organiser guidance. |
| Dashboard readiness | `VendorDashboardViewModelBuilder` | Yes | Add dashboard lifecycle cards from existing `readiness`, `events`, operational readiness, and route-access results. |
| Event workspace readiness | `VendorEventWorkspaceViewModelBuilder` | Yes | Add event-scoped lifecycle guidance from current status, booking type, existing metrics, banner, category/tag, and promoted signals. |
| Action queue | `VendorActionQueueBuilder` and `MelVendorDashboardActionQueueGovernance` | Yes | Keep priority/action ordering separate from lifecycle explanation. Lifecycle guidance should not add urgent tasks. |
| Event Studio publish hints | `EventStudioForm` with `VendorPublishRequirementsGate::getReadinessFlags()` | Yes | Explain publish readiness in Event Studio without adding publishing blocks or save logic. |
| Live publish requirements | `VendorPublishRequirementsGate` | Use with care | Reuse soft readiness flags and existing denial reasons only as explanations. Enforcement stays in the gate. |
| Paid publish Stripe gate | `PaidPublishStripeGate` | Use with care | Reuse existing outcome/copy to explain paid-ticket requirements. Do not inspect or expose Stripe internals. |
| Ticket presentation alerts | `VendorEventPresentationAlertsBuilder` | Yes | Keep ticket-mapping and price-display guidance in the existing vendor-console presentation builder. |
| Ticket availability | `TicketAvailabilityService` | Use with care | `resolveTierForVariation()` is safe for presentation. Buyer/session purchasability filtering remains checkout-owned. |
| Booking mode and CTA availability | `BookingFlowResolver` | Yes, through existing callers | Explain booking availability without duplicating checkout or public CTA rules. |
| Analytics summary availability | `VendorDashboardViewModelBuilder` and `VendorAnalyticsViewModelBuilder` | Yes | Mention analytics only when existing routes/access allow it. Do not calculate analytics in lifecycle guidance. |
| Attendee operations empty state | `GovernedOperationalTemplates` and `MelReadinessHelper` | Yes | Reuse attendee empty-state copy: bookings and RSVPs appear after they begin. |
| Attendee list/check-in state | `myeventlane_event_attendees` controllers and check-in services | Yes, via existing links/models | Explain that attendee and check-in tools are ready when bookings exist. Do not expose attendee PII. |
| Promote event state | `BoostEntitlementManager`, `VendorBoostController`, `field_promoted`, and core event state resolver | Yes | Explain promoted placement only as an existing signal. Do not create promotion scoring. |
| Banner image | `field_event_image` and existing thumbnail/presentation helpers | Yes | Suggest adding visual content for discovery and sharing. |
| Category and tag state | `field_category` and `field_tags` | Yes | Explain category/tag discovery support without SEO scoring. |
| Event overview metrics | `VendorEventOverviewController`, `MetricsAggregator`, `TicketSalesService`, `RsvpStatsService` | Yes | Reuse metrics already present on organiser surfaces. Do not add new queries to Twig. |
| Public visibility | Node published state, content moderation state, workspace status resolver | Yes | Explain draft/private, review, live, and past states using existing status payloads. |
| Help and contextual help | `ContextualHelpResolver`, `SupportPanelBuilder`, Help Centre config | Yes | Align wording with existing help surfaces without adding new retrieval or AI grounding. |
| Help Assistant and organiser AI | `HelpRetriever`, `UnifiedHelpRetriever`, `VendorAiAssistantForm`, configured prompts | No code change | Lifecycle copy may align in tone only. Retrieval, prompts, and AI behaviour stay unchanged. |

## Audit Findings

- `MelReadinessHelper` is already the canonical presentation-copy owner for readiness and empty-state guidance.
- Dashboard and event workspace builders already gather the reusable signals needed for lifecycle cards.
- Publishing, paid ticket, Stripe, Commerce, checkout, attendee, and check-in logic already have owning services and must remain authoritative.
- Existing live-ops panels, timeline cards, chips, and empty-state patterns are sufficient for the UI layer.
- Help and AI governance explicitly prevents widening retrieval, exposing staff-only content, or surfacing readiness internals to AI.

## Implementation Boundary

Lifecycle guidance may add reusable payloads and calm cards. It must not add notifications, modal onboarding, scores, new entities, permissions, dashboards, analytics engines, workflow states, Stripe logic, Commerce logic, or AI retrieval changes.
