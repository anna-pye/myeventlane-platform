# MyEventLane Product Constitution

| Field | Value |
| --- | --- |
| Status | Permanent governance |
| Authority | Product Owner |
| Version | 1.1 |
| Last reviewed | 2026-07-26 |
| Highest authority | [Organiser Manifesto](./00-organiser-manifesto.md) |
| Applies to | Product, research, design, content, engineering, commerce, operations and support |
| Decision record | [PDR-001](../product-decisions/PDR-001-governance-baseline-authority.md) |

## Purpose

This Constitution explains how MyEventLane turns the Organiser Manifesto into accountable decisions. It governs how subordinate standards are created, interpreted, changed and applied. It does not replace the Manifesto and has no authority to weaken it.

Related active governance:

- [Product Strategy](./02-product-strategy.md)
- [Product Roadmap](./03-product-roadmap.md)
- [Product Requirements](./04-product-requirements.md)
- [Operations](./05-operations.md)
- [Engineering Principles](./06-engineering-principles.md)

## Constitutional hierarchy

1. **Organiser Manifesto** - the reason MyEventLane exists and its non-negotiable commitments.
2. **Product Constitution** - the rules by which product authority is exercised.
3. **Product Strategy and Product Requirements** - the chosen direction and stable product-area responsibilities.
4. **Product Design System** - the enduring product, experience and design standard.
5. **Workspace Zones** - the constitutional organisation of the organiser workspace.
6. **Visual Language** - the meaning and permitted use of visual expression.
7. **Component Catalogue** - the approved interaction patterns and freeze ledger.
8. **Approved design decisions and assurance records** - scoped decisions and evidence accepted through governance.
9. **Implementation** - code, configuration, content and live behaviour.

The roadmap, operations and engineering documents interpret this hierarchy within their respective domains. The roadmap sequences outcomes but does not authorise implementation.

## Authority of each document

- The **Manifesto** decides purpose, beliefs, promises and constitutional boundaries.
- The **Constitution** decides governance, ownership, evidence and change control.
- **Strategy** decides where MyEventLane will focus and what it will decline.
- The **Roadmap** sequences outcomes; it does not guarantee dates or authorise implementation by itself.
- **Product requirements** define stable responsibilities and boundaries for major product areas.
- **Operations** governs the way the service is run and people are supported.
- **Engineering principles** govern technical judgement without dictating an unchangeable architecture.
- Design governance documents progressively narrow principles into reusable patterns.
- Implementation records what exists. Existing behaviour is evidence, not authority.

## Decision standard

Every material proposal must state:

1. the organiser or attendee outcome;
2. the relevant Manifesto commitment;
3. the next step made clearer;
4. the evidence for the need;
5. the complexity, risk and duplication introduced;
6. effects on accessibility, trust, privacy, security and mobile use;
7. the accountable decision-maker; and
8. the measure by which the outcome will be judged.

A proposal that cannot answer these questions is not ready for approval.

## How conflicts are resolved

Conflicts are resolved from the highest applicable authority downward. The Manifesto always prevails.

When two subordinate documents conflict:

1. identify the specific conflicting clauses;
2. test both interpretations against the Manifesto;
3. prefer the interpretation that protects confidence, community and momentum;
4. prefer one coherent source of truth over duplicated behaviour;
5. record the decision and affected documents; and
6. amend the lower-authority document rather than creating an exception in implementation.

Deadlines, sunk cost, competitor behaviour and technical convenience do not override constitutional commitments. If evidence remains insufficient, the decision is deferred rather than guessed.

## Change control

All permanent governance documents use a recorded version, status, owner and review date.

- Manifesto changes require Product Owner approval and the controls stated in the Manifesto.
- Constitutional changes require Product Owner approval, written rationale, impact assessment and a recorded version change.
- Subordinate governance changes require the accountable document owner and review by affected disciplines.
- Material changes require consultation with people affected, including organisers or attendees where appropriate.
- Emergency operational decisions may temporarily depart from a subordinate procedure to protect people, money, data or service integrity. The departure must be recorded, time-limited and reviewed. It may never override the Manifesto.

Silent changes to governing meaning are not permitted. Editorial corrections that do not change meaning may be made with a dated record.

## Product governance

The Product Owner is accountable for constitutional alignment and final product decisions. Product work begins with a human outcome, not a feature request. Discovery establishes the problem and affected people. Requirements establish responsibility and scope. Delivery approval establishes priority and capacity.

Commercial sustainability is necessary, but revenue gained through confusion, exclusion or avoidable friction is unacceptable. Metrics must combine product outcomes, community outcomes, trust, accessibility, operational health and commercial health.

## Design governance

Design begins with the complete journey and the smallest supported screen. It must:

- preserve one meaningful primary action per state;
- use the four Workspace Zones where the organiser is working on an event;
- use established components before creating variants;
- explain state, consequence and recovery in plain language;
- include accessibility in acceptance criteria; and
- validate with real content and representative users where the risk warrants it.

Novelty is not a reason to create a pattern. Any new pattern requires a documented gap, cross-journey assessment and design-system decision.

## Engineering governance

Engineering protects the integrity of product intent. Architecture must preserve a single source of truth, clear ownership, secure access, dependable states and recoverable operations.

Technical proposals must identify data ownership, permissions, failure behaviour, observability, accessibility effects, migration needs and rollback or recovery. Implementation must not expose Drupal or Commerce concepts merely because they exist internally.

No implementation begins solely because it is technically possible. Approved product intent, proportionate discovery and testable acceptance criteria are required.

## Governance records

Material decisions belong in version-controlled records close to the affected documentation. Records must be concise, dated, attributable and link to the governing principle, evidence and superseded decision where relevant.

## Review

This Constitution is reviewed when the product enters a materially new market, operating model, platform or commercial model, or when recurring conflicts show that authority is unclear. Review does not imply change.
