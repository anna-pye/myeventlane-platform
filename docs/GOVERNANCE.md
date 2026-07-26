# MyEventLane Documentation Governance

## Highest authority

The [Organiser Manifesto](governance/00-organiser-manifesto.md) is the highest product authority. This document administers that authority; it does not replace or reinterpret it.

The canonical repository-wide hierarchy is recorded in [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md).

## Product Owner authority

The Product Owner:

- approves constitutional change;
- resolves genuine conflicts between product authorities;
- approves material product scope and roadmap movement;
- approves or unfreezes foundational design decisions where required; and
- accepts product risk that cannot be resolved within approved boundaries.

Technical or design authority may stop unsafe or contradictory work. Neither may silently override the Product Owner or the Manifesto.

## Decision process

1. Define the human outcome and affected user.
2. Cite the Manifesto, Constitution, strategy and requirement.
3. Gather repository, user, operational and market evidence proportionate to the decision.
4. Identify existing owners, frozen decisions and dependencies.
5. Record options, trade-offs and effects.
6. Obtain the required review and approval.
7. Record the decision before implementation.
8. Validate the outcome and preserve evidence.

Use the [product decision record](templates/product-decision-record.md) for material product decisions and the [initiative brief](templates/initiative-brief.md) before delivery planning.

## Required evidence

Evidence may include user research, support patterns, accessibility findings, product measures, operational incidents, repository inspection, runtime verification and relevant market research.

Evidence must be attributable and current enough for the claim. A proposal, placeholder or unverified implementation note is not evidence of current behaviour. Where evidence is insufficient, state: **“I cannot confirm this.”**

## Approval states

| State | Meaning |
| --- | --- |
| Proposed | Submitted for consideration; not authoritative |
| Draft | Being developed; not authoritative |
| In review | Under formal review; not yet approved |
| Approved | Explicitly accepted by the stated authority |
| Frozen | Approved and protected by change control |
| Superseded | Replaced by a named current authority |
| Historical | Retained as evidence; not current authority |
| Rejected | Considered and declined |
| Unknown | Repository evidence does not establish status |

Words in a title or filename do not establish approval.

## Conflict escalation

When documents conflict:

1. record the exact clauses and evidence;
2. identify the highest governing authority;
3. stop affected implementation where the conflict is material;
4. do not edit an approved or frozen document to manufacture alignment;
5. refer constitutional, strategic or frozen-design conflicts to the Product Owner; and
6. record the decision, affected documents and any supersession.

Engineering may resolve a purely technical inconsistency within approved product intent through the normal architecture process. If the resolution changes product behaviour or policy, it is not purely technical.

## Change control

Material changes state:

- reason and human outcome;
- governing authority;
- affected commitments and records;
- evidence;
- reviewer and approver;
- version effect;
- migration or communication needs; and
- review trigger.

Constitutional changes require Product Owner approval. Frozen design changes require the authority named by the frozen record plus Product Owner approval where stated. Editorial changes may not alter governing meaning.

## Review expectations

Permanent governance is reviewed when evidence, law, operating model, market, platform or recurring conflicts materially change. Review dates are recorded only when agreed. “Last reviewed” means an actual review, not the date a file happened to be edited.

## Approval and freezing

A document becomes approved only through explicit, attributable acceptance recorded in the document, decision record or review system. A document becomes frozen only after approval and an explicit freeze decision that identifies scope, version and unfreezing authority.

Absent evidence, status is `Unknown`.

## Supersession and history

A superseding document names what it replaces and why. The replaced document is marked `Superseded` and links to its successor. Historical records are preserved in place or moved to [`archive/`](archive/) through an approved documentation change. They are not deleted merely because they are old.

## Implementation boundary

Implementation must serve approved product intent. Code, configuration, runtime behaviour and delivery notes may reveal facts or constraints, but they may not silently create product policy, change the authority chain or unfreeze a design decision.

Roadmap placement is not implementation approval.
