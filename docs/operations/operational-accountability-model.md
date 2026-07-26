# Operational Accountability Model

| Field | Value |
| --- | --- |
| Status | Recommended for Product Owner approval |
| Date | 2026-07-26 |
| Highest authority | [Organiser Manifesto](../governance/00-organiser-manifesto.md) |
| Operational authority | [MyEventLane Operations](../governance/05-operations.md) |
| Evidence baseline | [Operations Ownership and Verification Baseline](operations-ownership-verification-baseline.md) |

## Purpose

This model defines accountable operational roles and decision rights without assuming MyEventLane has a large team.

It assigns responsibilities to roles, not named people. A person may hold more than one role at MyEventLane's current scale, subject to the separation rules below. Product Owner approval of this model does not fill a role; each active role still requires a named assignment outside or within an appropriately controlled repository record.

## Role model

### Product Owner

Accountable for:

- product and community policy;
- approval of service expectations;
- material moderation outcomes and appeals where policy is unclear;
- risk acceptance;
- priority across operational, product and engineering work; and
- constitutional alignment.

The Product Owner does not use product authority to bypass financial, security, privacy or technical controls.

### Operations Duty Lead

Accountable for:

- the active operational queue;
- initial severity and event-impact assessment;
- assigning an owner and next update;
- cross-domain escalation;
- incident coordination until a specialist owner takes control; and
- confirming that affected people receive an appropriate update.

This is a duty role. It may rotate. Coverage expectations must be approved separately and must not be inferred from the existence of SLA software.

### Support and Community Lead

Accountable for:

- attendee and organiser support intake;
- support triage and case continuity;
- community-standard report intake;
- evidence preservation;
- moderation recommendations;
- communicating decisions and review paths; and
- identifying repeated support themes for Product.

Material moderation decisions require Product Owner accountability. The person who makes the original material decision must not be the sole reviewer of an appeal.

### Finance and Payments Lead

Accountable for:

- refund and payment operating decisions within approved policy;
- payment, payout and refund reconciliation;
- ambiguous payment-state escalation;
- financial action records; and
- liaison with Stripe or another approved provider.

Financial access is least privilege. Manual money movement requires a recorded reason and independent verification.

### Technical and Security Lead

Accountable for:

- technical incident assessment and containment;
- security triage and evidence preservation;
- recovery and rollback advice;
- vulnerability and dependency response;
- release execution;
- production technical verification; and
- keeping secrets and sensitive diagnostic material controlled.

The Technical and Security Lead may stop an unsafe release. They may not silently create product policy.

### Privacy and Accessibility Steward

Accountable for:

- privacy and accessibility issue intake;
- routing requests to the correct decision-maker;
- accessibility severity advice based on blocked outcomes;
- privacy-purpose, minimisation and retention review;
- accessible operational communication review; and
- maintaining evidence of unresolved barriers.

This steward advises and verifies. Legal decisions and material risk acceptance remain with the appropriately qualified authority and Product Owner.

### Independent Verifier

Responsible for a second-person or independent-system check of high-risk actions.

The verifier:

- did not perform the action being verified;
- has enough evidence and access to confirm the result;
- records the check and any exception; and
- escalates uncertainty rather than approving by assumption.

The verifier is assigned per action and is not necessarily a permanent job title.

## Accountability matrix

`A` means accountable, `R` responsible, `C` consulted and `V` independent verification.

