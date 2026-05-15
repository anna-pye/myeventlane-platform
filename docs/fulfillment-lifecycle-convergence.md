# Fulfillment lifecycle convergence (Phase 4A)

## Authority boundaries

Fulfillment lifecycle visibility is a **read-only operational projection layer**. It **does not** own entitlement truth, scanner execution, redemption mutation, incident handling, or workspace mutation.

| Authority | Role |
| --- | --- |
| Entitlement capability registry | Canonical capability semantics (`redeemable`, `multi_use`, `requires_fulfilment`, scanner action tokens). |
| Scanner / check-in spine | Canonical admission validation, redemption execution, and consumption events. |
| Operational integrity inspector | Staff-safe merged diagnostics (issuance, artifacts, recovery, guest continuity, fulfillment operational signals). |
| Operational continuity / venue policy | Topology, timing, session, and continuity descriptors consumed as **signals**, not re-evaluated. |
| Fulfillment lifecycle manager | Normalizes **lifecycle states**, **fulfillment types**, partial fulfillment, readiness/completion/degradation **descriptors** for display. |

This layer **must not** issue tickets, change entitlements, change scanner outcomes, enqueue orchestration, send notifications, reserve inventory, or execute shipping.

## Relationship to reservation governance

**Inventory reservation governance** (Phase 4A Commit 2) consumes fulfillment operational signals and continuity semantics as **inputs** for reservation state normalization. Reservation governance does **not** duplicate fulfillment lifecycle logic; it projects reservation-specific vocabulary (`reserved`, `allocated`, `ready_for_collection`, etc.) for staff visibility. See [inventory-reservation-governance-convergence.md](./inventory-reservation-governance-convergence.md).

## Canonical lifecycle states

Normalized strings (single source in `FulfillmentLifecycleManager`): `pending`, `prepared`, `ready`, `partially_fulfilled`, `fulfilled`, `collected`, `consumed`, `expired`, `failed`, `cancelled`.

Entity `fulfilment_status` maps into this vocabulary; admission **checked-in** rows map to `fulfilled` without duplicating scanner policy. Rollup-level issuance/recovery stress can surface as `failed` **visibility only**.

## Canonical fulfillment types

`admission`, `merch_pickup`, `hospitality`, `parking`, `vip_access`, `equipment`, `cloakroom` (reserved for future entitlement keys; currently unused), `multi_redeem`, `digital_access`.

Types are derived from normalized entitlement types plus redemption limits (e.g. add-ons with `redemption_limit > 1` → `multi_redeem`).

## Forbidden patterns

Do not add: inventory engines, warehouse orchestration, shipping execution, notifications, realtime/WebSocket coordination, or duplicate scanner/entitlement/continuity policy stacks in Twig or ad-hoc controllers.

## Related documentation

- [inventory-reservation-governance-convergence.md](./inventory-reservation-governance-convergence.md)
- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-coordination-state-convergence.md](./operational-coordination-state-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
