# MyEventLane Product Requirements

| Field | Value |
| --- | --- |
| Status | Permanent product-area governance |
| Highest authority | [Organiser Manifesto](./00-organiser-manifesto.md) |
| Constitutional parent | [Product Constitution](./01-product-constitution.md) |
| Strategic parent | [Product Strategy](./02-product-strategy.md) |

## Requirement standard

These requirements define responsibilities, not screens or implementation. Every area must use plain language, preserve progress, expose consequences, support mobile and accessibility, and provide one coherent source of truth.

## Discovery

- **Purpose:** Help people find relevant, trustworthy events.
- **User:** Prospective attendees and returning community members.
- **Responsibilities:** Search, browse, filter, relevance, clear empty states, event status and fair visibility.
- **Success criteria:** People can identify a suitable event and understand the next step without hidden ranking or dead ends.
- **Out of scope:** Social popularity mechanics and undisclosed pay-to-win ranking.
- **Dependencies:** Published event data, categories, location, accessibility information, moderation and analytics.

## Events

- **Purpose:** Hold the trusted public and operational identity of an event.
- **User:** Organisers, attendees and authorised staff.
- **Responsibilities:** Identity, description, schedule, location, imagery, organiser, status, policies and lifecycle.
- **Success criteria:** People can answer what, when, where, who, cost and next action; organisers can understand current state.
- **Out of scope:** Acting as the payment, order or ticket ledger.
- **Dependencies:** Organiser Workspace, Discovery, Tickets, RSVP, Marketing and Administration.

## Tickets

- **Purpose:** Represent the right to attend under clear conditions.
- **User:** Attendees, organisers and event-day staff.
- **Responsibilities:** Ticket types, availability, ownership, delivery, status, validation, transfer rules and check-in.
- **Success criteria:** Availability is accurate; ownership and status are trustworthy; event-day validation works under pressure.
- **Out of scope:** Replacing orders or payment records.
- **Dependencies:** Events, Orders, Payments, capacity, Messages and Administration.

## RSVP

- **Purpose:** Provide a complete, respected registration journey for free events.
- **User:** Attendees and organisers.
- **Responsibilities:** Registration, capacity, confirmation, cancellation, attendee questions, reminders and attendance state.
- **Success criteria:** Free attendance is as clear and dependable as paid booking.
- **Out of scope:** Simulating a payment where no payment exists.
- **Dependencies:** Events, capacity, Messages, Analytics and Organiser Workspace.

## Orders

- **Purpose:** Provide the authoritative commercial record of a booking.
- **User:** Customers, organisers, support and finance staff with appropriate access.
- **Responsibilities:** Line items, customer, totals, adjustments, state, receipts, refunds and audit trail.
- **Success criteria:** Every commercial state is consistent, explainable and access-controlled.
- **Out of scope:** Public event content or informal attendee notes.
- **Dependencies:** Tickets, Payments, Checkout, tax and Administration.

## Payments

- **Purpose:** Move and account for money safely and transparently.
- **User:** Customers, organisers, finance and support staff.
- **Responsibilities:** Authorisation, capture, failure, payout, fees, refund, reconciliation and evidence.
- **Success criteria:** Amounts, consequences and states are clear; failures recover safely; records reconcile.
- **Out of scope:** Concealing gateway complexity through ambiguous status or manually rewriting financial truth.
- **Dependencies:** Orders, payment providers, identity, security, privacy and incident response.

## Marketing

- **Purpose:** Help organisers reach appropriate audiences and continue the event relationship.
- **User:** Organisers and consenting recipients.
- **Responsibilities:** Sharing, campaign preparation, audience consent, previews, scheduling, attribution and follow-up.
- **Success criteria:** Organisers can communicate confidently; recipients understand source, purpose and choices.
- **Out of scope:** Spam, manufactured urgency, purchased attention disguised as relevance or unconsented messaging.
- **Dependencies:** Events, Messages, privacy, Discovery and Analytics.

## Analytics

- **Purpose:** Help organisers and MyEventLane understand outcomes and improve decisions.
- **User:** Organisers and authorised platform staff.
- **Responsibilities:** Clear definitions, reliable event and booking measures, privacy-conscious collection, context and export.
- **Success criteria:** Users can understand what happened and take a useful next step without specialist knowledge.
- **Out of scope:** Vanity measures, invasive tracking or claims unsupported by data quality.
- **Dependencies:** Consent, Events, Discovery, RSVP, Orders, Marketing and operational data.

## Messages

- **Purpose:** Deliver timely, accountable communication connected to an event outcome.
- **User:** Organisers, attendees, customers and support staff.
- **Responsibilities:** Transactional notices, reminders, organiser communications, delivery state, preferences and auditability.
- **Success criteria:** The right person receives understandable information at the right time, with appropriate control.
- **Out of scope:** A general social network or ungoverned bulk messaging.
- **Dependencies:** Identity, Events, RSVP, Orders, Marketing, privacy and provider operations.

## Help Centre

- **Purpose:** Help people resolve questions and recover without being abandoned.
- **User:** Organisers, attendees and customers.
- **Responsibilities:** Task-based guidance, contextual help, escalation, status information and feedback loops.
- **Success criteria:** People can solve common needs; unresolved needs reach accountable support; recurring confusion informs product improvement.
- **Out of scope:** Using documentation to compensate for avoidable product complexity.
- **Dependencies:** Support operations, product documentation, accessibility and incident communication.

## Organiser Workspace

- **Purpose:** Be the organiser's place of truth for one event.
- **User:** Organisers and authorised team members.
- **Responsibilities:** Identity, guidance, work and outcome zones; event lifecycle; readiness; sales or RSVP operations; attendance; promotion and follow-up.
- **Success criteria:** The organiser understands current state, what matters now and the next useful action.
- **Out of scope:** A display of everything the system knows, competing dashboards or platform administration.
- **Dependencies:** Every organiser-facing product area, permissions and the design governance hierarchy.

## Administration

- **Purpose:** Enable authorised staff to operate the platform safely and accountably.
- **User:** Support, moderation, finance, operations and technical administrators.
- **Responsibilities:** Access-controlled intervention, review queues, audit records, configuration, operational status and recovery.
- **Success criteria:** Staff can resolve legitimate needs without bypassing ownership, financial integrity, privacy or accountability.
- **Out of scope:** Convenience access to private data, silent state changes or using raw system interfaces as organiser experiences.
- **Dependencies:** Security, privacy, role governance, audit logging, incident response and all product-area sources of truth.

## Cross-area rule

No area may duplicate another area's authoritative state. Where journeys cross boundaries, the responsible area supplies the truth and the consuming area presents it in context.
