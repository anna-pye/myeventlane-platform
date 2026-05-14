# Anti-passback and live occupancy policy convergence (Phase 2J)

## Scope

This phase introduces a **single canonical metadata-only occupancy policy layer** for live-venue occupancy semantics, re-entry hints, anti-passback vocabulary, entry/exit balancing descriptors, directional scan binding, occupancy continuity references, and zone-aware composition **without**:

- new occupancy entities or ledgers
- queues, workers, websocket systems, or realtime dashboards
- mutating QR payloads, wallet URLs, PDFs, issuance paths, or public scanner JSON result tokens
- a second entitlement authority (ticket rows + redemption logs remain authoritative)

Occupancy is **operational policy composition**, not scanner-local truth or gate-local truth.

## Canonical authority

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Occupancy / anti-passback / directional / balancing metadata normalization; scan-time directional evaluation; customer-safe occupancy summaries; staff diagnostics | `myeventlane_tickets.occupancy_policy_manager` | `OccupancyPolicyManager` |
| Orchestration of timing + session + zone + identity + continuity **and** occupancy after prior gates | `myeventlane_tickets.venue_operation_policy_manager` | `VenueOperationPolicyManager` |
| Continuity / replay alignment (unchanged) | `myeventlane_tickets.operational_continuity_policy_manager` | `OperationalContinuityPolicyManager` |
| Device identity (unchanged) | `myeventlane_tickets.device_operation_identity_manager` | `DeviceOperationIdentityManager` |
| Zone topology (unchanged) | `myeventlane_tickets.zone_access_policy_manager` | `ZoneAccessPolicyManager` |
| Session + timed entry (unchanged) | `SessionEntitlementPolicyManager`, `TimedEntryPolicyManager` | — |
| Registry + capabilities (unchanged) | `EntitlementCapabilityRegistry`, `mel_ticket_capability.manager` | — |
| Scanner mutations + public JSON | `mel_scanner.operation_manager` | `ScannerOperationManager` |

`VenueOperationPolicyManager` **must not** reimplement timing, session, zone, continuity replay maps, or registry semantics; it calls `OccupancyPolicyManager` after `evaluateZoneAccessForScan()` succeeds.

## Supported metadata

Occupancy material may appear under ticket `metadata_json` and/or optional JSON merged into the scanner operational context:

- `metadata_json.mel_operational_occupancy` (canonical)
- `metadata_json.operational_occupancy` (legacy alias)

Normalized fields (machine-oriented):

- `occupancy_mode` — `inherit_venue` \| `live_estimate` \| `authoritative_ledger_hint` \| `policy_only`
- `anti_passback_mode` — `none` \| `soft` \| `strict` \| `session_bound` \| `zone_bound`
- `reentry_policy` — `inherit` \| `allow` \| `deny` \| `session_governed` \| `zone_governed`
- `directional_mode` — `none` \| `entry` \| `exit` \| `bidirectional`
- `entry_zone`, `exit_zone` — normalized zone tokens (directional binding only)
- `occupancy_scope` — `ticket` \| `zone_group` \| `venue_session` \| `event`
- `occupancy_group` — opaque grouping token
- `balancing_strategy` — `none` \| `entry_exit_balance` \| `one_in_one_out` \| `implicit_session`
- `occupancy_cap` — optional positive int (descriptor-only in this slice)
- `occupancy_window` — `{ start_offset, duration_seconds }` (descriptor-only)

## Composition model

1. **Timing + session + zone** — unchanged; `VenueOperationPolicyManager::evaluateZoneAccessForScan()` remains the composed gate for clock, session, and topology.
2. **Occupancy** — when the composed gate allows, `OccupancyPolicyManager::evaluateOccupancyForScan()` applies **directional** constraints when an effective zone is present. Denials reuse existing scanner tokens (for example `invalid` for directional mismatch).
3. **Anti-passback / replay** — staff diagnostics **reference** `OperationalContinuityPolicyManager` replay alignment output; this layer does **not** duplicate scanner result-token normalization or mint alternate replay authority.
4. **Customer view model** — `UniversalTicketViewModelBuilder` may add `occupancy` with **only** `occupancy_mode`, `reentry_policy`, and `directional_mode` when metadata is non-baseline and customer-safe.

## Public vs staff occupancy data

| Surface | May include | Must not include |
| --- | --- | --- |
| Universal ticket view model `occupancy` | The three safe scalars above | Anti-passback internals, balancing caps/windows, topology ids, continuity fingerprints, replay tokens |
| `mel_redemption_log.metadata_json.operational_scan_policy.occupancy` | Normalized occupancy policy slice from scan evaluation | Same exclusions for customer-facing routes (log is staff-side; still avoid leaking site secrets) |
| `OperationalIntegrityInspector` `artifacts.occupancy_policy` | Summaries, descriptors, composition refs, deterministic occupancy descriptor | Not applicable to anonymous customer JSON |

## Immutable contracts (unchanged)

- QR payloads and signing (`TicketQrPayload`, `QrCodeGenerator`)
- Public scanner operation JSON result keys (`ok`, `result`, `message`, `ticket_label`, `checked_in_at`, `ticket_id`)
- Wallet routes, PDF contracts, issuance authority

## Forbidden patterns

- Local occupancy truth, gate-local balancing state, websocket/realtime occupancy systems
- Duplicating zone topology maps, session progression math, or continuity replay semantics outside canonical managers
- Exposing continuity fingerprints, replay tokens, or internal gate topology on customer surfaces

## Related documentation

- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
- [device-gate-identity-convergence.md](./device-gate-identity-convergence.md)
- [zone-access-topology-convergence.md](./zone-access-topology-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md)
- [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md)
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md)
