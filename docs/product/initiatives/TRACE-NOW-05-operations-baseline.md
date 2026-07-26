# Initiative Brief: Operations Ownership and Verification Baseline

| Field | Value |
| --- | --- |
| Initiative | TRACE-NOW-05 — Operations ownership and verification baseline |
| Status | Baseline approved; role assignments pending |
| Product Owner approval | Operations ownership and verification baseline approved on 26 July 2026 |
| Date | 2026-07-26 |

## Product surface

Support, moderation, refunds, payments, incidents, security, privacy, community standards, accessibility, documentation and release management.

## User

- Organisers and attendees who need help or recovery
- Staff responding to support, safety, payment and platform issues
- Product and engineering contributors responsible for safe delivery

## Human outcome

When something goes wrong, the affected person can reach an accountable response path, understand what happens next and trust that people, money, information and event continuity are protected.

## Why it exists

MyEventLane has extensive operational software and architecture records. Repository evidence does not establish named accountable human owners, staffed coverage, escalation expectations or current exercise evidence across all operational domains.

The baseline must distinguish:

- system ownership from human accountability;
- documented architecture from an exercised procedure;
- a configured route from an active support channel; and
- a launch conclusion from current production readiness.

## Manifesto alignment

- Support real conditions.
- Earn trust continuously.
- Explain consequences.
- Accessibility is foundational.
- Treat support demand as product evidence.
- Keep one source of truth.

## Strategic goal

[Years 1-2: Establish trust and coherence](../../governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence).

## Requirement reference

- [Help Centre](../../governance/04-product-requirements.md#help-centre)
- [Administration](../../governance/04-product-requirements.md#administration)
- [Payments](../../governance/04-product-requirements.md#payments)
- [MyEventLane Operations](../../governance/05-operations.md)

## Existing system owner

Confirmed repository owners include:

- escalation entities and portals under `myeventlane_escalations*`;
- Help Centre and support presentation under `myeventlane_help_centre`, `myeventlane_support` and `myeventlane_support_console`;
- refund access, processing and queues under `myeventlane_refunds`;
- payment runtime under Commerce and MyEventLane payment services;
- operational interpretation under `MELOperationalPolicySystem`;
- explainability under `MELObservabilitySystem`; and
- GitHub Actions and deployment scripts for release delivery.

Named accountable human owners are `Unknown` unless separately recorded.

## In scope

- Catalogue operational system owners and current evidence
- Identify accountable-owner and coverage gaps
- Separate confirmed routes and modules from staffed process claims
- Identify procedures requiring exercises or target-environment verification
- Establish the evidence needed before operational product work
- Preserve historical records without treating them as automatically current

## Out of scope

- Implementing support, moderation, payment or incident features
- Changing permissions, routes, queues, workflows or configuration
- Assigning people without Product Owner confirmation
- Inventing service levels, legal duties or escalation promises
- Production probes, deployment or payment movement
- Reopening accepted product or design decisions

## Dependencies

- Product Owner confirmation of accountable roles
- Current support-channel and staffing information
- Legal and policy input where required
- Target-environment access for later verification
- Security-conscious handling of incident and support evidence

## Risks

- Technical ownership may be mistaken for operational accountability.
- Historical launch evidence may be treated as current production evidence.
- A route may exist without a monitored channel.
- Unverified service expectations may become accidental promises.
- Sensitive operational detail may be placed in a public repository.

## Accessibility considerations

Operational channels, status communication and recovery instructions must be accessible. Accessibility issues require an owned intake and response path. This documentation phase does not claim screen-reader or physical-device certification.

## Security and privacy considerations

Do not place personal information, support transcripts, incident secrets, credentials or exploit detail in repository records. Use de-identified evidence and least-privilege access.

## Commerce considerations

Refunds, payment failures, disputes, payouts and reconciliation require explicit authority and auditable evidence. This initiative does not change Commerce or authorise payment movement.

## Success criteria

- Each operational domain has a confirmed system owner or `Unknown`.
- Human accountability is not inferred from code.
- Current evidence and evidence gaps are recorded separately.
- Product Owner decisions are explicit.
- No implementation or unsupported operating promise is created.

## Evidence required before implementation

- Named accountable owner
- Support and escalation coverage
- Approved procedure and decision authority
- Representative exercise or target-environment evidence
- Accessibility, security, privacy and Commerce assessment
- Success and failure communication expectations
- Product Owner approval for a bounded delivery brief

## Product Owner approval

| Decision | Name | Date | Evidence |
| --- | --- | --- | --- |
| Proceed with the documentation-only operations baseline | Product Owner | 2026-07-26 | Direction to continue to the next governed step |
| Approve the operations ownership and verification baseline | Product Owner | 2026-07-26 | Explicit approval of the documented findings and required decisions |

This approval confirms the baseline and its governance boundary. It does not assign accountable people, approve service expectations, authorise implementation or change operational policy.

## Recommended accountability model

The [Operational Accountability Model](../../operations/operational-accountability-model.md) proposes a scale-appropriate role structure, decision rights, channel ownership and separation rules. It remains pending Product Owner approval and named assignments.

## Deferred operational settings

Service levels, coverage hours, channel availability and escalation timing may become governed settings in a later build. [Deferred Operational Configuration Requirements](../../operations/deferred-operational-configuration-requirements.md) records the future safety and approval boundary.

No values or implementation are authorised. An inactive or incomplete configuration must not create a public service promise.
