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

## Canonical lifecycle states

Normalized strings (single source in `FulfillmentLifecycleManager`): `pending`, `prepared`, `ready`, `partially_fulfilled`, `fulfilled`, `collected`, `consumed`, `expired`, `failed`, `cancelled`.

Entity `fulfilment_status` maps into this vocabulary; admission **checked-in** rows map to `fulfilled` without duplicating scanner policy. Rollup-level issuance/recovery stress can surface as `failed` **visibility only**.

## Canonical fulfillment types

`admission`, `merch_pickup`, `hospitality`, `parking`, `vip_access`, `equipment`, `cloakroom` (reserved for future entitlement keys; currently unused), `multi_redeem`, `digital_access`.

Types are derived from normalized entitlement types plus redemption limits (e.g. add-ons with `redemption_limit > 1` → `multi_redeem`).

## Redemption normalization

Redemption **execution summaries** are composed from:

- entitlement scanner action token (from the capability registry),
- timed/session scanner state strings already materialized on continuity rows (`timing_session_composition_refs`),
- redemption count / limit integers from ticket rows.

No replay tokens, QR payloads, device fingerprints, or raw scanner payloads are emitted.

## Pickup normalization

Pickup summaries use `requires_fulfilment` from capabilities plus normalized lifecycle state and the raw fulfilment row status label for staff continuity. There are **no** “complete pickup” or fulfilment mutation controls in the workspace shell.

## Partial fulfillment

When capabilities mark `redeemable` + `multi_use` and `0 < redemption_count < redemption_limit`, rows are labeled `partially_fulfilled` for lifecycle visibility. Consumed (`fulfilment_status = redeemed`) takes precedence over partial semantics.

## Consumption semantics

Consumption descriptors distinguish: not redeemable, partial consumption visibility, complete consumption visibility, pending consumption.

## Workspace (`/admin/mel/operations`)

Sections are gated by **`govern mel fulfillment lifecycle`** (separate from escalation, ownership, and coordination permissions). Twig renders **pre-built cards and timelines only**; all arithmetic and ordering happen in PHP services.

## Forbidden patterns

Do not add: inventory engines, warehouse orchestration, shipping execution, notifications, realtime/WebSocket coordination, or duplicate scanner/entitlement/continuity policy stacks in Twig or ad-hoc controllers.

## Related documentation

- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
- [operational-coordination-state-convergence.md](./operational-coordination-state-convergence.md)
- [operational-observability.md](./operational-observability.md)
- [offline-reconciliation-operational-continuity.md](./offline-reconciliation-operational-continuity.md)
- [operational-assignment-ownership-governance.md](./operational-assignment-ownership-governance.md)