| Operational domain | Product Owner | Operations Duty Lead | Support and Community Lead | Finance and Payments Lead | Technical and Security Lead | Privacy and Accessibility Steward | Independent Verifier |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Support intake and case continuity | C | A | R | C | C | C | — |
| Support escalation | C | A/R | R | C | C | C | — |
| Community report intake | A | C | R | — | C | C | — |
| Material moderation decision | A | C | R | — | C | C | V for appeal |
| Moderation appeal | A | C | R | — | C | C | V |
| Refund decision within policy | C | C | R | A | C | — | V when manually executed |
| Payment and payout reconciliation | C | C | C | A/R | C | — | V |
| Ambiguous financial state | C | A | C | R | R | — | V before resolution |
| Operational incident coordination | C | A/R | C | C | R | C | V at recovery |
| Security incident | C | R | C | C | A/R | C | V at recovery |
| Privacy request or incident | A | R | C | C | C | R | V where material |
| Accessibility issue | A | R | C | — | C | R | V at closure |
| Release approval | A for product scope | C | C | C where affected | R | C | V |
| Release execution | C | C | — | C where affected | A/R | C | V |
| Production verification | C | A | C | R for financial checks | R for technical checks | C | V |
| Operational documentation | A for policy | R | R | R | R | R | V for high-risk procedures |

No table entry grants Drupal, Commerce, Stripe, infrastructure or personal-information access. Technical access remains separately controlled through least privilege.

## Approved channel model

This model recommends the following channel purposes:

| Channel | Intended purpose | Operational owner |
| --- | --- | --- |
| `/support` and Help Centre | Public self-service guidance and routing | Support and Community Lead |
| `/my/support` | Attendee/customer case intake and case history | Support and Community Lead |
| `/vendor/support` | Organiser case intake and case history | Support and Community Lead |
| `/admin/myeventlane/support-console` | Staff triage and coordination | Operations Duty Lead |
| Escalation dashboards | Queue, workload and policy oversight | Operations Duty Lead |
| Refund workspaces | Refund decision and recovery | Finance and Payments Lead |

This classification does not claim that each route is active in production or monitored. Target-environment verification remains required.

## Separation rules

At least one independent check is required for:

- manual refunds or payment adjustments;
- payout or gateway configuration changes;
- production release verification;
- restoration after a security or privacy incident;
- deletion or export of personal information;
- material moderation appeals; and
- risk acceptance affecting people, money, privacy, security or event continuity.

The same person may prepare and execute a low-risk action when authorised. They must not be the sole approver and verifier of a high-risk action.

If MyEventLane cannot provide an independent person:

1. use an authoritative provider or automated reconciliation check where it genuinely verifies the outcome;
2. defer non-urgent high-risk action until independent review is available; or
3. escalate the exception to the Product Owner and record the risk.

Urgency does not turn self-approval into verification.

## Incident command

The Operations Duty Lead is the default Incident Coordinator.

The specialist accountable lead controls domain decisions:

- Finance and Payments Lead for money movement;
- Technical and Security Lead for containment and recovery;
- Privacy and Accessibility Steward for privacy or accessibility impact advice; and
- Product Owner for material product, community and risk-acceptance decisions.

Every incident record requires:

- human impact and affected event context;
- severity and owner;
- decisions and evidence;
- communication owner and next update;
- containment and recovery verification; and
- follow-up owner.

Service levels and severity thresholds require a separate approved procedure.

## Role assignment record

| Role | Named assignee | Deputy or verifier | Effective date | Review trigger |
| --- | --- | --- | --- | --- |
| Product Owner | Unknown | Unknown | Unknown | Operating-model change |
| Operations Duty Lead | Unknown | Unknown | Unknown | Coverage or roster change |
| Support and Community Lead | Unknown | Unknown | Unknown | Channel or policy change |
| Finance and Payments Lead | Unknown | Unknown | Unknown | Gateway or financial-policy change |
| Technical and Security Lead | Unknown | Unknown | Unknown | Architecture, security or release change |
| Privacy and Accessibility Steward | Unknown | Unknown | Unknown | Legal, privacy or accessibility-policy change |

Do not replace `Unknown` without explicit evidence from the Product Owner.

## Approval required

The Product Owner must decide:

1. whether to approve this role model;
2. which roles may be combined at the current scale;
3. who is assigned to each role;
4. how independent verification will be provided; and
5. which channels are active and monitored.

Until those decisions are recorded, this document is a recommendation and does not establish staffed coverage.
