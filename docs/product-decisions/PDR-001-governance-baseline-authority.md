# Product Decision Record: Governance baseline authority

| Field | Value |
| --- | --- |
| Status | Approved |
| Date | 2026-07-26 |
| Owner | Product Owner |
| Review date | Unknown |

## Human outcome

Contributors can identify the governing product authority, preserve approved design decisions and know when a conflict requires escalation rather than interpretation in implementation.

## Problem being solved

MyEventLane accumulated valuable product, design, implementation and assurance records before the Organiser Manifesto and Product Constitution were established. Several local documents use constitutional, canonical or source-of-truth language. Their scopes and precedence are not consistently distinguished.

Repository workflow documents also disagree about whether feature worktrees are permitted.

## Organiser Manifesto alignment

This decision protects:

- one source of truth;
- connected journeys over isolated pages;
- established patterns over unnecessary variation;
- trustworthy feedback;
- preservation of progress; and
- the requirement that lower-level documents may not contradict the Manifesto.

## Constitutional authority

- [Organiser Manifesto](../governance/00-organiser-manifesto.md)
- [Product Constitution](../governance/01-product-constitution.md)
- [Documentation Governance](../GOVERNANCE.md)

## Evidence

- The Manifesto and Product Constitution established a repository-wide authority chain.
- The frozen Vendor Studio PDS contains a local design constitution and build stack.
- Multiple dashboard records claim canonical hierarchy without approval metadata.
- `docs/DEVELOPMENT_WORKFLOW.md` requires feature worktrees.
- `docs/operations/DEV_GIT_RULES.md` prohibits feature worktrees.
- The Product Reset Phase 1 document predates the constitutional baseline and overlaps current strategy and requirements.
- The Launch Success catalogue records an approved direction but not completed implementation or final freeze.

## Options considered

### Option A - Preserve every local hierarchy without reconciliation

This avoids edits but leaves contributors to choose among conflicting authorities. It was rejected.

### Option B - Replace or rewrite the frozen design system

This creates unnecessary design risk and discards valid local governance. It was rejected.

### Option C - Establish global authority and preserve subordinate local decisions

This retains approved design intent while making scope and escalation explicit. It was approved.

## Decision

The canonical repository-wide authority chain is:

1. Organiser Manifesto
2. Product Constitution
3. Product Strategy and Product Requirements
4. Product Design System
5. Workspace Zones
6. Visual Language
7. Component Catalogue
8. Approved design decisions and assurance records
9. Implementation

Additional decisions:

- The Vendor Studio PDS remains frozen and authoritative within its design scope, subordinate to levels 1-3 above.
- PDS hierarchy clarifications are compatible governance changes and do not reopen design decisions.
- `docs/design/vendor-studio/12-dashboard-philosophy.md` is the current dashboard design authority. Competing root dashboard governance files are historical implementation records.
- Launch Success Alternative A is an approved design direction. Implementation completion and final freeze are not confirmed.
- `docs/product-reset-phase-1-source-of-truth.md` is historical product evidence, subordinate to current strategy and requirements.
- Isolated feature worktrees are permitted and governed by `docs/DEVELOPMENT_WORKFLOW.md`. The contrary prohibition is superseded.

## Trade-offs

The hierarchy is clearer, but several older records retain language that requires contextual cross-references. Narrow governance amendments are necessary in the frozen PDS pack.

## Trust effect

Contributors are less likely to change payment, publishing, dashboard or workspace behaviour based on a lower-authority document or an ambiguous filename.

## Accessibility effect

The decision preserves the frozen accessibility requirements and makes them subordinate only to higher constitutional commitments that also require accessibility by default.

## Complexity introduced

One global hierarchy is added above existing local design governance. No new product workflow, runtime service or implementation state is introduced.

## Dependencies

- Documentation register and governance audit
- Vendor Studio PDS change control
- Repository contribution guidance

## Success measure

- One documented global hierarchy
- No unresolved worktree instruction conflict
- Dashboard and Product Reset records clearly classified
- Launch Success status represented without unsupported completion claims
- All changed links resolve

## Review date

Unknown.

## Superseded decisions

The repository-wide hierarchy supersedes any interpretation that a local design or implementation document outranks the Organiser Manifesto, Product Constitution, Product Strategy or Product Requirements.
