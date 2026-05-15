# Offline reconciliation and operational continuity (Phase 2I)

## Scope

This phase adds a **single canonical operational continuity policy layer** for offline scan scaffolding, reconciliation metadata, replay alignment semantics, conflict descriptors, deterministic reconciliation fingerprints, and machine-safe continuity payloads. It does **not** add entities, queues, cron processors, background sync, remote reconciliation APIs, websocket systems, admin UIs, or alternate entitlement authority.

## Canonical authority

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Continuity / reconciliation metadata normalization, replay alignment scaffolding, deterministic reconciliation fingerprints, customer-safe continuity projection | `myeventlane_tickets.operational_continuity_policy_manager` | `OperationalContinuityPolicyManager` |
| Scan-time orchestration of identity + composed gates + continuity payloads | `myeventlane_tickets.venue_operation_policy_manager` | `VenueOperationPolicyManager` |
| Device / checkpoint / trust descriptors (metadata-only) | `myeventlane_tickets.device_operation_identity_manager` | `DeviceOperationIdentityManager` |
| Timing, session, zone topology (unchanged authorities) | `TimedEntryPolicyManager`, `SessionEntitlementPolicyManager`, `ZoneAccessPolicyManager` | — |
| Entitlement maps | `EntitlementCapabilityRegistry`, `mel_ticket_capability.manager` | — |
| Scanner orchestration (unchanged public JSON result contract) | `mel_scanner.operation_manager` | `ScannerOperationManager` |

**Commerce + `myeventlane_ticket` entities remain authoritative.** Continuity is **policy and metadata only**: it must not become device-local truth, a second issuance path, or a bypass of persisted redemption state.

## Staff coordination visibility (downstream)

Merged continuity and reconciliation **readiness machine strings** inside inspector rollups may feed **operational coordination projections** in the staff workspace for accounts with **`govern mel operational coordination`** — observability only; no continuity mutation and no reconciliation execution. The same continuity timing/session composition refs may also be consumed by **fulfillment lifecycle visibility** (separate permission) as scanner-outcome summaries without duplicating scanner policy — see [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md). See [operational-coordination-state-convergence.md](./operational-coordination-state-convergence.md).

## Supported metadata

Continuity material may appear under ticket `metadata_json` and/or optional JSON request bodies merged into the scanner operational context using:

- `metadata_json.mel_operational_continuity` (canonical)
- `metadata_json.operational_continuity` (legacy alias)

Normalized scalar fields (machine-oriented):

- `reconciliation_group`
- `continuity_epoch`
- `offline_sequence`
- `replay_window`
- `reconciliation_strategy`
- `conflict_strategy`
- `sync_hint`
- `continuity_mode`
- `recovery_scope`

## Composition model

`VenueOperationPolicyManager::evaluateOperationalContinuity()` returns the same scan gate bundle as `evaluateOperationalIdentity()` plus `operational_continuity`, built by `OperationalContinuityPolicyManager::buildMachineSafeContinuityPayload()` from:

- merged ticket + request continuity blobs,
- normalized device identity (for **staff-side** device continuity fingerprint only),
- the composed scan `policy` snapshot (timed + session + zone when present).

`VenueOperationPolicyManager` **must not** reimplement timing, session, zone, registry, or scanner-token normalization; it delegates to existing managers and `OperationalContinuityPolicyManager`. **`OccupancyPolicyManager`** composes after those gates for directional occupancy metadata only (see [anti-passback-live-occupancy-convergence.md](./anti-passback-live-occupancy-convergence.md)); continuity fingerprints remain owned by `OperationalContinuityPolicyManager`.

## Public vs staff-only continuity data

| Surface | May include | Must not include |
| --- | --- | --- |
| Universal ticket view model `continuity` | `offline_capable`, `continuity_mode`, `reconciliation_hint` (customer-safe tokens only) | `reconciliation_fingerprint`, `continuity_descriptor_token`, `replay_token`, HMAC material, internal topology dumps, operator identifiers |
| `mel_redemption_log.metadata_json.operational_continuity` | Full machine payload including deterministic fingerprints and staff replay diagnostics | N/A (staff-side storage; not part of public scanner JSON) |
| `OperationalIntegrityInspector` `artifacts.operational_continuity` | Summaries, policies, staff diagnostics | Not applicable to anonymous customer routes |

## Immutable contracts (unchanged)

- QR payloads and signing (`TicketQrPayload`, `QrCodeGenerator`)
- Public scanner operation JSON result keys (`ok`, `result`, `message`, `ticket_label`, `checked_in_at`, `ticket_id`)
- Wallet routes and PDF generation contracts
- Issuance authority and entity mutation rules outside `ScannerOperationManager`

## Forbidden patterns

- Device-local entitlement truth or offline-only mutations that skip entity authority
- Queues, daemons, websocket sync, or async reconciliation workers introduced by this layer
- Duplicating timing, session, zone, or scanner result-token semantics outside the canonical managers
- Exposing `replay_token`, site HMAC material, or continuity fingerprints on customer surfaces

## Operational escalation and resolution governance (Phase 3A, Commit 4)

Escalation, resolution, and suppression **governance projections** for staff (SLA acknowledgement windows, severity→escalation routing, suppression rule visibility) are documented in [operational-escalation-resolution-governance.md](./operational-escalation-resolution-governance.md). Those projections **must not** become alternate continuity authority, must not mutate persisted continuity blobs, and must not execute reconciliation.

**Assignment and ownership governance (Phase 3A, Commit 5)** adds read-only workspace projections for operational ownership, assignment posture, and handoff audit continuity documented in [operational-assignment-ownership-governance.md](./operational-assignment-ownership-governance.md). That layer **must not** mutate continuity blobs, scanner state, or reconciliation execution.

## Related documentation

- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md)
- [device-gate-identity-convergence.md](./device-gate-identity-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [operational-escalation-resolution-governance.md](./operational-escalation-resolution-governance.md)
- [operational-assignment-ownership-governance.md](./operational-assignment-ownership-governance.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [zone-access-topology-convergence.md](./zone-access-topology-convergence.md)
- [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md)
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md)
- [anti-passback-live-occupancy-convergence.md](./anti-passback-live-occupancy-convergence.md)
