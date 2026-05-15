# Venue operations workspace convergence (Phase 3A, Commit 1–3)

## Purpose

Introduce a **single staff-only operational shell** at `/admin/mel/operations` that converges operational visibility (integrity, venue topology, scanner posture, entitlement catalog, **incident triage, recovery visibility, severity rollup**, **stored operational coordination incidents**) **without** a second policy stack, **without** realtime infrastructure, and **without** exposing machine integrity material to guests or vendors.

This commit family is **read-only for issuance and scanner truth** through Commit 2. **Commit 3** adds bounded **operational coordination** writes on `mel_operational_incident` only (see [operational-incident-lifecycle-convergence.md](./operational-incident-lifecycle-convergence.md)); it does not introduce alternate entitlement stores, repair automation, or realtime sync.

**Commit 2** (incident / recovery convergence) is documented in [operational-incident-recovery-convergence.md](./operational-incident-recovery-convergence.md).

**Commit 3** (operational coordination lifecycle) is documented in [operational-incident-lifecycle-convergence.md](./operational-incident-lifecycle-convergence.md).

## Architecture

| Component | Responsibility |
| --- | --- |
| `OperationalIncidentAccessChecker` (`myeventlane_tickets.operational_incident_access`) | Route access for `/admin/mel/operations`: anonymous denied; requires `view mel venue operations workspace` (restricted permission). Same staff boundary as the legacy `OperationalWorkspaceAccessChecker`, which remains registered for compatibility. |
| `OperationalWorkspaceBuilder` (`myeventlane_tickets.operational_workspace_builder`) | Delegates bounded event sampling to `OperationalIncidentBuilder`, merges inspector diagnostics (including compatibility worst-path rollup), composes Twig-safe section arrays, and strips sensitive keys. |
| `OperationalIncidentBuilder` (`myeventlane_tickets.operational_incident_builder`) | Inspector-backed incident rows, severity rollup, redemption deny tallies (bounded), extended integrity catalog cards. |
| `OperationalRecoverySummaryBuilder` (`myeventlane_tickets.operational_recovery_summary_builder`) | Recovery + readiness cards from merged inspector domains. |
| `OperationalIncidentNormalizer` (`myeventlane_tickets.operational_incident_normalizer`) | Machine-safe incident type tokens; workspace sampled severity (`info` / `warning` / `critical`) vs coordination severity (`low` … `critical`) for stored incidents. |
| `OperationalIncidentProjectionBuilder` (`myeventlane_tickets.operational_incident_projection_builder`) | Composes stored `mel_operational_incident` coordination rows for the workspace (badges, assignment visibility, suppression excerpt, workflow metadata keys, optional `?incident_lifecycle=` filter). |
| `OperationalIncidentLifecycleManager` (`myeventlane_tickets.operational_incident_lifecycle_manager`) | Bounded coordination writes on `mel_operational_incident` only; validates lifecycle and acknowledgement transitions; requires suppression rationale for `suppressed`; never mutates tickets or scanner truth. |
| `OperationalIncidentWorkflowNormalizer` (`myeventlane_tickets.operational_incident_workflow_normalizer`) | Canonical lifecycle, escalation, and acknowledgement tokens for coordination (distinct from diagnostic typing). |
| `OperationalIncidentAuditBuilder` (`myeventlane_tickets.operational_incident_audit_builder`) | Logger-safe transition payloads and coordination audit summaries (keys and counts, not raw snapshots). |
| `OperationalIncidentEntityAccessControlHandler` | Entity access: view with workspace or coordination permission; update/delete with `manage mel operational incidents` only. |
| `VenueOperationsController` | Resolves optional `?event={nid}` (event bundle only), invokes the workspace builder, returns a themed render array with cache tags/contexts. |
| `venue_operations_workspace` Twig template | Renders pre-shaped `sections` and `meta` only — **no policy branching, no calculations** (optional `card.severity` / row severity classes for presentation only). |

**Composition note:** `OperationalIntegrityInspector` already orchestrates `TicketCapabilityManager`, `TimedEntryPolicyManager`, `SessionEntitlementPolicyManager`, `ZoneAccessPolicyManager`, `DeviceOperationIdentityManager`, `OperationalContinuityPolicyManager`, and `OccupancyPolicyManager` for `inspectOrder()` output. The workspace builder **reuses** that spine; `OperationalIncidentBuilder` adds **bounded redemption audit aggregation** (deny counts by result token) without duplicating scanner gate logic. Type-catalog rows still use `EntitlementCapabilityRegistry` + `VenueOperationPolicyManager::describeEntitlementGateSemantics()` for **type-level** summaries.

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

## Related documents

- [operational-incident-recovery-convergence.md](./operational-incident-recovery-convergence.md) — Phase 3A Commit 2 incident and recovery workspace
- [operational-incident-lifecycle-convergence.md](./operational-incident-lifecycle-convergence.md) — Phase 3A Commit 3 coordination entity and lifecycle
- [operational-observability.md](./operational-observability.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md)
