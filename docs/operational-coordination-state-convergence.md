# Operational coordination state convergence (Phase 3B, Commit 1)

## Purpose

Introduce a **single canonical operational coordination read-model** for staff at `/admin/mel/operations`. This layer expresses **venue operational readiness**, **operational degradation**, **recovery coordination**, **reconciliation readiness**, **venue confidence**, **incident saturation**, **operational posture**, and **coordination visibility** — all as **read-only governance semantics** composed from existing merged inspector rollups and existing escalation / resolution projections.

This phase is **not** orchestration, automation, execution, dispatch, notifications, active failover, websocket coordination, or reconciliation execution.

## Authority boundaries

| Authority | Owns |
| --- | --- |
| **Entitlement authority** | Issued rows, capability truth, registry maps. |
| **Scanner authority** | Scan orchestration and public JSON contracts (`ScannerOperationManager`). |
| **Venue policy authority** | Gate semantics and venue composition (`VenueOperationPolicyManager` and delegates). |
| **Operational continuity authority** | Continuity / reconciliation metadata (`OperationalContinuityPolicyManager`). |
| **Incident authority** (when persisted) | Incident records — coordination layer **does not** mutate incidents. |
| **Escalation governance** (`OperationalEscalationPolicyManager`) | Severity and escalation token normalization and governed severity **from rollups** (unchanged). |
| **Resolution governance** (`OperationalResolutionGovernanceManager`) | Resolution lifecycle tokens and acknowledgement timing **for projections** (unchanged). |
| **Coordination state** (`OperationalCoordinationStateManager`) | Normalization of coordination / posture / confidence / saturation tokens; **composition** of coordination semantics from rollup keys + governed severity + resolution projection only. |
| **Coordination cards** (`OperationalCoordinationProjectionBuilder`) | Twig-safe workspace sections (labels/values pre-computed in PHP). |
| **Coordination audit** (`OperationalCoordinationAuditProjector`) | Append-only style timelines and history summaries without secrets. |
| **Operational workspace authority** (`OperationalWorkspaceBuilder`) | Merges inspector rollups, strips sensitive keys, attaches coordination sections **only** when permitted. |

Coordination services **must not** be consulted by scanner, issuance, redemption, or continuity mutation paths.

## Canonical tokens

### Coordination states

`nominal`, `elevated`, `degraded`, `constrained`, `recovery`, `reconciliation`, `saturated`, `restricted`

### Operational postures

`standard_operations`, `heightened_monitoring`, `incident_response`, `recovery_operations`, `reconciliation_operations`, `restricted_operations`

### Venue confidence states

`stable`, `caution`, `uncertain`, `degraded`, `critical`

### Incident saturation states

`clear`, `rising`, `elevated`, `saturated`

Unknown external tokens normalize to safe defaults (**logged**): coordination → `nominal`, posture → `standard_operations`, confidence → `stable`, saturation → `clear`.

## Posture normalization

Operational posture is **derived** from the canonical coordination state, the projected resolution state, and governed severity — without introducing a second severity engine. Resolution lifecycle anchors may appear in coordination audit timelines **only** as normalized state strings and unix anchors (no store mutation).

## Degradation semantics

Degradation visibility is expressed as **rollup stress summaries**: counts and governed severity drawn from merged issuance, recovery, artifact readiness, and guest continuity observations already produced by `OperationalWorkspaceBuilder`. The coordination layer **does not** re-score scanner outcomes per ticket.

## Recovery coordination semantics

Recovery coordination summarizes **completion states observed** and **recovery mismatch** flags from merged recovery rollups. It describes coordination visibility only; it does not trigger recovery execution or subscriber work.

## Reconciliation coordination semantics

Reconciliation readiness summarizes **artifact readiness machine strings** (for example QR operability and attachment continuity) from merged rollups. It does **not** expose QR payloads, signing material, or replay continuity tokens.

## Confidence semantics

Venue confidence states map **one-way** from governed severity (rollup-derived via `OperationalEscalationPolicyManager::projectSeverityFromOperationalRollup()`). This is observability, not a second confidence authority for scanners.

## Forbidden patterns

- Websockets, polling coordination rooms, realtime incident boards, or live maps.
- Notifications, queues, cron-driven coordination, staffing automation, or dispatch.
- Mutating entitlement truth, scanner state, redemption logs, continuity blobs, or recovery `State` keys.
- Duplicating timed-entry, session, zone, occupancy, continuity, or scanner evaluation logic — rollups and existing governance managers remain the sole inputs.
- Twig-side calculations of posture, degradation, confidence, saturation, or readiness.
- Exposing `replay_token`, QR payload material, device fingerprints, deterministic continuity descriptors, or customer identifiers through coordination projections.

## Operational governance boundaries

Coordination state remains **downstream of** entitlement, scanner, continuity, and diagnostics authorities. It provides **staff visibility** only and preserves separation of duties from escalation-only and ownership-only permissions.

## Workspace integration

- Route: `/admin/mel/operations` (unchanged).
- **Coordination sections** (cards + coordination audit timeline) render only when the account holds **`govern mel operational coordination`** (restricted), separate from **`govern mel operational escalations`** and **`govern mel operational ownership`**.
- `OperationalWorkspaceBuilder` appends coordination sections after ownership sections when permitted; sensitive keys are stripped before render.

## Related documents

- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [operational-escalation-resolution-governance.md](./operational-escalation-resolution-governance.md)
- [operational-assignment-ownership-governance.md](./operational-assignment-ownership-governance.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
