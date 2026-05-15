# Operational assignment and ownership governance (Phase 3A, Commit 5)

## Purpose

Converge **canonical operational ownership**, **assignment semantics**, **acknowledgement ownership visibility**, **escalation owner visibility**, **handoff governance**, **assignment audit continuity**, and **responsibility normalization** for staff at `/admin/mel/operations`. This layer is **governance visibility only**: it does not run staffing engines, messaging, notifications, automation, incident mutation, entitlement repair, or scanner execution.

## Authority boundaries

| Authority | Owns |
| --- | --- |
| **Entitlement authority** | Issued rows and capability truth (`EntitlementCapabilityRegistry`, issuance paths). |
| **Scanner authority** | Scan orchestration (`ScannerOperationManager`). |
| **Continuity authority** | Continuity metadata normalization (`OperationalContinuityPolicyManager`). |
| **Escalation governance** (`OperationalEscalationPolicyManager`) | Severity and escalation token normalization and routing **for projections** (unchanged). |
| **Resolution governance** (`OperationalResolutionGovernanceManager`) | Resolution lifecycle tokens, suppression validation, acknowledgement timing **for projections** (unchanged). |
| **Assignment governance** (`OperationalAssignmentGovernanceManager`) | Ownership state normalization, handoff type normalization, responsibility descriptors, escalation-owner **mapping labels**, rollup-derived ownership projection. |
| **Ownership projection** (`OperationalOwnershipProjectionBuilder`) | Twig-safe workspace **sections** (cards + optional pre-sorted timeline rows). |
| **Assignment audit projection** (`OperationalAssignmentAuditProjector`) | Assignment history summaries, handoff audit lines, ownership timelines, responsibility continuity summaries. |
| **Operational workspace authority** (`OperationalWorkspaceBuilder`) | Merges inspector rollups, strips sensitive keys, attaches governance sections per permission. |

Assignment and ownership governance **must not** be consulted by scanner, issuance, or redemption paths.

## Canonical ownership states

`unassigned`, `assigned`, `acknowledged`, `escalated`, `delegated`, `monitoring`, `resolved`

## Canonical handoff types

`operational`, `escalation`, `venue`, `moderation`, `finance`, `trust_safety`, `executive`

Unknown handoff tokens normalize to **`operational`** (logged). Unknown ownership tokens normalize to **`unassigned`** (logged).

## Assignment normalization

- **Ownership state** is normalized against the canonical list above.
- **Operational responsibility** strings are composed from normalized ownership + handoff only (no customer identifiers, no UIDs, no device material).
- **Rollup-derived ownership** (`projectOwnershipStateFromRollup()`) consumes **merged inspector summaries** already produced by `OperationalWorkspaceBuilder` and delegates severity/resolution signals to `OperationalEscalationPolicyManager` and `OperationalResolutionGovernanceManager` so routing math is not reimplemented ad hoc.

## Acknowledgement continuity

Acknowledgement **deadlines and SLA labels** for ownership cards reuse `OperationalResolutionGovernanceManager::projectAcknowledgementTiming()` — the same acknowledgement semantics as escalation governance, presented under the separate **`govern mel operational ownership`** permission for assignment-focused visibility.

## Handoff semantics

Dominant handoff **lanes** are observational projections from rollup shape (for example elevated severity routes toward an `escalation` lane; multi-gate venue samples may imply `venue` or `delegated` ownership posture). They are **not** executed handoffs and enqueue no work.

## Audit guarantees

- Assignment audit rows are **append-only narratives** for the current observation window; they do not mutate stores.
- Timeline rows are **sorted in PHP** by `recorded_at_unix` before theming; Twig renders rows only.
- Historical ownership, escalation transitions, acknowledgement continuity, and handoff continuity are described **without** replay tokens, QR payloads, device fingerprints, or sensitive customer identifiers.

## Workspace integration

- Route: `/admin/mel/operations` (unchanged); route access remains `view mel venue operations workspace`.
- **Ownership and assignment governance sections** render only when the account holds **`govern mel operational ownership`** (restricted), separate from **`govern mel operational escalations`**.
- Twig renders **pre-shaped** `sections` / `meta` / optional `timeline` arrays only.

## Forbidden patterns

- Notification dispatch, chat, websockets, auto-assignment, staffing automation, or workforce scheduling.
- Mutating entitlement truth, scanner state, redemption state, or operational continuity blobs from these services.
- Duplicating scanner evaluation, continuity fingerprint production, or entitlement maps (rollups remain the sole workspace input).
- Exposing `replay_token`, QR payload material, device fingerprints, or customer PII through ownership projections.

## Related documents

- [operational-escalation-resolution-governance.md](./operational-escalation-resolution-governance.md)
- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
