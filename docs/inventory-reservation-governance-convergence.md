# Inventory reservation governance convergence (Phase 4A, Commit 2)

## Authority boundaries

Inventory reservation governance is a **read-only normalization layer**. It **does not** own Commerce inventory, stock quantities, warehouse execution, shipping, dispatch, or realtime allocation.

| Authority | Role |
| --- | --- |
| Commerce | Authoritative for purchasable inventory, order quantities, and inventory truth. |
| Scanner / check-in spine | Authoritative for redemption execution, admission execution, and consumption execution. |
| Fulfillment lifecycle (when present) | Authoritative for fulfillment lifecycle normalization consumed as signals. |
| Operational integrity inspector | Staff-safe merged diagnostics including `fulfillment_operational_signals`. |
| Operational continuity / venue policy | Topology, timing, session, and continuity descriptors consumed as **signals**, not re-evaluated. |
| Entitlement capability registry | Canonical capability semantics (`redeemable`, `multi_use`, `requires_fulfilment`, collection support). |
| Inventory reservation governance manager | Normalizes **reservation states**, **reservation types**, partial allocation, readiness/degradation/continuity **descriptors** for display. |

This layer **must not** reserve stock, decrement inventory, mutate Commerce stock, orchestrate warehouse systems, trigger notifications, trigger dispatch, manage shipping, or allocate inventory in realtime.

## Canonical reservation states

Normalized strings (single source in `InventoryReservationGovernanceManager`): `unreserved`, `reserved`, `partially_reserved`, `allocated`, `prepared`, `ready_for_collection`, `degraded`, `exhausted`, `fulfilled`, `released`, `expired`, `cancelled`.

Entity `fulfilment_status`, redemption counts/limits, issuance alignment, and continuity rollup stress map into this vocabulary for **visibility only**.

## Canonical reservation types

`merch`, `hospitality`, `food_drink`, `parking`, `vip_package`, `equipment`, `cloakroom` (reserved for future entitlement keys), `timed_pickup`, `digital_redemption`.

Types are derived from normalized entitlement types, redemption limits, and timed-entry scanner state strings already materialized on inspector rows.

## Allocation semantics

Allocation summaries compose from:

- reservation type and normalized reservation state,
- redemption count / limit integers from `fulfillment_operational_signals`,
- partial allocation flag when capabilities mark `redeemable` + `multi_use` and `0 < redemption_count < redemption_limit`.

No stock decrement, warehouse slot assignment, or realtime allocation occurs in this layer.

## Reservation lifecycle semantics

Lifecycle ordering for audit continuity follows the canonical state list (low → progressed). Workspace audit timelines emit `lane_state`, `recorded_at_unix`, and `audit_note` strings only.

## Degradation semantics

Rollup-level issuance misalignment, recovery mismatch, invalid guest continuity, or non-valid canonical PDF readiness can surface **degraded** reservation visibility. Degradation descriptors are composed in PHP services; Twig renders pre-built strings only.

## Continuity semantics

Reservation continuity projections compose guest continuity statuses observed in merged rollups and per-ticket continuity mode strings from `artifacts.operational_continuity`. Continuity policy is **not** re-evaluated in reservation governance.

## Partial allocation convergence

When capabilities mark `redeemable` + `multi_use` and partial redemption counts apply, rows are labeled `partially_reserved` for reservation visibility. Exhausted (`redemption_count >= redemption_limit`) and fulfilled terminal states take precedence.

## Workspace (`/admin/mel/operations`)

Sections are gated by **`govern mel inventory reservations`** (separate from escalation, ownership, coordination, and fulfillment permissions). Twig renders **pre-built cards and timelines only**; all arithmetic and ordering happen in PHP services.

Services:

| Service ID | Class |
| --- | --- |
| `myeventlane_tickets.inventory_reservation_governance_manager` | `InventoryReservationGovernanceManager` |
| `myeventlane_tickets.inventory_reservation_projection_builder` | `InventoryReservationProjectionBuilder` |
| `myeventlane_tickets.inventory_reservation_audit_projector` | `InventoryReservationAuditProjector` |

## Forbidden patterns

Do not add: warehouse systems, stock orchestration, shipping execution, dispatch orchestration, notifications, websocket infrastructure, realtime reservation execution, or duplicate fulfillment/scanner/continuity/entitlement policy stacks in Twig or ad-hoc controllers.

Do not expose: `replay_token`, `qr_payload`, device fingerprints, raw scanner payloads, or customer-sensitive identifiers in reservation audit projections.

## Related documentation

- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-coordination-state-convergence.md](./operational-coordination-state-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
