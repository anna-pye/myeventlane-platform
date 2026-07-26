# MyEventLane Product Delivery Traceability

| Field | Value |
| --- | --- |
| Status | Baseline |
| Scope | Product Roadmap initiatives currently in “Now” |
| Owner | Product Owner |
| Governing decision | [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md) |
| Highest authority | [Organiser Manifesto](governance/00-organiser-manifesto.md) |
| Last reviewed | 2026-07-26 |

## Purpose

This document connects current product direction to existing repository evidence. It does not approve implementation, reopen frozen design decisions or certify that older acceptance evidence remains current.

Roadmap position does not authorise implementation. Each delivery slice still requires an approved initiative brief, a confirmed system owner, bounded acceptance criteria and evidence appropriate to its risk.

## Authority chain

1. [Organiser Manifesto](governance/00-organiser-manifesto.md)
2. [Product Constitution](governance/01-product-constitution.md)
3. [Product Strategy](governance/02-product-strategy.md) and [Product Requirements](governance/04-product-requirements.md)
4. Applicable Product Design System
5. Workspace Zones, Visual Language and Component Catalogue where applicable
6. Approved decisions and assurance records
7. Implementation

## “Now” overview

| Initiative | Primary Manifesto alignment | Strategic goal | Primary requirement | Delivery readiness |
| --- | --- | --- | --- | --- |
| Discovery and research | Build with communities; start with the organiser or attendee goal | Make discovery dependable | Discovery | Discovery evidence exists; current user research plan and synthesis are missing |
| Vendor Studio | One coherent workspace; make the next step visible | Establish Vendor Studio as the organiser's coherent event workspace | Organiser Workspace | Strong design authority and repository evidence; current acceptance must be reconciled with outstanding review states |
| Customer experience | Design the complete journey; earn trust continuously | Make booking and confirmation dependable; equal quality for RSVP and paid ticketing | Events, Tickets, RSVP, Orders, Payments, Messages and Help Centre | Broad acceptance evidence exists; device, accessibility and production-sensitive gates remain |
| Checkout | Show consequences before commitment; protect payment integrity | Make booking and confirmation dependable | Orders and Payments | Canonical architecture exists; production-sensitive payment verification remains conditional |
| Operations | Support real conditions; earn trust continuously | Make support and operational readiness measurable | Help Centre, Administration and Payments | Governance and launch records exist; accountable operating ownership and live evidence remain incomplete |

## TRACE-NOW-01 — Discovery and research

### Human outcome

MyEventLane understands what organisers and attendees are trying to achieve, where they hesitate and which failures create avoidable support demand before deciding what to build.

### Manifesto alignment

- Build with communities rather than making assumptions about them.
- Start with the organiser's goal.
- Measure outcomes, not activity.
- Community is the reason the product exists.

### Strategic goal

