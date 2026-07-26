# Initiative Brief: Vendor Studio Acceptance and Catalogue Closure

| Field | Value |
| --- | --- |
| Initiative | TRACE-NOW-02 — Vendor Studio acceptance and catalogue closure |
| Status | Approved for acceptance evidence only |
| Product Owner approval | Approved to proceed with this bounded next step |
| Date | 2026-07-26 |

## Product surface

Vendor Studio Event Workspace: Launch Success, shared outcome states and the ticket workspace refinement.

## User

Event organisers using free RSVP, paid ticket or external booking workflows.

## Human outcome

An organiser can publish an event, understand what happened, see one trustworthy next step and manage ticket tiers without losing orientation or protected booking data.

## Why it exists

Current repository evidence shows that the approved Launch Success direction and ticket workspace refinement are implemented. Their catalogue labels were stale, while complete organiser-experience acceptance evidence and final Product Owner freeze were not found. The smallest safe next step is to test and close those known items before starting more design or implementation.

## Manifesto alignment

- One event, one coherent workspace, one clear next step.
- Preserve progress.
- Show consequences before commitment.
- Support real mobile conditions.
- Measure outcomes, not activity.
- Keep one source of truth.

## Strategic goal

[Years 1-2: Establish trust and coherence](../../governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence).

## Requirement reference

- [Organiser Workspace](../../governance/04-product-requirements.md#organiser-workspace)
- [Events](../../governance/04-product-requirements.md#events)
- [Tickets](../../governance/04-product-requirements.md#tickets)
- [RSVP](../../governance/04-product-requirements.md#rsvp)

## Existing system owner

- Event Studio workspace shell and outcome zone
- Launch Success preprocessing and shell behaviour
- Event Studio ticket forms and ticket application
- Ticket tier lifecycle and deletion guard
- Vendor theme Launch Success, outcome-state and ticket hierarchy presentation

## In scope

- Review Launch Success through both AJAX and return-query paths
- Review free RSVP, paid ticket and external booking outcome variants
- Review ticket creation, editing, capacity context and protected deletion
- Review at desktop, 768 px and 390 px
- Review keyboard, focus, reduced motion and relevant assistive technology
- Confirm that Hero remains the sole dominant primary action
- Record evidence, defects and Product Owner decisions
- Update Catalogue status after explicit Product Owner acceptance

## Out of scope

- New features or product areas
- Public discovery research
- Workspace, navigation or visual-language redesign
- Broad forms polish
- New success, notification or ticket component systems
- Resolving route ownership unless acceptance demonstrates a user-facing conflict
- Fixing defects during the evidence pass
- Roadmap implementation beyond this acceptance slice

## Dependencies

- Representative event states for free RSVP, paid tickets and external booking
- A safe target environment
- Current build and Drupal state
- Product Owner availability for acceptance
- Suitable browsers, viewport sizes and assistive technology

## Risks

- Contract tests may be mistaken for organiser acceptance.
- Test content may not represent meaningful ticket states.
- Acceptance may drift into opportunistic polish or redesign.
- A defect may be fixed without a governed brief.

## Accessibility considerations

Review heading and landmark structure, keyboard order, focus placement, visible focus, status announcement, colour-independent meaning, zoom/reflow, reduced motion, touch targets and the experience with relevant assistive technology.

## Security and privacy considerations

Use non-sensitive test data. Do not expose attendee details, payment data or production credentials in screenshots or evidence. Confirm that destructive ticket actions preserve existing booking protections.

## Commerce considerations

Test representative paid-ticket states without changing payment, order, capacity or refund policy. Confirm that tier deletion is blocked where bookings or order-item references require preservation. This initiative does not authorise Commerce architecture changes.

## Success criteria

- Both publish-success paths communicate a clear, accurate outcome.
- Free RSVP, paid ticket and external booking variants show an appropriate next step.
- No competing dominant action is introduced.
- Ticket tasks are understandable and operable at the nominated viewport sizes.
- Keyboard, focus, reduced-motion and relevant assistive-technology evidence is recorded.
- Protected deletion behaviour is verified with representative states.
- Each defect is recorded separately with severity and evidence.
- The Product Owner explicitly accepts, conditionally accepts or rejects each catalogue item.
- Catalogue statuses match the recorded decision.

## Evidence required before implementation

This is an acceptance initiative, not an implementation authority. If a defect is found, implementation requires:

- reproducible evidence;
- the affected governing authority;
- a bounded defect brief;
- accessibility, security and Commerce impact where applicable;
- the proposed smallest safe change;
- explicit Product Owner approval; and
- proportionate verification criteria.

## Product Owner approval

| Decision | Name | Date | Evidence |
| --- | --- | --- | --- |
| Proceed with bounded acceptance and catalogue reconciliation | Product Owner | 2026-07-26 | Direction to use the recommended organiser-experience next step |

This approval does not authorise implementation, redesign or automatic freeze.
