# Initiative Brief: Customer Experience Acceptance Refresh

| Field | Value |
| --- | --- |
| Initiative | TRACE-NOW-03 — Customer experience acceptance refresh |
| Status | Approved for bounded evidence collection; implementation not authorised |
| Product Owner approval | Approved on 26 July 2026 |
| Date | 2026-07-26 |

## Product surface

The attendee journey from understanding an event through RSVP or paid booking, confirmation, ticket access, calendar use, help and recovery.

## User

- Attendees considering or booking an event
- Guests and signed-in customers accessing confirmations and tickets
- Organisers whose event information and trust signals shape the attendee journey
- Support and operations roles responsible for recovery

## Human outcome

An attendee can understand an event, make an informed commitment, complete the appropriate booking path and confidently find the correct confirmation, ticket or next step.

## Why it exists

Repository and DDEV evidence cover substantial parts of the customer journey, but no single current acceptance record confirms the complete journey across mobile devices, assistive technology, transactional messages and the target environment.

This initiative exists to refresh acceptance evidence across the journey before any isolated customer-facing change is authorised.

## Manifesto alignment

- Design the complete journey.
- Earn trust continuously.
- Treat free RSVP and paid ticketing with equal product care.
- Show consequences before commitment.
- Support real mobile conditions.
- Preserve progress and provide a clear next step.

## Strategic goal

[Years 1-2: Establish trust and coherence](../../governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically dependable booking and confirmation and equal quality for RSVP and paid ticketing.

## Requirement reference

- [Events](../../governance/04-product-requirements.md#events)
- [Tickets](../../governance/04-product-requirements.md#tickets)
- [RSVP](../../governance/04-product-requirements.md#rsvp)
- [Orders](../../governance/04-product-requirements.md#orders)
- [Payments](../../governance/04-product-requirements.md#payments)
- [Messages](../../governance/04-product-requirements.md#messages)
- [Help Centre](../../governance/04-product-requirements.md#help-centre)

## Existing system owner

Current ownership is distributed across:

- event content, public event presentation and discovery owners;
- RSVP submission and confirmation owners;
- Drupal Commerce orders and checkout;
- payment-runtime services;
- ticket issuance, ticket view models and customer ticket surfaces;
- transactional messaging; and
- Help Centre and support routes.

Supporting architecture includes:

- [Digital Ticket Experience](../../architecture/digital-ticket-experience.md);
- [Ticket View Model](../../ticket-view-model.md);
- [Customer Operational Commerce Experience](../../customer-operational-commerce-experience.md); and
- [Canonical Checkout Flow](../../architecture/ADR-0001-canonical-checkout-flow.md).

I cannot confirm a single architecture owner for the complete customer lifecycle.

## In scope

- Reconcile existing customer acceptance and verification evidence
- Define representative RSVP and paid-ticket journeys
- Verify continuity from event understanding through confirmation and ticket access
- Identify evidence gaps for mobile, accessibility, messages and recovery
- Record bounded defects or policy questions for separate Product Owner decisions
- Distinguish repository evidence, DDEV evidence and target-environment evidence

## Out of scope

- Changing event, RSVP, checkout, payment, ticket or messaging implementation
- Redesigning public or customer surfaces
- Replacing accepted architecture
- Production payment movement
- Publishing or changing customer promises
- Treating an identified defect as authority to implement a fix

## Dependencies

- Current representative free RSVP and paid-ticket test scenarios
- Confirmed target environment for each acceptance claim
- Applicable public design authority
- Current transactional-message inventory
- Checkout and payment verification evidence
- Accessible mobile and assistive-technology test access
- Operational recovery and support ownership

## Risks

- A successful isolated screen may conceal a broken end-to-end journey.
- Historical DDEV evidence may be mistaken for current production evidence.
- Guest and signed-in journeys may diverge without being tested separately.
- RSVP may receive less product care than paid ticketing.
- Payment, ticket or message delays may be presented as failure or false success.
- Acceptance findings may be mistaken for implementation approval.

## Accessibility considerations

Acceptance must cover keyboard operation, focus order, status announcements, error recovery, zoom and reflow, readable language and representative screen-reader use. Responsive browser checks alone are not evidence of physical-device behaviour.

## Security and privacy considerations

Use test identities and de-identified evidence. Do not place customer personal information, ticket secrets, payment credentials or private support content in repository records. Verify that customer access boundaries remain intact throughout the journey.

## Commerce considerations

Paid and free journeys must be distinguished without fragmenting the human outcome. Acceptance must preserve canonical Commerce ownership of orders and payments and canonical ticket ownership of entitlement. Payment success, order placement and ticket issuance must not be treated as interchangeable states.

## Success criteria

- Representative RSVP and paid-ticket journeys have explicit acceptance scenarios.
- Guest and signed-in continuity are assessed separately where behaviour differs.
- Each conclusion identifies its environment and evidence date.
- Mobile, accessibility, messaging and recovery gaps are explicit.
- Customer-facing defects are recorded without silently authorising implementation.
- The Product Owner receives a bounded acceptance conclusion and decision list.

## Evidence required before implementation

- Approved requirement and bounded human outcome
- Current repository and route ownership evidence
- Applicable approved design authority
- Representative runtime evidence for RSVP and paid booking
- Mobile and assistive-technology evidence appropriate to the claim
- Transactional-message and ticket-access evidence
- Privacy, security, Commerce and operational assessment
- Product Owner approval of any resulting delivery slice

## Product Owner approval

| Decision | Name | Date | Evidence |
| --- | --- | --- | --- |
| Approve bounded customer experience acceptance evidence collection | Product Owner | 2026-07-26 | Explicit Product Owner approval |

Roadmap position and approval of this acceptance brief authorise evidence collection only. They do not authorise implementation.

## Current evidence

- [Customer Acceptance Audit](../../launch/customer-acceptance/customer-acceptance.md)
- [Customer Verification Summary](../../launch/customer-verification/verification-summary.md)
- [Launch Certification Sign-off](../../launch/launch-certification/launch-signoff.md)
- [Product Delivery Traceability](../../product-delivery-traceability.md#trace-now-03--customer-experience)
