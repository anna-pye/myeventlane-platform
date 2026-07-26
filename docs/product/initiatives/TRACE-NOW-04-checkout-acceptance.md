# Initiative Brief: Checkout Acceptance Refresh

| Field | Value |
| --- | --- |
| Initiative | TRACE-NOW-04 — Checkout acceptance refresh |
| Status | Approved for bounded evidence collection; implementation not authorised |
| Product Owner approval | Approved on 26 July 2026 |
| Date | 2026-07-26 |

## Product surface

Cart, checkout, fees and totals, payment, order placement, confirmation, ticket issuance and recovery for event bookings.

## User

- Attendees purchasing tickets or completing a Commerce-backed booking
- Guest and signed-in customers
- Organisers relying on accurate orders, payments and ticket outcomes
- Finance, support and technical roles responsible for reconciliation and recovery

## Human outcome

An attendee understands the total commitment, completes checkout safely and receives a truthful explanation of the resulting order, payment and ticket state.

## Why it exists

MyEventLane has an accepted canonical checkout flow and documented payment runtime. Existing verification remains conditional for target-environment gateway configuration, Stripe test-mode reconciliation, mobile accessibility and current payment-state language.

This initiative exists to refresh those acceptance conditions without changing the accepted architecture.

## Manifesto alignment

- Show consequences before commitment.
- Earn trust continuously.
- Trust before flair.
- Prevent avoidable recovery.
- Use plain language.
- Preserve progress.

## Strategic goal

[Years 1-2: Establish trust and coherence](../../governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically dependable booking and confirmation.

## Requirement reference

- [Orders](../../governance/04-product-requirements.md#orders)
- [Payments](../../governance/04-product-requirements.md#payments)
- [Tickets](../../governance/04-product-requirements.md#tickets)
- [Messages](../../governance/04-product-requirements.md#messages)

## Existing system owner

- [ADR-0001: Canonical Checkout Flow](../../architecture/ADR-0001-canonical-checkout-flow.md) governs event-ticket checkout.
- [ADR-0001 Implementation](../../architecture/ADR-0001-implementation.md) records implementation evidence.
- [ADR-002: Payment Runtime](../../adr/ADR-002-payment-runtime.md) records the accepted current payment model.
- Drupal Commerce owns orders and payment workflow.
- Canonical ticket services own issued entitlement.

[ADR-003: Stripe Connect Strategy](../../adr/ADR-003-stripe-connect-strategy.md) remains proposed and does not override the accepted payment runtime.

## In scope

- Reconcile existing checkout, payment and launch evidence
- Confirm the current target-environment gateway matrix
- Define representative successful, declined, interrupted and delayed-state scenarios
- Verify price, fee, total and payment-consequence comprehension
- Trace successful test-mode payment through authoritative order, payment and ticket records
- Record mobile, accessibility, copy and recovery evidence gaps
- Produce bounded findings for Product Owner decision

## Out of scope

- Changing checkout panes, routes, gateways or payment architecture
- Enabling or disabling payment methods
- Moving money, issuing live refunds or changing payouts
- Redesigning checkout
- Selecting a future Stripe Connect model
- Implementing defects found during acceptance

## Dependencies

- Product Owner approval of this evidence-collection brief
- Confirmed non-production test environment
- Approved test-mode payment and reconciliation procedure
- Current gateway and manual-payment decision evidence
- Customer experience acceptance scenarios
- Finance and payment operating authority
- Applicable checkout design and content authority
- Accessible mobile and assistive-technology test access

## Risks

- A checkout UI success may be mistaken for payment or ticket success.
- Test-mode evidence may be represented as production readiness.
- An unconfirmed manual gateway may create ambiguous payment expectations.
- Price, fee or recovery language may be technically correct but unclear.
- Testing may create unsafe financial actions if environment boundaries are not explicit.
- Findings may be mistaken for authority to change accepted architecture.

## Accessibility considerations

Acceptance must cover keyboard operation, focus management, error identification, status announcements, zoom and reflow, plain-language consequences and representative screen-reader use. Payment-provider boundaries must not break the accessible journey.

## Security and privacy considerations

Use approved test credentials and test identities only. Do not record card data, secrets, webhook secrets, customer personal information or sensitive provider evidence in repository documentation. Maintain least privilege and authoritative audit records.

## Commerce considerations

Commerce order, payment and workflow state remain authoritative for their domains. Ticket issuance remains a distinct entitlement outcome. Acceptance must reconcile these states without introducing duplicate business logic or treating browser redirects as payment proof.

## Success criteria

- The tested environment and gateway matrix are explicit.
- Representative success, failure, interruption and delayed states have evidence.
- Price, fee and total consequences are understandable before commitment.
- Test-mode payment is reconciled through authoritative records.
- Order, payment and ticket states are described truthfully and separately.
- Mobile, accessibility and recovery evidence is explicit.
- No production payment movement or architecture change occurs.

## Evidence required before implementation

- Accepted canonical checkout and payment architecture
- Approved bounded problem statement
- Current gateway and target-environment evidence
- Authoritative test-mode reconciliation evidence
- Applicable design and content authority
- Mobile and assistive-technology evidence
- Security, privacy, Commerce and operational review
- Product Owner approval of any resulting delivery slice

## Product Owner approval

| Decision | Name | Date | Evidence |
| --- | --- | --- | --- |
| Approve bounded checkout acceptance evidence collection | Product Owner | 2026-07-26 | Explicit Product Owner approval |

Roadmap position and approval of this acceptance brief authorise bounded evidence collection only. They do not authorise implementation, production payment movement or architecture change.

## Current evidence

- [Customer Verification Summary](../../launch/customer-verification/verification-summary.md)
- [Payment Executive Summary](../../launch/payment-executive-summary.md)
- [Launch Certification Sign-off](../../launch/launch-certification/launch-signoff.md)
- [Product Delivery Traceability](../../product-delivery-traceability.md#trace-now-04--checkout)