[Years 1-2: Establish trust and coherence](governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically making discovery dependable and making accessibility, support and operational readiness measurable.

### Requirement reference

[Discovery](governance/04-product-requirements.md#discovery), with supporting responsibilities from [Events](governance/04-product-requirements.md#events) and [Analytics](governance/04-product-requirements.md#analytics).

### Existing architecture and ownership evidence

- [Discovery Route Ownership Map](audits/discovery-route-ownership-map.md) identifies the Views, services, templates and navigation sources for primary listing routes.
- [Discovery Route Inventory](audits/discovery-route-inventory.md) records active, redirecting and unowned routes.
- [Discovery Signal Ownership Map](audits/discovery-signal-ownership-map.md) records ranking, attribution and merchandising ownership, including placeholder and unproven signals.

These are audit records, not product authority. Their runtime conclusions require refresh before implementation.

### Design authority

- [MEL Design System](../DESIGN_SYSTEM.md) governs the public-theme token, hero and card contracts.
- [MEL Brand Strategy](brand/mel-brand-strategy.md) supplies brand direction but does not state approval or constitutional authority.
- [Canonical Design System Proposal](audits/canonical-design-system.md) is proposal-only and must not be treated as approved.

I cannot confirm a single approved public-discovery design authority beyond the scoped contracts in `DESIGN_SYSTEM.md`.

### Acceptance and evidence

- [Customer Acceptance Audit](launch/customer-acceptance/customer-acceptance.md) includes repository-evidence review of discovery surfaces.
- [Customer Verification Summary](launch/customer-verification/verification-summary.md) corrects several earlier assumptions through a DDEV live run.
- Existing discovery audits identify broken or unowned routes and placeholder signals.

### Evidence gaps before implementation

- Approved research questions, participant groups and consent approach
- Current organiser and attendee interviews or observed task evidence
- Support-demand evidence attributable to discovery
- Fresh runtime verification of route, ranking and empty-state findings
- Product decision on the public discovery design authority

### Current decision

The technical evidence refresh is complete. Further public discovery research is deferred to prioritise the organiser experience. Do not treat the research drafts, identified route gaps or ranking gaps as an active delivery commitment or authorised implementation.

Current records:

- [Discovery and research initiative brief](product/initiatives/TRACE-NOW-01-discovery-research.md)
- [Discovery evidence refresh — 26 July 2026](research/discovery/2026-07-26-evidence-refresh.md)
- [Deferred research protocol](research/discovery/research-protocol.md)
- [Deferred evidence-collection plan](research/discovery/evidence-collection-plan.md)

## TRACE-NOW-02 — Vendor Studio

### Human outcome

An organiser can work on one event in one coherent workspace, understand its current state and see one clear next step across draft, launch, live operation and follow-up.

### Manifesto alignment

- MyEventLane is an organiser operating system.
- We design workflows, not pages.
- Preserve progress.
- Keep one source of truth.
- One event, one coherent workspace, one clear next step.

### Strategic goal

[Years 1-2: Establish trust and coherence](governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically establishing Vendor Studio as the coherent organiser workspace.

### Requirement reference

[Organiser Workspace](governance/04-product-requirements.md#organiser-workspace), supported by [Events](governance/04-product-requirements.md#events), [Tickets](governance/04-product-requirements.md#tickets), [RSVP](governance/04-product-requirements.md#rsvp), [Marketing](governance/04-product-requirements.md#marketing), [Analytics](governance/04-product-requirements.md#analytics) and [Messages](governance/04-product-requirements.md#messages).

### Existing architecture and ownership evidence

- [DDR-008](design/vendor-studio/decisions/DDR-008-canonical-event-workspace.md) accepts the canonical Event Workspace path and shell.
- [DDR-009](design/vendor-studio/decisions/DDR-009-workspace-navigation.md) accepts the workspace navigation.
- [Workspace Foundation Review](design/vendor-workspace-v2/13-workspace-foundation-review.md) records implementation evidence but states that Product Owner review was pending at the time.
- [Workspace ownership map](audits/workspace-ownership-map.md) supplies supporting ownership evidence.

### Design authority

- [Vendor Studio PDS](design/vendor-studio/README.md), frozen version 1.0.3
- [Workspace Zones](design/vendor-studio-visual/07-workspace-zones.md)
- [Visual Language B.5](design/vendor-studio-visual/03-option-b5.md)
- [Vendor Component Catalogue](design/vendor-workspace-v2/23-vendor-component-catalogue.md)
- Accepted design decisions under [`design/vendor-studio/decisions/`](design/vendor-studio/decisions/)

These authorities must not be silently redesigned.

### Acceptance and evidence

- [Organiser Experience Acceptance Programme](launch/organiser-acceptance/organiser-acceptance.md) verified the canonical Event Studio paths in DDEV and found no P0 organiser launch blocker.
- [Launch Certification Sign-off](launch/launch-certification/launch-signoff.md) records launch readiness with conditions.
- The Component Catalogue records freeze and implementation states for Workspace components.
- [Vendor Studio Current-State and Catalogue Reconciliation](design/vendor-workspace-v2/24-current-state-catalogue-reconciliation.md) distinguishes frozen authority, implemented work and outstanding acceptance.
- [Vendor Studio Acceptance and Catalogue Closure](product/initiatives/TRACE-NOW-02-vendor-studio-acceptance.md) defines the bounded next organiser-experience slice.

### Evidence gaps before implementation

- Reconcile older “awaiting Product Owner review” records with accepted DDRs and later freeze decisions
- Fresh status of each Component Catalogue item
- Current live route and access verification on the target environment
- On-device mobile and assistive-technology evidence for core organiser tasks
- An approved initiative brief for any bounded delivery slice

### Current decision

Use the frozen design stack and existing ownership. The next bounded slice is acceptance and catalogue closure for Launch Success and the merged ticket workspace refinement. This does not authorise redesign or further implementation. Defects found during acceptance require separate, bounded approval.

## TRACE-NOW-03 — Customer experience

### Human outcome

An attendee can understand an event, trust its organiser and terms, complete an RSVP or paid booking, receive confirmation and access the correct ticket or next step.

### Manifesto alignment

- Design the complete journey.
- Earn trust continuously.
- Treat free RSVP and paid ticketing with equal product care.
- Show consequences before commitment.
- Support real mobile conditions.

### Strategic goal

[Years 1-2: Establish trust and coherence](governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically dependable booking and confirmation and equal quality for free RSVP and paid ticketing.

### Requirement reference

[Events](governance/04-product-requirements.md#events), [Tickets](governance/04-product-requirements.md#tickets), [RSVP](governance/04-product-requirements.md#rsvp), [Orders](governance/04-product-requirements.md#orders), [Payments](governance/04-product-requirements.md#payments), [Messages](governance/04-product-requirements.md#messages) and [Help Centre](governance/04-product-requirements.md#help-centre).

### Existing architecture and ownership evidence

- [Canonical Checkout Flow ADR](architecture/ADR-0001-canonical-checkout-flow.md)
- [Digital Ticket Experience](architecture/digital-ticket-experience.md)
- [Ticket View Model](ticket-view-model.md)
- [Customer Operational Commerce Experience](customer-operational-commerce-experience.md), a read-only projection contract

These records cover different parts of the journey. No single architecture record owns the complete customer lifecycle.

### Design authority

- [MEL Design System](../DESIGN_SYSTEM.md) for locked public-theme contracts
- Documents under [`docs/brand/`](brand/) as public brand references, subject to their individual status
- Product requirements and accessibility commitments above all surface-specific guidance

I cannot confirm that every document under `docs/brand/` is approved or frozen.

### Acceptance and evidence

- [Customer Acceptance Audit](launch/customer-acceptance/customer-acceptance.md), repository evidence
- [Customer Verification Summary](launch/customer-verification/verification-summary.md), DDEV live evidence
- [Customer Acceptance records](launch/customer-acceptance/)
- [Launch Certification](launch/launch-certification/launch-signoff.md)

The June 2026 verification found no remaining P0 blocker but retained accessibility, device and production-sensitive conditions.

### Evidence gaps before implementation

- Full WCAG AA and assistive-technology journey verification
- On-device mobile booking, ticket and calendar verification
- Current transactional-message inventory and deliverability evidence
- Target-environment verification of event-to-confirmation continuity
- Current evidence for saved-event, waitlist and refund communication states

### Current decision

Treat the customer journey as one cross-area outcome. Do not authorise isolated page work without showing continuity through confirmation and ticket access.

## TRACE-NOW-04 — Checkout

### Human outcome

An attendee understands the total cost and payment consequence, completes the appropriate checkout safely and receives a truthful order, payment and ticket outcome.

### Manifesto alignment

- Show consequences before commitment.
- Earn trust continuously.
- Trust before flair.
- Prevent avoidable recovery.
- Use plain language.

### Strategic goal

[Years 1-2: Establish trust and coherence](governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically dependable booking and confirmation.

### Requirement reference

[Orders](governance/04-product-requirements.md#orders) and [Payments](governance/04-product-requirements.md#payments), supported by [Tickets](governance/04-product-requirements.md#tickets) and [Messages](governance/04-product-requirements.md#messages).

### Existing architecture and ownership evidence

- [ADR-0001: Canonical Checkout Flow](architecture/ADR-0001-canonical-checkout-flow.md) accepts `mel_event_checkout` for event-ticket checkout.
- [ADR-0001 Implementation](architecture/ADR-0001-implementation.md) records implementation evidence.
- [ADR-002 Payment Runtime](adr/ADR-002-payment-runtime.md) records the current platform-collect and later-transfer model.
- [Payment Executive Summary](launch/payment-executive-summary.md) summarises launch architecture and conditional readiness.
- [ADR-003 Stripe Connect Strategy](adr/ADR-003-stripe-connect-strategy.md) remains proposed and must not override the accepted current runtime.

### Design authority

- Product Requirements for Orders and Payments
- [MEL Design System](../DESIGN_SYSTEM.md) for public checkout presentation contracts
- Applicable checkout trust and content records

I cannot confirm a single approved checkout-specific design authority equivalent to the Vendor Studio PDS.

### Acceptance and evidence

- [Customer Verification Summary](launch/customer-verification/verification-summary.md) verified payment-state gating, refund guards and webhook controls in DDEV.
- [Launch Certification Sign-off](launch/launch-certification/launch-signoff.md) records launch-ready with conditions.
- Payment launch and release records provide supporting evidence.

### Evidence gaps before implementation

- Current target-environment configuration and gateway matrix
- Live Stripe test-mode reconciliation from charge through ledger and transfer
- Confirmed production decision for the manual gateway
- Full mobile and assistive-technology checkout verification
- Current fee, refund and payment-state copy review

### Current decision

Preserve the accepted checkout and payment architecture. Discovery may test comprehension and identify bounded problems; no architecture change is authorised.

## TRACE-NOW-05 — Operations

### Human outcome

Organisers and attendees receive clear, accountable support while MyEventLane protects people, money, information and event continuity under normal and incident conditions.

### Manifesto alignment

- Support real conditions.
- Earn trust continuously.
- Explain consequences.
- Accessibility is foundational.
- Support needs should reveal product improvement opportunities.

### Strategic goal

[Years 1-2: Establish trust and coherence](governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), specifically measurable support and operational readiness.

### Requirement reference

[Help Centre](governance/04-product-requirements.md#help-centre), [Administration](governance/04-product-requirements.md#administration) and [Payments](governance/04-product-requirements.md#payments), governed by [Operations](governance/05-operations.md).

### Existing architecture and ownership evidence

- [Operational Readiness Governance](operational-readiness-governance.md) maps presentation responsibilities to existing owners.
- [Support Architecture](support-architecture.md)
- [Observability System](architecture/mel-observability-system.md)
- [Operational Policy System](architecture/mel-operational-policy-system.md)
- Existing runbooks and deployment records under [`docs/operations/`](operations/)

Several records describe presentation or architecture rather than confirmed staffed operating processes.

### Design authority

- [Operations governance](governance/05-operations.md)
- Product and accessibility requirements
- Applicable public or Vendor Studio design authority for the affected surface

Operational urgency does not permit inaccessible or ambiguous user communication.

### Acceptance and evidence

- [Launch Certification Sign-off](launch/launch-certification/launch-signoff.md)
- [Launch Evidence](launch/launch-certification/launch-evidence.md)
- Customer and organiser acceptance programmes
- Operational readiness audits and release records

The launch sign-off template still contains uncompleted owner signature fields. It is evidence of a documented certification conclusion, not proof of final named-owner sign-off.

### Evidence gaps before implementation

- Named accountable owners and escalation coverage
- Current support channels, service expectations and hand-offs
- Moderation decision and appeal procedures
- Incident classification, communication and exercise evidence
- Refund operating authority and reconciliation procedure
- Accessibility issue intake and response procedure
- Current release ownership and production verification evidence

### Current decision

Prioritise operating-model confirmation and runbook evidence before new operational product surfaces.

## Cross-initiative dependencies

| Dependency | Affected initiatives | Current evidence |
| --- | --- | --- |
| Public event truth and status | Discovery, Customer experience, Checkout | Distributed across Views, event content, booking resolvers and audits |
| Identity and access | Vendor Studio, Customer experience, Operations | Accepted workspace DDRs and route/access evidence; target environment still requires verification |
| Orders, payments and ticket fulfilment | Customer experience, Checkout, Operations | Accepted checkout ADR and payment-runtime records |
| Product language and status clarity | All | Manifesto, requirements and design guidance; no single cross-product content register confirmed |
| Accessibility and mobile evidence | All | Governance is clear; complete current evidence is not available |
| Support and incident learning | Discovery and research, Customer experience, Operations | Governance exists; operating evidence is incomplete |

## Delivery gate

Before any “Now” initiative produces implementation work, it must have:

1. an approved [initiative brief](templates/initiative-brief.md);
2. a defined human outcome and bounded product surface;
3. current evidence for the problem;
4. a requirement reference;
5. confirmed architecture and system owner;
6. applicable approved or frozen design authority;
7. accessibility, privacy, security, Commerce and operational assessment;
8. success and acceptance evidence;
9. Product Owner approval; and
10. a delivery decision separate from roadmap placement.

## Recommended delivery order

1. **Discovery and research evidence refresh** - establishes current problems without implementation.
2. **Operations ownership and verification baseline** - identifies who can safely support later delivery.
3. **Customer and checkout acceptance refresh** - closes device, accessibility and payment-environment evidence gaps.
4. **Vendor Studio catalogue reconciliation** - identifies whether any bounded, approved slice remains.

This order is a recommendation for decision readiness. It is not an implementation sequence or approval.
