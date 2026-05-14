# Session and multi-use entitlement convergence (Phase 2F)

## Scope

This phase adds **one canonical operational session orchestration layer** for ticket-backed entitlements. It does **not** redesign scanners, wallets, PDFs, issuance, checkout, venue timing math, or operational entities.

## Canonical service

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Session grouping, sequencing, multi-use exhaustion, bundle/zone metadata, grouped redemption interpretation | `myeventlane_tickets.session_entitlement_policy_manager` | `SessionEntitlementPolicyManager` |

## Mandatory composition order

Interpretation order on the scan path:

1. **`TimedEntryPolicyManager::evaluate()`** — operational clock policy (see [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md)).
2. **`SessionEntitlementPolicyManager::buildNormalizedPayload()`** — session, progression, multi-use, sequencing, re-entry semantics derived from entitlement + operational metadata, registry maps, and the timed snapshot (for shared `session_key` / zone hints only).
3. **`VenueOperationPolicyManager::evaluateZoneAccessForScan()`** — composes timing + session + zone topology scanner slices into one `allow` decision, existing `result_token` values, and staff `message` strings; attaches `policy` metadata (`timed_entry`, `session_entitlement`, `zone_access`) for audits. (Timed+session-only composition remains available as **`evaluateTimedEntryForScan()`** for narrow diagnostics.)
4. **`ScannerOperationManager`** — applies the venue gate before mutations; does not implement parallel window, session, sequencing, or zone rules.

## Normalized session payload (machine-only)

`SessionEntitlementPolicyManager::buildNormalizedPayload()` returns:

```php
[
  'session' => [
    'session_key' => string|null,
    'session_type' => string|null,
    'bundle_key' => string|null,
    'zone_key' => string|null,
  ],
  'redemption' => [
    'multi_use' => bool,
    'remaining_uses' => int,
    'max_uses' => int,
    'reentry_allowed' => bool,
    'sequence_required' => bool,
  ],
  'progression' => [
    'current_state' => string,
    'next_allowed_operations' => [],
    'is_exhausted' => bool,
    'requires_previous_redemption' => bool,
  ],
  'scanner' => [
    'allowed_now' => bool,
    'state' => string,
    'reason' => string,
  ],
]
```

No UI labels, no translated strings, no rendering. Customer-facing copy remains in presenters and existing scanner messages from `VenueOperationPolicyManager` / `ScannerOperationManager`.

## Metadata containers (no new entities)

Operational session hints are read from ticket `metadata_json` (first match):

- `mel_operational_session` (preferred)
- `operational_session` (alias)

Supported machine fields:

| Field | Type | Role |
| --- | --- | --- |
| `session_key` | string | Operational session identifier |
| `session_type` | string | Scenario token (e.g. `weekend_pass`, `convention`) |
| `bundle_key` | string | Bundle / meal-pack grouping |
| `zone_key` | string | Zone / wave label (may align with timed `capacity_window`) |
| `reentry_allowed` | bool | Overrides default re-entry inference when present |
| `sequence_required` | bool | Workshop / VIP wave sequencing |
| `required_prior_redemptions` | int | Minimum successful redemption count before scans are allowed |
| `progression_state` | string | Optional override for `progression.current_state` |

Timing metadata remains under `mel_operational_timing` / `operational_timing` per timed-entry documentation.

## Supported operational scenarios (canonical semantics)

Metadata-driven combinations cover, without inventory or checkout redesign:

- weekend / multi-day passes (`session_type`, optional `session_key`)
- drink / meal packs (`bundle_key`, multi-use redemption)
- parking-style re-entry (registry validate family + `reentry_allowed` defaults)
- VIP / backstage waves (`sequence_required`, `required_prior_redemptions`)
- workshop rotations (sequencing fields)
- convention access and zone-based access (`zone_key`, `session_key`)

## View model

`UniversalTicketViewModelBuilder::build()` adds:

- **`timed_entry`** — full timed policy array
- **`session_entitlement`** — full session payload
- **`zone_access`**, **`topology`**, **`gate_groups`**, **`reentry`**, **`progression`** — zone topology read-only fields (see [zone-access-topology-convergence.md](./zone-access-topology-convergence.md))
- **`scanner.timing_*` / `scanner.session_*`** — compact timing and session hints without altering `qr.payload` or wallet/PDF route contracts
- **`occupancy`** — optional customer-safe slice (`occupancy_mode`, `reentry_policy`, `directional_mode`) when non-baseline **`mel_operational_occupancy`** / **`operational_occupancy`** metadata is present (see [anti-passback-live-occupancy-convergence.md](./anti-passback-live-occupancy-convergence.md))

## Observability

`OperationalIntegrityInspector::inspectOrder()` adds **`artifacts.session_entitlement_policy`** (per ticket id: policy snapshot + machine `conflicts` for sequencing / exhaustion). **`artifacts.timed_entry_policy`** remains per-ticket timing diagnostics. **`artifacts.zone_access_topology`** summarizes zone policy, gate counts, progression/re-entry semantics, and structural conflicts. **`artifacts.occupancy_policy`** adds occupancy/directional/balancing read-only diagnostics. All are read-only.

## Scanner audit metadata

Successful and denied scans may attach **`operational_scan_policy`** inside `mel_redemption_log.metadata_json` when the venue gate produced a `policy` snapshot (staff-side only).

## Anti-patterns (forbidden)

- Scanner-local session, sequencing, or bundle progression rules
- UI-owned entitlement sequencing or exhaustion authority
- Wallet-only or PDF-only session or progression semantics
- Duplicate redemption progression rules outside `SessionEntitlementPolicyManager` and `TicketCapabilityManager`
- Direct `RedemptionLog` orchestration that bypasses `VenueOperationPolicyManager` / policy managers for gate decisions
- Weakening replay protection, timing authority, or registry delegation

## Related documentation

- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md) — venue gate and scanner orchestration
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md) — operational clock authority
- [entitlement-capability-convergence.md](./entitlement-capability-convergence.md) — immutable entitlement maps
- [operational-observability.md](./operational-observability.md) — diagnostics domains
- [zone-access-topology-convergence.md](./zone-access-topology-convergence.md) — zone metadata authority and scan composition
