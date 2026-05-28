# Operational fulfillment execution convergence (Phase 4C)

## Execution authority

Fulfillment **execution orchestration** is a **read-only projection layer** over the existing operational spine. It **does not** own entitlement truth, scanner execution, reservation mutation, inventory mutation, issuance, warehouse systems, shipping, or notifications.

| Authority | Role |
| --- | --- |
| `FulfillmentLifecycleManager` | Canonical lifecycle states, fulfillment types, readiness/completion descriptors, and ticket-level read-models consumed as **inputs only**. |
| `OperationalFulfillmentExecutionManager` | Normalizes **execution semantics** (`mel_operational_fulfillment_execution` contract): execution type, state buckets, collection/redemption **labels**, chips, timelines, customer-safe summaries. Strips forbidden keys and **scanner_action** tokens from execution-facing strings. |
| `OperationalFulfillmentExecutionGovernanceManager` | Read-only **severity / degradation / stall / continuity conflict** signals for staff governance cards. |
| `OperationalFulfillmentExecutionProjectionBuilder` | Staff workspace sections and customer render arrays (`mel_operational_fulfillment_execution_customer`). |
| `OperationalFulfillmentExecutionAuditProjector` | Execution audit timeline rows (lane state, completion, collection notes) without replay or QR material. |
| Scanner / check-in spine | **Exclusive** authority for scan outcomes, redemption mutation, and device policy. Execution layers must never bypass or re-evaluate scanner gates. |

## Execution contract (`mel_operational_fulfillment_execution`)

Top-level document flag: `mel_operational_fulfillment_execution: true`.

Per-execution rows (whitelisted keys only; enforced in `OperationalFulfillmentExecutionManager::whitelistExecution()`):

- `execution_id`, `execution_type`, `fulfillment_type`, `execution_state`, `readiness_state`, `completion_state`
- `collection_state`, `redemption_state`, `execution_mode`, `continuity_mode`, `reservation_state`
- `operational_summary`, `execution_chips`, `execution_timeline`, `customer_visibility`, `execution_readiness`, `operational_completion`

**Supported execution types** (normalized projection vocabulary, mapped from lifecycle fulfillment types): `merch_pickup`, `timed_collection`, `hospitality_redemption`, `parking_access`, `vip_access`, `digital_redemption`, `cloakroom_collection`, `food_drink_redemption`, plus internal `admission_access` / `operational_execution` where the lifecycle spine still classifies those rows.

**Forbidden in this contract:** `qr_payload`, `replay_token`, scanner secrets, device fingerprints, stock quantities, warehouse identifiers, shipment internals, raw inventory execution, entitlement issuance mutation.

`reservation_state` is intentionally a **non-authoritative placeholder** (`reservation_authority_external`) so reservation normalization stays in `InventoryReservationGovernanceManager` without duplication.

## Completion semantics

- `completion_state` buckets lifecycle into `terminal_complete`, `terminal_closed`, or `in_progress` for cross-line rollup copy.
- `operational_completion` exposes customer-safe terminal labels (e.g. collected, consumed, fulfilled, ready, needs attention, in progress).

## Operational progression

Execution timelines are **ordinal slices** of canonical lifecycle order from `FulfillmentLifecycleManager::allCanonicalStates()` up to the current normalized state. They describe **progress hints**, not realtime orchestration.

## Redemption progression

`redemption_state` and `operational_summary` may incorporate lifecycle redemption summaries after **scanner_action** stripping so customers see timing/session hints without scanner policy tokens.

## Collection continuity

Collection visibility cards read `collection_state` from lifecycle **collection semantics** descriptors; continuity join uses guest continuity statuses from merged inspector payloads — **signals only**, no continuity policy re-evaluation.

## Hospitality execution

Hospitality rows map to `execution_type = hospitality_redemption` with informational chips (e.g. VIP hospitality activates at the venue). No hospitality POS, stock, or fulfillment execution.

## Staff workspace (`govern mel fulfillment execution`)

Gated read-only sections (no execution buttons):

- `execution_governance_summary`
- `fulfillment_execution_cards`
- `operational_collection_visibility`
- `execution_readiness_visibility`
- `operational_execution_audit_timeline` (from audit projector)

Permission: **`govern mel fulfillment execution`** (`restrict_access: true`), combined with **`view mel venue operations workspace`** for `/admin/mel/operations`.

## Customer surfaces

Theme preprocess attaches `mel_operational_fulfillment_execution_customer` on My Tickets, order detail, checkout completion, cart, commerce order user view, and checkout flow templates where an order is available. Twig/CSS/JS are **presentation-only**; orchestration stays in PHP services.

## Phase 4D sibling — operational merchandise

**`mel_operational_purchase_composition`** (operational Commerce product grouping) is a **separate** read-only strip from execution. It uses `OperationalMerchandiseManager` / `OperationalPurchaseCompositionManager` and must **not** be merged into execution JSON or used to re-derive lifecycle or scanner semantics. See [operational-merchandise-architecture.md](./operational-merchandise-architecture.md) and [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md).

## Anti-patterns

- Duplicating capability mapping, reservation logic, continuity evaluation, fulfillment readiness, or scanner policy in execution services or Twig.
- Emitting warehouse, shipping, stock decrement, or realtime allocation semantics from this layer.
- Adding websocket push, notification send, or scanner bypass hooks from execution projections.

**Operational checkout orchestration** (`mel_operational_checkout`) is a **separate** Commerce presentation strip built from purchase composition; it must not merge execution contracts or duplicate fulfillment timelines. See [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md).

## Related documentation

- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
- [inventory-reservation-governance-convergence.md](./inventory-reservation-governance-convergence.md)
- [operational-entitlement-capability-convergence.md](./operational-entitlement-capability-convergence.md)
- [customer-operational-commerce-experience.md](./customer-operational-commerce-experience.md)
- [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md)
- [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md)
- [issuance-pipeline.md](./issuance-pipeline.md)
- [venue-operations-workspace-convergence.md](./venue-operations-workspace-convergence.md)
