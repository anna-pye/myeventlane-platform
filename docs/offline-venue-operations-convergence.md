# Offline and venue operations convergence (Phase 2D)

## Scope

This phase introduces a **single venue operation policy layer** for ticket-backed entitlements. It does **not** add sync queues, mobile clients, websocket infrastructure, or remote reconciliation APIs.

## Canonical services

| Responsibility | Service ID | Class |
| --- | --- | --- |
| Entitlement type policy (immutable maps) | `myeventlane_tickets.entitlement_capability_registry` | `EntitlementCapabilityRegistry` |
| Entity-aware scan / expiry / redemption composition | `mel_ticket_capability.manager` | `TicketCapabilityManager` |
| Venue gate policy, offline scaffolding metadata, replay fingerprints | `myeventlane_tickets.venue_operation_policy_manager` | `VenueOperationPolicyManager` |
| Operational timing (entry windows, grace, session/capacity semantics) | `myeventlane_tickets.timed_entry_policy_manager` | `TimedEntryPolicyManager` |
| Session / multi-use / sequencing / bundle semantics | `myeventlane_tickets.session_entitlement_policy_manager` | `SessionEntitlementPolicyManager` |
| Scanner orchestration (QR parse, mutations, audit) | `mel_scanner.operation_manager` | `ScannerOperationManager` |
| Staff/API entry point | `myeventlane_tickets.ticket_checkin_service` | `TicketCheckinService` |

`ScannerOperationManager` remains the **only** scanner orchestration implementation. It **must** route gate actions through `EntitlementCapabilityRegistry`, apply **timed entry** and **session entitlement** decisions through **`VenueOperationPolicyManager`** (which delegates to **`TimedEntryPolicyManager`** and **`SessionEntitlementPolicyManager`**), and enrich `mel_redemption_log.metadata_json` using `VenueOperationPolicyManager` (nested key `venue_operation_integrity`, optional `operational_scan_policy` snapshot when the composed gate produced policy metadata).

## Operational action authority

- **Entitlement truth** lives on `myeventlane_ticket` entities (codes, limits, counts, status, fulfilment fields).
- **Type-level policy** (scanner mode, multi-use semantics, fulfilment flags) comes from `EntitlementCapabilityRegistry`.
- **Venue execution policy** (offline eligibility scaffold, conflict policy labels, deterministic operation envelopes) comes from `VenueOperationPolicyManager`.
- **Operational timing policy** (entry windows, grace, late/early semantics, session/capacity window metadata, scanner timing state) comes from `TimedEntryPolicyManager`, composed by `VenueOperationPolicyManager` and enforced on the scan path before mutations.
- **Session and multi-use progression policy** (sequencing, exhaustion, bundle/zone metadata, grouped redemption interpretation) comes from `SessionEntitlementPolicyManager`, composed by `VenueOperationPolicyManager` alongside timing; scanners must not duplicate these rules. See [session-multiuse-entitlement-convergence.md](./session-multiuse-entitlement-convergence.md).

QR payload contracts, ticket codes, public scanner JSON shapes, and routes are **unchanged**.

## Replay protection policy

Existing enforcement remains authoritative:

- admission replay via `STATUS_CHECKED_IN` and registry admission detection
- redemption limits via `TicketCapabilityManager::canBeScanned()` and persisted counts
- QR integrity via `TicketQrPayload`

This phase adds **non-authoritative scaffolding**:

- deterministic `operation_id` values inside staff-side redemption metadata
- `operation_fingerprint` for future duplicate-operation correlation (same ticket, gate, pre-mutation count, device, payload hash)
- `replay_token` (HMAC using site hash salt) stored **only** in `mel_redemption_log.metadata_json` — never returned on public guest surfaces

## Offline scaffolding boundaries

`VenueOperationPolicyManager::buildOperationDescriptor()` marks `offline_eligible` for current ticket-local scan paths because no live Commerce round-trip is required today. This is **not** a guarantee that all future gate products remain offline-safe without additional review.

**Explicitly out of scope for this phase:**

- offline sync queues or conflict resolution workers
- device attestation or scanner trust upgrades
- eventual consistency or multi-writer merge rules

## Deterministic operation semantics

`operation_id` values are derived from ticket id, gate action, operation timestamp, post-operation redemption count, and the duplicate fingerprint material. Identical inputs yield identical ids (used for idempotent log correlation tests).

## Observability

`OperationalIntegrityInspector::inspectOrder()` adds `artifacts.venue_operation_policy`, keyed by normalized entitlement type, with machine-only gate semantics, descriptors, offline eligibility flags, replay state summaries, and conflict policy tokens. It also adds **`artifacts.timed_entry_policy`** (per ticket id: policy snapshot + timing conflict codes from `TimedEntryPolicyManager`). The inspector remains **read-only**.

## Anti-patterns (forbidden)

- Scanner-specific entitlement `switch` / parallel action maps outside `EntitlementCapabilityRegistry` and `VenueOperationPolicyManager`
- Parallel timing / entry-window logic outside `TimedEntryPolicyManager` (scanners must not own clock policy)
- Parallel session, sequencing, or multi-use exhaustion logic outside `SessionEntitlementPolicyManager` / `VenueOperationPolicyManager`
- Entitlement logic that bypasses `EntitlementCapabilityRegistry` for operational policy
- A second replay-detection implementation that contradicts persisted ticket state checks
- Offline-only entitlement rules that are not also enforced on the online scanner path
- Mutating operational outcomes inside PDF, wallet, or view-model builders
- Writing `mel_redemption_log` rows without passing through `ScannerOperationManager` audit paths (bypasses integrity envelope construction)

## Related documentation

- [entitlement-capability-convergence.md](./entitlement-capability-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [timed-entry-capacity-convergence.md](./timed-entry-capacity-convergence.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
