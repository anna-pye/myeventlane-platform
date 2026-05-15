# Venue operations workspace convergence (Phase 3A, Commit 1)

## Purpose

Introduce a **single staff-only operational shell** at `/admin/mel/operations` that converges operational visibility (integrity, venue topology, scanner posture, entitlement catalog) **without** a second policy stack, **without** realtime infrastructure, and **without** exposing machine integrity material to guests or vendors.

This commit is **read-only**: no websocket sync, no polling loops, no maps, no alternate operational stores.

## Architecture

| Component | Responsibility |
| --- | --- |
| `OperationalWorkspaceAccessChecker` (`myeventlane_tickets.operational_workspace_access`) | Route access only: anonymous denied; requires `view mel venue operations workspace` (restricted permission). |
| `OperationalWorkspaceBuilder` (`myeventlane_tickets.operational_workspace_builder`) | Samples Commerce orders for issued tickets on an optional event scope, calls `OperationalIntegrityInspector::inspectOrder()`, merges read-only diagnostics, and emits **Twig-safe section arrays** (cards with labels/values). When the account has `govern mel operational escalations`, appends **governance sections** produced exclusively by `OperationalEscalationAuditProjector` (escalation, SLA, resolution, suppression, audit visibility). |
| `OperationalEscalationPolicyManager` (`myeventlane_tickets.operational_escalation_policy_manager`) | Normalizes escalation and severity tokens, maps severity to escalation tiers, and defines SLA acknowledgement semantics for projections (not scanner authority). |
| `OperationalResolutionGovernanceManager` (`myeventlane_tickets.operational_resolution_governance_manager`) | Resolution state normalization, suppression validation (explicit reason required), lifecycle transition matrix, acknowledgement timing projection. |
| `OperationalEscalationAuditProjector` (`myeventlane_tickets.operational_escalation_audit_projector`) | Audit-safe escalation/resolution/suppression summaries for the workspace; no secrets or QR material. |
| `VenueOperationsController` | Resolves optional `?event={nid}` (event bundle only), invokes the builder, returns a themed render array with cache tags/contexts. |
| `venue_operations_workspace` Twig template | Renders pre-shaped `sections` and `meta` only — **no policy branching, no calculations**. |

**Composition note:** `OperationalIntegrityInspector` already orchestrates `TicketCapabilityManager`, `TimedEntryPolicyManager`, `SessionEntitlementPolicyManager`, `ZoneAccessPolicyManager`, `DeviceOperationIdentityManager`, `OperationalContinuityPolicyManager`, and `OccupancyPolicyManager` for `inspectOrder()` output. The workspace builder **reuses** that spine and adds only **type-catalog** rows via `EntitlementCapabilityRegistry` + `VenueOperationPolicyManager::describeEntitlementGateSemantics()` so policy is not evaluated twice for the same ticket rows. **Governance note (Phase 3A Commit 4):** governed severity is **projected** from merged rollup machine strings via `OperationalEscalationPolicyManager::projectSeverityFromOperationalRollup()` — not a second scanner or continuity evaluation.

## Read model boundaries

- **Public / vendor / customer surfaces** must never embed workspace variables. The route is admin-only and permission-restricted.
- **Staff render variables** contain aggregated machine strings (status tokens, counts, distinct policy labels). They intentionally **omit** replay tokens, HMAC material, continuity fingerprints, and device fingerprints (stripped in the builder).
- **Machine-only payloads** from `OperationalIntegrityInspector` remain inside the inspector; the workspace does not re-expose nested machine blobs to Twig.

## Orchestration responsibilities

- **Timing, session, zone, continuity, occupancy, device identity**: composed **only** inside `OperationalIntegrityInspector` and the policy managers it calls. The workspace builder **does not** re-evaluate scan gates or rebuild capability maps from scratch.
- **Entitlement catalog cards** call `EntitlementCapabilityRegistry::getAllCapabilityMaps()` plus `VenueOperationPolicyManager::describeEntitlementGateSemantics()` for **type-level** summaries that are already canonical elsewhere — this is a **catalog view**, not a per-scan evaluation.
- **Event-scoped diagnostics** require issued `myeventlane_ticket` rows with a Commerce `order_id`; the builder deduplicates orders discovered from the event ticket query (bounded sample size).

## Forbidden patterns

- Policy `if` trees in Twig or the controller for gate outcomes.
- Parallel entitlement maps duplicating `EntitlementCapabilityRegistry`.
- Returning `replay_token`, HMAC segments, QR payload bytes, or raw continuity fingerprints to themed output.
- Vendor routes reusing the workspace theme or builder without the staff permission and admin routing guarantees.
- Introducing websocket clients, polling JS, or charting libraries for this shell.
- Controllers or Twig computing escalation, SLA deadlines, suppression outcomes, or resolution transitions outside the governance managers / audit projector.

## Related documents

- [operational-observability.md](./operational-observability.md)
- [operational-escalation-resolution-governance.md](./operational-escalation-resolution-governance.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md)
