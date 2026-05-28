# Zone and access topology convergence (Phase 2G)

## Scope

This phase adds **one canonical operational zone / access topology policy layer** for ticket-backed entitlements. It does **not** add entity fields, config entities, migrations, venue builders, GIS, staffing dashboards, or mobile clients.

## Canonical service

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Zone metadata normalization, topology descriptors, gate legality, scanner-safe denials | `myeventlane_tickets.zone_access_policy_manager` | `ZoneAccessPolicyManager` |

## Operational topology authority

**`VenueOperationPolicyManager`** remains the **orchestrator** for scan-time composition: it delegates clock policy to **`TimedEntryPolicyManager`**, session / sequencing / multi-use orchestration to **`SessionEntitlementPolicyManager`**, entitlement type maps to **`EntitlementCapabilityRegistry`** (via **`TicketCapabilityManager`**), and **zone / topology policy** to **`ZoneAccessPolicyManager`**.

**`ScannerOperationManager`** applies **`VenueOperationPolicyManager::evaluateZoneAccessForScan()`** before mutations. It does **not** own zone rules, timing math, or session progression.
**`ScannerOperationManager`** applies **`VenueOperationPolicyManager::evaluateOperationalIdentity()`**, which in turn calls **`evaluateZoneAccessForScan()`** before mutations. It does **not** own zone rules, timing math, session progression, or device trust semantics. When **`mel_operational_device.zone_id`** (or alias `operational_device`) is present, **`VenueOperationPolicyManager`** supplies it as the effective gate zone argument to **`ZoneAccessPolicyManager::evaluateZoneAccessForComposition()`** without duplicating topology math. See [device-gate-identity-convergence.md](./device-gate-identity-convergence.md).

## Metadata sources (no new entities)

Zone policy is read only from ticket `metadata_json`, first match:

- `mel_operational_zones` (preferred)
- `operational_zones` (alias)

Supported machine-oriented keys include (non-exhaustive):

| Key | Role |
| --- | --- |
| `zones` / `zone_ids` | Declared zone ids (scalar list or id→label map) |
| `allowed_zones` | Allow-list for a supplied gate zone id |
| `denied_zones` | Deny-list for a supplied gate zone id |
| `progression_order` | Ordered zones; enforced when a gate zone id is supplied |
| `gate_groups` / `gate_grouping` | Maps gate identifiers to zone id(s) |
| `topology_hints` | Machine tokens for observability / descriptors |
| `reentry_allowed` / `re_entry_allowed`, `reentry_by_zone` | Zone-layer re-entry hints (composed with session re-entry semantics) |

## Inward-only policy composition

- **Inward:** timed-entry and session payloads are **passed into** `ZoneAccessPolicyManager::evaluateZoneAccessForComposition()` from venue orchestration so zone evaluation does not re-derive clock or session math in parallel.
- **Outward:** customer QR payloads, wallet routes, PDF routes, issuance, and public scanner **result token strings** remain frozen; zone denials reuse existing tokens (for example `invalid`, `redemption_limit_reached`) with existing staff-facing message patterns where applicable.

## Immutable contracts (unchanged)

- QR payload structure and signing
- Public scanner JSON field names (`ok`, `result`, `message`, etc.)
- Ticket issuance and Commerce order semantics
- Wallet URL routes
- PDF generation contracts

## Scanner audit metadata

When the composed gate succeeds or is denied before mutation, **`operational_scan_policy`** in `mel_redemption_log.metadata_json` may include:

- `timed_entry`
- `session_entitlement`
- `zone_access` (normalized topology, scanner slice, optional scanner-safe `denial` block)

**`replay_token`** and other integrity secrets remain **staff-side only** inside `venue_operation_integrity`; they must not appear in public view models or guest diagnostics.

## View model (read-only enrichment)

**`UniversalTicketViewModelBuilder::build()`** adds machine-safe, metadata-derived fields:

- `zone_access` — normalized zone policy
- `topology` — compact descriptor (includes timed/session **references**, not duplicated policy maps)
- `gate_groups` — gate→zone map from metadata
- `reentry` — zone defaults / per-zone map plus `session_reentry_allowed` (session slice is not duplicated as a full map)
- `progression` — `zone_order` plus the existing `session_entitlement.progression` payload by reference under `progression.session`

Templates that do not reference these keys remain visually unchanged.

## Observability

**`OperationalIntegrityInspector::inspectOrder()`** adds **`artifacts.zone_access_topology`**, keyed by ticket id, with:

- topology descriptor summary
- structural conflict codes (for example allow/deny overlap)
- progression / re-entry semantics summaries
- gate policy counts (no raw purchaser PII)

## Anti-patterns (forbidden)

- Zone allow/deny/progression `switch` trees inside **`ScannerOperationManager`**
- Duplicating **`EntitlementCapabilityRegistry`** maps or **`TicketCapabilityManager`** redemption math inside zone code
- Duplicating **`TimedEntryPolicyManager`** or **`SessionEntitlementPolicyManager`** logic inside scanners or zone code for gates already composed by venue policy
- Emitting replay tokens, HMAC material, or full QR payload strings through public view models or guest diagnostics
- New QR payload versions or new public scanner status strings introduced for zone-only outcomes

## Related documentation

- [offline-venue-operations-convergence.md](./offline-venue-operations-convergence.md)
- [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md)
- [entitlement-capability-convergence.md](./entitlement-capability-convergence.md)
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
