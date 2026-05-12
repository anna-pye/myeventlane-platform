# Event Studio Question Governance Audit

Status: implementation audit  
Scope: attendee question integrity, field types, applicability, archived behavior, and future versioning

## Immutable Governance Rules

Question templates with historical attendee answers are operational records. Once answers exist, vendors must archive and create a new question instead of mutating answer semantics.

Forbidden after answers exist:

- Field type changes.
- Applicability boundary changes.
- Required-to-optional changes.
- Stable machine-name changes.
- Option value changes for `select`, `radios`, and `checkboxes`.

Allowed after answers exist:

- Label wording cleanup.
- Description/help copy.
- Ordering.
- Archiving the question.

Option-bearing questions compare normalized option value hashes. This keeps stored selected values from being reinterpreted after checkout answers already exist.

## Archived Rendering Rules

Rule A: archived questions never appear in new checkout renders.

Rule B: archived questions must still render historical answers in readonly reporting projections when answers exist.

Checkout filtering remains owned by the checkout attendee schema path. Reporting projections must read stored answer payloads and labels without requiring archived questions to become active again.

## Field Type Governance

Canonical internal values:

- `textfield`
- `textarea`
- `select`
- `checkboxes`
- `radios`
- `email`
- `tel`
- `number`

`tel` is legacy-compatible only. Studio UI must not promote it for new vendor-authored questions, but consumers must continue to render historical `tel` questions safely.

## Applicability Boundaries

Field type governance does not own applicability. Applicability, ticket-type matching, archived filtering, and checkout eligibility remain in checkout schema/resolver ownership.

Current active checkout support:

- `per_ticket`
- `per_ticket_type`

`per_order` remains deferred because per-order answer storage is not enabled in this slice. Active per-order questions are rejected or skipped rather than partially captured.

## Versioning Strategy

Full question versioning is not implemented in this slice. No speculative fields, version columns, placeholder metadata, or migrations were added.

Future direction:

- Snapshot immutable answer boundaries at checkout capture time.
- Consider version hashes for question type, machine key, applicability, required semantics, and option values.
- Keep historical answer rendering independent from whether the source question remains active.

## Unresolved Risks

- Historical answer existence currently depends on cloned question machine names or labels remaining available on attendee answer child paragraphs.
- A future normalized answer/version table would make immutable checks and reporting projections stronger.
- Mixed-cart checkout verification must remain part of every question-governance change because attendee capture is order-item-scoped.
