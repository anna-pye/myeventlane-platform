# Operational entitlement capability convergence (Phase 4A, Commit 3)

## Authority boundaries

Operational entitlement capability convergence is a **read-only normalization layer**. It **does not** own Commerce inventory, warehouse execution, shipping, dispatch, hospitality orchestration, scanner execution, or redemption mutation.

| Authority | Role |
| --- | --- |
| Entitlement capability registry | Canonical capability semantics (`redeemable`, `multi_use`, `requires_fulfilment`, scanner action tokens, fulfilment modes). |
| Scanner / check-in spine | Authoritative for admission execution, redemption execution, and consumption execution. Capability convergence exposes **scanner visibility** only. |
| Inventory reservation governance | Authoritative for reservation state/type normalization consumed as **composition inputs** — not re-evaluated. |
| Fulfillment operational signals | Per-ticket `fulfilment_status`, redemption counts, and ticket status from `OperationalIntegrityInspector` consumed as **signals**. |
| Operational continuity policy | Continuity mode descriptors consumed as **signals** from merged inspector artifacts. |
| Operational entitlement capability manager | Normalizes **capability types**, **capability states**, readiness/degradation/continuity **descriptors**, fulfillment/reservation capability summaries, and execution descriptors for display. |

This layer **must not** reserve stock, decrement inventory, execute fulfillment, dispatch products, ship items, orchestrate hospitality systems, trigger notifications, introduce new scanner execution paths, or bypass scanner authority.

## Canonical capability types

Normalized strings (single source in `OperationalEntitlementCapabilityManager`):

`admission`, `merch_pickup`, `hospitality_access`, `food_drink_redemption`, `parking_access`, `vip_access`, `cloakroom_retrieval`, `timed_collection`, `digital_redemption`.

Types are derived from normalized entitlement types, reservation type hints from reservation governance, redemption limits, and timed-entry scanner state strings already materialized on inspector rows.

## Canonical capability states

`unavailable`, `available`, `reserved`, `prepared`, `ready`, `partially_ready`, `degraded`, `exhausted`, `redeemed`, `fulfilled`, `expired`, `cancelled`.

Capability states compose from reservation governance states (mapped without re-evaluating reservation rows), fulfillment operational signals (`fulfilment_status`), and continuity mode descriptors. Terminal redeemed visibility surfaces when `fulfilment_status` is `redeemed`.

## Fulfillment-capability composition

Fulfillment-capability summaries compose from:

- per-ticket `fulfilment_status` in `fulfillment_operational_signals`,
- entitlement capability map flags (`requires_fulfilment`, `supports_collection`),
- normalized capability state after reservation mapping.

No stock decrement, warehouse slot assignment, dispatch, or shipping execution occurs in this layer.

## Reservation-capability composition

Reservation-capability summaries compose from `InventoryReservationGovernanceManager::composeReservationReadModel()` outputs:

- `normalized_reservation_state` and `reservation_type` per ticket row,
- allocation fraction and reservation type tallies from the reservation rollup.

Reservation logic is **not** duplicated; the capability manager maps reservation tokens into capability vocabulary.

## Degradation semantics

Degradation descriptors inherit reservation governance rollup stress signals and add normalized degraded capability row counts. Degradation is composed in PHP services; Twig renders pre-built strings only.

## Readiness semantics

Readiness projections count rows in `prepared`, `ready`, `partially_ready`, and `available` capability states, plus type-specific readiness for merch pickup, hospitality access, and timed collection. Execution readiness is **visibility only**.

## Continuity semantics

Capability continuity projections compose guest continuity statuses and per-ticket continuity mode strings from merged rollups. Continuity policy is **not** re-evaluated in capability convergence.

## Scanner authority

Scanner remains authoritative for admission, redemption, and consumption execution. Capability convergence exposes read-only `scanner_visibility` strings (scanner action token, redeemable, multi_use) derived from `EntitlementCapabilityRegistry` — it does not mutate redemption truth or introduce alternate execution paths.

## Workspace (`/admin/mel/operations`)

Sections are gated by **`govern mel operational capabilities`** (separate from escalation, reservation, coordination, and fulfillment lifecycle permissions). Twig renders **pre-built cards and timelines only**; all arithmetic and ordering happen in PHP services.

Services:

| Service ID | Class |
| --- | --- |
| `myeventlane_tickets.operational_entitlement_capability_manager` | `OperationalEntitlementCapabilityManager` |
| `myeventlane_tickets.operational_capability_projection_builder` | `OperationalCapabilityProjectionBuilder` |
| `myeventlane_tickets.operational_capability_audit_projector` | `OperationalCapabilityAuditProjector` |

Workspace section IDs:

- `capability_governance_summary`
- `capability_lifecycle_cards`
- `capability_readiness_visibility`
- `capability_lifecycle_audit_timeline`

## Customer safety

Customer-facing capability projections (when surfaced outside the staff workspace) must remain coarse: readiness hints and continuity hints only. Do not expose internal governance, escalation state, replay semantics, fingerprints, or topology internals.

## Forbidden patterns

Do not add: warehouse systems, stock orchestration, shipping execution, dispatch orchestration, hospitality orchestration, notifications, websocket infrastructure, realtime inventory, POS systems, or duplicate reservation/fulfillment/continuity/scanner policy stacks in Twig or ad-hoc controllers.

Do not expose: `replay_token`, `qr_payload`, device fingerprints, raw scanner payloads, or customer-sensitive identifiers in capability audit projections.

## Related documentation

- [inventory-reservation-governance-convergence.md](./inventory-reservation-governance-convergence.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
- [entitlement-capability-convergence.md](./entitlement-capability-convergence.md)
