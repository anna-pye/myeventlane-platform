# Operations Ownership and Verification Baseline

| Field | Value |
| --- | --- |
| Status | Product Owner approved; accountable roles pending |
| Date | 2026-07-26 |
| Highest authority | [Organiser Manifesto](../governance/00-organiser-manifesto.md) |
| Operational authority | [MyEventLane Operations](../governance/05-operations.md) |
| Initiative | [TRACE-NOW-05](../product/initiatives/TRACE-NOW-05-operations-baseline.md) |

## Purpose

This baseline identifies what MyEventLane can confirm about operational ownership and readiness from repository and local DDEV evidence.

It does not create service levels, assign staff, certify production or authorise implementation. A class, module, route, dashboard or queue is a system owner or capability; it is not evidence that an accountable person is monitoring it.

## Evidence standard

| Evidence | What it establishes | What it does not establish |
| --- | --- | --- |
| Enabled module | Capability is enabled in the reviewed DDEV environment | Production state or staffed ownership |
| Route and access declaration | A product or staff entry point and access contract exist | Availability, usability or monitoring |
| Service, queue or entity | A technical responsibility has an implementation owner | Human decision authority or response time |
| Runbook | A procedure has been documented | Procedure currency or successful exercise |
| Historical acceptance or launch record | A conclusion was recorded for its stated environment and date | Current target-environment readiness |
| Named sign-off | A person accepted a stated decision | Ongoing roster or operational coverage |

Where the repository does not establish a fact: **I cannot confirm this.**

## Operational ownership matrix

| Domain | Confirmed system ownership | Current evidence | Accountable human owner | Verification state | Required action |
| --- | --- | --- | --- | --- | --- |
| Support intake | `myeventlane_escalations_portal`, `myeventlane_support`, Help Centre | `/my/support`, `/vendor/support`, `/support`; enabled modules and route access | Unknown | Routes confirmed in DDEV; monitoring and response coverage not confirmed | Product Owner to name accountable role and active channels |
| Support triage and escalation | escalation entity, SLA, analytics, capacity and policy modules | Staff entity routes, SLA dashboard, capacity and analytics routes; [Support Architecture](../support-architecture.md) | Unknown | Technical workflow present; staffed triage and escalation cadence not confirmed | Confirm roster, authority, escalation levels and hand-off |
| Moderation | Drupal content moderation plus operational-policy interpretation | Editorial workflow and moderation-related policy signals | Unknown | Content workflow exists; report intake, material decision and appeal procedure not confirmed | Product Owner decision and governed procedure required |
| Refund requests | `myeventlane_refunds`, refund access resolvers, processor and queues | Buyer, organiser approval/rejection, vendor refund and retry routes; kernel and unit test records | Unknown | Technical paths and guards present; current financial authority and reconciliation procedure not confirmed | Name refund decision authority and reconciliation owner |
| Payments and payouts | Drupal Commerce, Stripe Connect gateway and payment-runtime services | Payment ADRs, runtime maps, customer verification and launch records | Unknown | Architecture evidence strong; current gateway matrix and live reconciliation not confirmed in this phase | Confirm payment operator, gateway decisions and reconciliation cadence |
| Incident response | Drupal logging, diagnostics, observability and deployment recovery tooling | `dblog`, MEL observability records, diagnostics, release and rollback procedures | Unknown | Signals and recovery tools exist; incident classification, communications and exercise record not confirmed | Approve incident roles, severity model, communications and exercise |
| Security | Security workflow, permission configuration, dependency and repository checks | Security scan workflow, role-permission matrix and security audits | Unknown | Repository controls present; production security ownership and response coverage not confirmed | Name security owner and incident hand-off |
| Privacy | Privacy configuration and public policy routes; tracking audit | Privacy settings route and [tracking audit](../privacy/tracking-audit.md) | Unknown | Technical and documentation evidence exists; request-handling ownership and retention exercise not confirmed | Confirm privacy authority, request process and review trigger |
| Community standards | Public policy and trust content owners | Public trust module and Help Centre/policy content evidence | Unknown | Content capability present; enforcement and appeal ownership not confirmed | Confirm policy owner and review path |
| Accessibility operations | Product accessibility governance and surface helpers | [MEL Accessibility Review](../product-system/MEL_ACCESSIBILITY_REVIEW.md) and accessibility helper services | Unknown | Pattern evidence exists; issue intake, response ownership and device assurance remain incomplete | Name intake owner and severity/response procedure |
| Operational documentation | Documentation governance and repository register | [Documentation Governance](../GOVERNANCE.md), templates and document register | Unknown | Repository governance active; operational review calendar not confirmed | Confirm document owners and review triggers |
| Release management | GitHub Actions, release validation and deployment scripts | Staging deployment workflow, validation scripts, provenance and runbook | Unknown | Git-driven staging procedure documented; current production release owner and production verification not confirmed | Name release authority and production verification owner |

