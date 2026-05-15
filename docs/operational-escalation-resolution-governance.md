# Operational escalation and resolution governance (Phase 3A, Commit 4)

## Purpose

Converge **canonical escalation governance**, **resolution lifecycle governance**, **suppression rules**, **SLA acknowledgement semantics**, and **audit-safe projections** for staff operational coordination. This layer is **observational and coordinative**: it does not issue tickets, mutate entitlements, execute reconciliation, enqueue work, auto-resolve incidents, or expose QR or replay material.

## Authority boundaries

| Authority | Owns |
| --- | --- |
| **Entitlement authority** | Issued ticket rows, entitlement truth, capability maps (`EntitlementCapabilityRegistry`, `TicketCapabilityManager`). |
| **Scanner authority** | Scan orchestration and public JSON contracts (`ScannerOperationManager`). |
| **Continuity authority** | Continuity / reconciliation metadata normalization (`OperationalContinuityPolicyManager`). |
| **Diagnostics authority** | `OperationalIntegrityInspector` read models. |
| **Incident authority** (future persistence) | Incident records when introduced; this phase does **not** add incident entities or mutation APIs. |
| **Workflow authority** | Business workflows outside this document. |
| **Operational workspace authority** | Staff shell composition (`OperationalWorkspaceBuilder`, `VenueOperationsController`). |
| **Escalation governance** (`OperationalEscalationPolicyManager`) | Normalization of escalation + severity tokens, severity→escalation routing table, SLA acknowledgement windows for **projections**. |
| **Resolution governance** (`OperationalResolutionGovernanceManager`) | Resolution state normalization, suppression validation rules, declarative transition matrix, acknowledgement timing projection. |
| **Escalation audit projection** (`OperationalEscalationAuditProjector`) | Audit-safe summaries and workspace card shapes derived from managers only. |

Escalation and resolution governance are **not** authoritative ticket truth and must not be consulted by scanner or issuance paths.

## Canonical tokens

### Escalation levels

`none`, `review`, `elevated`, `urgent`, `executive`

### Severity levels

`low`, `warning`, `moderate`, `elevated`, `severe`, `critical`

### Resolution states

`unresolved`, `acknowledged`, `under_review`, `monitoring`, `resolved`, `suppressed`

## Suppression semantics

- Suppression **requires** an explicit non-empty **reason** string at validation time.
- Suppression governance **never** deletes operational or incident history; it only governs whether a suppression **intent** is structurally valid.
- Suppression must **not** remove incidents from audit views, mutate entitlement state, erase scanner evidence, or erase redemption evidence.

## SLA semantics

SLA material is expressed as **acknowledgement seconds** per escalation tier (for example urgent tiers expect shorter acknowledgement windows). Values feed **deadline projections** from a request-time anchor; they are not timers or daemons.

## Workspace integration

- Route: `/admin/mel/operations` (unchanged).
- Route access remains `view mel venue operations workspace` via `OperationalWorkspaceAccessChecker`.
- **Escalation governance cards**, **SLA indicators**, **resolution governance cards**, **suppression visibility**, **ownership indicators**, and **escalation audit visibility** render only when the account holds **`govern mel operational escalations`** (restricted). The builder attaches governance **sections**; Twig renders normalized card rows only.
- **Assignment ownership**, **acknowledgement ownership**, **escalation owner visibility**, **handoff continuity**, **operational responsibility**, and **ownership audit timelines** render only when the account holds **`govern mel operational ownership`** (restricted). Shapes are composed exclusively by `OperationalOwnershipProjectionBuilder` and `OperationalAssignmentAuditProjector`; see [operational-assignment-ownership-governance.md](./operational-assignment-ownership-governance.md).
- **Operational coordination** (coordination state, posture, readiness, recovery/reconciliation visibility, confidence, saturation, coordination audit timelines) render only when the account holds **`govern mel operational coordination`** (restricted). Shapes are composed exclusively by `OperationalCoordinationProjectionBuilder` and `OperationalCoordinationAuditProjector`; see [operational-coordination-state-convergence.md](./operational-coordination-state-convergence.md).

Forbidden in the workspace: mutation buttons, “repair now”, “retry issuance”, reconciliation execution, entitlement editing, websocket clients.

## Twig and SCSS rules

- Twig renders **pre-shaped** `sections` / `meta` only — no severity math, SLA math, suppression decisions, or lifecycle transitions in templates.
- SCSS extends the existing operational workspace stylesheet with **soft severity tones** for governance sections; no full redesign.

## Forbidden patterns

- Mutating ticket entities, redemption logs, or recovery state from governance services.
- Regenerating QR payloads or exposing replay tokens through governance projections.
- Automated escalation, auto-suppression, auto-resolution, queues, or realtime systems inside this phase.
- Duplicating scanner evaluation, continuity fingerprints, or entitlement maps inside governance managers (rollup inputs must remain inspector/workspace merged summaries only).

## Related documents

- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-assignment-ownership-governance.md](./operational-assignment-ownership-governance.md)
- [operational-coordination-state-convergence.md](./operational-coordination-state-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
