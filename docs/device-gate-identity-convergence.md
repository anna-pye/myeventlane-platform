# Device and gate operational identity convergence (Phase 2H)

## Scope

This phase adds a **metadata-only operational identity layer** for scanner devices, venue gates, checkpoints, scan stations, and operator attribution. It does **not** add entities, config entities, admin UI, migrations, authentication, queues, or QR / issuance / wallet / PDF mutations.

## Canonical authority

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Normalize device / operator / checkpoint metadata; public vs staff descriptors; replay-safe device fingerprints | `myeventlane_tickets.device_operation_identity_manager` | `DeviceOperationIdentityManager` |
| Orchestrate identity with timing, session, zone, and entitlement composition on the scan path | `myeventlane_tickets.venue_operation_policy_manager` | `VenueOperationPolicyManager` |
| Scanner orchestration (unchanged public JSON result contract) | `mel_scanner.operation_manager` | `ScannerOperationManager` |

**VenueOperationPolicyManager** is the **canonical operational identity orchestrator** for scan-time composition. It **must not** reimplement timing, session, zone topology, or entitlement maps; it delegates to `TimedEntryPolicyManager`, `SessionEntitlementPolicyManager`, `ZoneAccessPolicyManager`, `EntitlementCapabilityRegistry`, and `TicketCapabilityManager`, and uses `DeviceOperationIdentityManager` for descriptor material only.

## Supported metadata

Operational device material may appear under ticket `metadata_json` and/or optional JSON request bodies (scanner API) using:

- `metadata_json.mel_operational_device` (canonical)
- `metadata_json.operational_device` (legacy alias)

Supported scalar fields (normalized, machine-oriented):

- `device_id`, `gate_id`, `checkpoint_id`, `operator_id`, `operator_role`, `trust_level`, `venue_id`, `zone_id`, `scan_mode`, `offline_mode`, `reconciliation_group`

**Scanner devices are not authentication systems.** Trust semantics are **labels** interpreted by policy managers (`normalizeDeviceTrustPolicy()`), not proof of identity.

## Public vs staff-only surfaces

| Surface | May include | Must not include |
| --- | --- | --- |
| Customer / universal ticket view model `operational_identity` (when present) | Sanitized `checkpoint`, `trust_category`, `scan_mode`, `offline_capable`, `operational_identity_version` | Operator IDs, gate topology internals, replay fingerprints, reconciliation hashes, HMAC / replay tokens |
| `mel_redemption_log.metadata_json.operational_identity.public_summary` | Same customer-safe subset | Operator IDs, fingerprints |
| `mel_redemption_log.metadata_json.operational_identity.staff_integrity_identity` | Operator attribution, `device_fingerprint`, gate/checkpoint IDs for audits | N/A (staff-side storage only; not returned on public scanner JSON) |
| `OperationalIntegrityInspector` `artifacts.operational_identity` | Diagnostics, masked operator suffix, fingerprints | Not applicable to customer routes (inspector is staff/diagnostic only) |

## Immutable contracts (unchanged)

- QR payloads and signing (`TicketQrPayload`, `QrCodeGenerator`)
- Public scanner operation JSON result keys (`ok`, `result`, `message`, `ticket_label`, `checked_in_at`, `ticket_id`)
- Wallet routes (`/wallet/apple/{order_item_id}`, `/wallet/google/{order_item_id}`)
- PDF generation authority and legacy compatibility adapters
- Entitlement authority on `myeventlane_ticket` entities

## Composition flow (scanner)

1. `ScannerOperationManager` merges ticket `metadata_json` operational device blobs with optional request `mel_operational_device` / `operational_device` (see `TicketCheckinApiController`).
2. `VenueOperationPolicyManager::evaluateOperationalIdentity()` normalizes identity, applies **effective zone** from `zone_id` when present, and calls existing `evaluateZoneAccessForScan()` once.
3. Audit rows include `operational_identity` alongside existing `venue_operation_integrity` and optional `operational_scan_policy` snapshots.

## Forbidden patterns

- Scanner-local trust decisions that bypass `VenueOperationPolicyManager` / `DeviceOperationIdentityManager`
- Duplicate topology, replay, or entitlement capability maps outside the canonical managers
- Device or gate **entities** as sources of truth for entitlement
- Exposing `replay_token`, site HMAC material, or full operator identifiers on customer-visible models
- Mutating QR payloads, wallet URLs, PDFs, or issuance flows from identity code paths

## Related documentation

- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md)
- [zone-access-topology-convergence.md](./zone-access-topology-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md)
- [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md)