## Confirmed local capabilities

The following relevant modules were enabled in the reviewed DDEV environment:

- escalation core, portal, SLA, policy, analytics, capacity and refund correlation;
- support, support console and Help Centre;
- refunds and notifications;
- public trust;
- Drupal database logging and automated cron; and
- MyEventLane surface accessibility helpers.

Representative confirmed routes include:

- `/my/support` and `/my/support/escalations`;
- `/vendor/support` and `/vendor/support/{escalation}`;
- `/admin/myeventlane/escalations/dashboard`;
- `/admin/myeventlane/escalations/capacity`;
- `/admin/myeventlane/escalations/analytics`;
- `/my-tickets/order/{commerce_order}/refund`;
- `/vendor/events/{node}/refund-requests`;
- `/vendor/orders/{commerce_order}/refund`; and
- `/admin/myeventlane/support-console`.

Route confirmation does not establish response coverage or target-environment availability.

## Competing or overlapping responsibilities

### Support entry points

MyEventLane exposes `/support`, `/my/support`, `/support/tickets`, `/vendor/support` and a staff support console. The [Support Architecture](../support-architecture.md) describes their intended audiences. I cannot confirm that current navigation, staff practice and external support communication consistently direct each person to the intended channel.

### Refund surfaces

Refund responsibilities are distributed across buyer refund requests, organiser refund decisions, direct vendor refunds, event-state refund presentation and escalation/refund correlation. Technical separation is documented. The single accountable operating authority is not.

### Operational policy and enforcement

`MELOperationalPolicySystem` is interpretation-only. Permissions, moderation, Commerce, entity access and workflow engines remain authoritative. Operational policy presentation must not be treated as enforcement or as permission for automation.

### Observability and monitoring

`MELObservabilitySystem` explains state and orchestration. Drupal logs and other monitoring remain authoritative. Repository evidence does not establish production alert destinations, on-call coverage or acknowledgement expectations.

## Historical evidence classification

The June 2026 launch certification records “launch-ready with conditions” for DDEV and contains valuable bounded evidence. It does not prove current production readiness or a named operating roster.

The staging deployment runbook describes an implemented Git-driven staging model. It explicitly excludes the production HOLD site and does not establish current production deployment authority.

Historical implementation and remediation documents remain evidence. They do not outrank the Organiser Manifesto, Product Constitution or current Operations governance.

## Product Owner decisions required

The Product Owner approved this baseline on 26 July 2026. The decisions below remain open because approval of the baseline does not identify the accountable people or roles.

| ID | Decision | Why it is required |
| --- | --- | --- |
| OPS-PO-01 | Name the accountable role for support intake and escalation | Technical portals do not establish staffed ownership |
| OPS-PO-02 | Confirm active support channels and service expectations | Avoid accidental or unsupported promises |
| OPS-PO-03 | Name moderation decision and appeal authority | Material community decisions require human accountability |
| OPS-PO-04 | Name refund and payment operating authorities | Money movement and reconciliation require explicit authority |
| OPS-PO-05 | Approve incident roles, severity model and communication ownership | Tools exist but exercised response is not confirmed |
| OPS-PO-06 | Name privacy, security and accessibility intake owners | Trust responsibilities require clear hand-offs |
| OPS-PO-07 | Name release authority and production verification owner | Staging documentation does not establish production authority |

## Verification backlog

After the Product Owner confirms accountable roles:

1. validate each active support channel in its target environment;
2. walk one de-identified support escalation from intake to closure;
3. exercise one moderation decision and review path using test content;
4. reconcile one Stripe test-mode payment and refund through authoritative records;
5. run a tabletop incident covering event continuity, communication and recovery;
6. exercise accessibility issue intake and response;
7. perform a staging release and rollback evidence review; and
8. record outcomes without personal information or secrets.

Each exercise requires a separate approved brief. This baseline does not authorise those actions.

## Current decision

Operational architecture is substantial enough to support further verification. Human accountability and exercised procedures are not sufficiently established for new operational product surfaces.

The safe next action is Product Owner confirmation of roles and channels. Do not implement new operations features to compensate for missing operating-model decisions.

**Approval record:** Product Owner approved this baseline and its findings on 26 July 2026. No role assignment, service expectation, implementation authority or production certification is implied.
