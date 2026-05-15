# Operational incident and recovery workspace convergence (Phase 3A, Commit 2)

## Purpose

Extend the staff-only venue operations workspace (`/admin/mel/operations`) with a **read-only operational incident and recovery layer**: triage cards, severity rollup, recovery visibility, and extended integrity projections **without** a second policy stack, **without** mutating issuance or scanner authority, and **without** realtime infrastructure.

## Architecture

| Component | Service ID | Responsibility |
| --- | --- | --- |
| `OperationalIncidentNormalizer` | `myeventlane_tickets.operational_incident_normalizer` | Machine tokens for incident types; severity tokens `info`, `warning`, `critical` only. |
| `OperationalIncidentBuilder` | `myeventlane_tickets.operational_incident_builder` | Bounded event sampling (tickets → orders), `OperationalIntegrityInspector::inspectOrder()` aggregation, incident cards, severity rollup, extended integrity cards, bounded redemption-log **deny counts** (result token only). |
| `OperationalRecoverySummaryBuilder` | `myeventlane_tickets.operational_recovery_summary_builder` | Recovery visibility cards from merged inspector domains + merged compatibility worst-path rollup. |
| `OperationalIncidentAccessChecker` | `myeventlane_tickets.operational_incident_access` | Route access: anonymous denied; requires `view mel venue operations workspace` (same staff boundary as the workspace shell). |
| `OperationalWorkspaceBuilder` | `myeventlane_tickets.operational_workspace_builder` | Composes legacy sections with the new incident / recovery / severity / integrity-execution sections; strips sensitive keys before theming. |

`OperationalIntegrityInspector` remains the **single** orchestration point for timing, session, zone, continuity, occupancy, device identity, issuance, artifacts, recovery, compatibility, and guest continuity. Incident surfaces **consume** that read model and redemption audit metadata; they do **not** re-run scanner evaluation or recompute entitlement semantics.

## Recovery visibility model

Recovery cards summarize:

- Completion states and mismatch flags from merged `recovery` domain.
- Worst-path compatibility signals (`ticket_pdf_operational_path`, `wallet_resolution_surface`, `order_item_pdf_surface`) merged across sampled orders.
- Worst artifact readiness tokens (`canonical_pdf_readiness`, `wallet_route_scaffold`, `attachment_continuity`, `qr_payload_operational`).
- Guest continuity statuses observed (no purchaser PII, no email fields).

## Incident normalization rules

- **Incident types** are lowercase alphanumeric + underscore tokens (e.g. `orphan_ticket_rows`, `issuance_mismatch`, `failed_scan_attempt`).
- **Severity** is one of `info`, `warning`, `critical` derived from incident context (e.g. issuance misalignment across orders → `critical`; bounded deny counts → `warning` when non-zero).
- **Failed scans** aggregate `mel_redemption_log` rows with `metadata_json.ok = false`, counting **normalized `result` tokens** only. Raw metadata envelopes are never passed to Twig.

## Read-only guarantees

- No ticket, order, or state mutations from builders or workspace composition.
- No automated repair, reissue, queue orchestration, or alternate entitlement stores.
- No websocket, polling, or “live” sync assumptions.

## Orchestration boundaries

- **In scope:** inspector read models, registry / gate **catalog** descriptors (`VenueOperationPolicyManager::describeEntitlementGateSemantics` for types observed in the sample), redemption audit tallies.
- **Out of scope:** `ScannerOperationManager` scan paths, `TicketIssuer` mutations, wallet URL minting, QR generation, entitlement map duplication in controllers/Twig/JS.

## Forbidden patterns

- Re-evaluating gate policy in Twig, JS, or thin controllers.
- Surfacing `replay_token`, HMAC material, raw QR payloads, continuity fingerprints, or purchaser email to the workspace theme.
- Vendors or customers routing to the staff workspace theme or incident builders without the staff permission.

## Related documents

- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-incident-lifecycle-convergence.md](./operational-incident-lifecycle-convergence.md) — Phase 3A Commit 3 coordination entity and lifecycle services
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
