# Deferred Operational Configuration Requirements

| Field | Value |
| --- | --- |
| Status | Deferred requirement; implementation not authorised |
| Date | 2026-07-26 |
| Owner | Product Owner |
| Highest authority | [Organiser Manifesto](../governance/00-organiser-manifesto.md) |
| Constitutional authority | [Product Constitution](../governance/01-product-constitution.md) |
| Operational authority | [MyEventLane Operations](../governance/05-operations.md) |
| Accountability model | [Operational Accountability Model](operational-accountability-model.md) |

## Decision

Service levels, coverage hours, holiday arrangements, channel availability and escalation timing will be considered as governed settings in a later build.

No values are approved by this decision. No implementation is authorised. Until an approved policy is deliberately activated, MyEventLane must not make a response-time or staffed-coverage promise that it cannot evidence.

Settings express approved operational policy; they do not create policy or authority.

## Purpose

Future configuration should allow MyEventLane to change operational commitments safely as its team, community and support capacity develop. It should avoid hard-coded promises and allow authorised people to understand what is active, when it applies and who approved it.

## Required configuration capability

A future approved initiative may provide settings for:

- active support channels;
- coverage timezone;
- ordinary weekly coverage periods;
- public holidays, planned closures and exceptional coverage;
- after-hours behaviour and messages;
- incident or request severity classes;
- acknowledgement targets and update cadence by severity or channel;
- resolution targets, only where an approved policy requires them;
- event-day priority periods or approved overrides;
- escalation timers and hand-off rules;
- secure references to the current duty roster;
- secure notification destinations;
- the accountable owner and approving authority;
- internal-only or public visibility;
- activation and effective dates; and
- review dates and review triggers.

Personal contact details, secrets and private roster information must not be stored in exported configuration or public documentation.

## Governance requirements

Future operational settings must:

- support draft, active and retired states;
- require authorised review before activation;
- distinguish internal operating targets from public commitments;
- show a clear preview of the commitment before publication;
- record who approved a change and when it became effective;
- preserve an auditable history and allow safe rollback;
- use least-privilege access;
- avoid exporting personal information or secrets;
- provide accessible public and internal messages;
- identify the timezone wherever a time-based commitment is shown;
- define safe behaviour when configuration is missing, incomplete or expired; and
- preserve Commerce, privacy, security and incident-response controls.

Configuration must not allow implementation staff or automated systems to silently create product or community policy.

## Safe inactive behaviour

When no service policy is active:

- acknowledge receipt without promising a response or resolution time;
- explain the next known step where evidence supports it;
- provide emergency or external-service directions only where separately approved;
- do not describe a channel as monitored unless current coverage is confirmed; and
- escalate operational ambiguity to the accountable role.

## Dependencies

- Product Owner approval of the [Operational Accountability Model](operational-accountability-model.md)
- Named accountable roles and an approved method of independent verification
- Confirmed support capacity and active channels
- Approved support, moderation, payment and incident procedures
- Legal, privacy, security, accessibility and Commerce review where applicable
- A bounded initiative brief and acceptance evidence for the future build

## Acceptance criteria for a future build

A future implementation must demonstrate that:

- inactive or incomplete settings create no unsupported public promise;
- only authorised roles can draft, approve and activate commitments;
- internal targets cannot accidentally appear as public commitments;
- timezone, closures and after-hours behaviour are unambiguous;
- changes are attributable, reviewable and reversible;
- accessibility and plain-language checks cover every published message;
- secrets and personal information are excluded from configuration exports;
- escalation behaviour fails safely; and
- the Product Owner approves the policy values independently of implementation approval.

## Out of scope

This record does not:

- choose service levels, hours, channels, timezones or severity thresholds;
- design an administration interface;
- define a data model or Drupal configuration schema;
- implement notifications, rosters, queues or escalation automation;
- amend any public support promise; or
- authorise roadmap delivery.

Roadmap position and this deferred requirement do not authorise implementation.
