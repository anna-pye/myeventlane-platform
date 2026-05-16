# Operational purchase composition convergence (read-only)

## Scope

**Purchase composition** surfaces (checkout sidebar, cart, order detail, My Tickets) assemble **Commerce order context** with **operational expectation copy** from Event Studio and tickets-layer read-models. This document anchors how those surfaces stay **composition-only**: they **delegate** normalization to authoritative managers and attach sibling projections instead of re-deriving policy.

## Fulfillment execution sibling

Phase 4C adds **`OperationalFulfillmentExecutionProjectionBuilder::buildCustomerRenderArray()`** as a **separate** themed strip (`mel_operational_fulfillment_execution_customer`) built from `OperationalIntegrityInspector` merged diagnostics and `OperationalFulfillmentExecutionManager`. Composition templates must **not** merge execution JSON inside Event Studio blocks or duplicate lifecycle/reservation/scanner logic.

## Phase 4D — Operational merchandise composition

**`OperationalPurchaseCompositionManager`** (with **`OperationalMerchandiseManager`** and **`OperationalMerchandiseGovernanceManager`**) produces **`mel_operational_purchase_composition`**: grouped operational Commerce lines (merch, hospitality, timed collection, bundles, parking) from **real order items** or **event authoring preview**, plus read-only governance severity. It remains **non-mutating** for carts, orders, checkout transitions, entitlements, inventory, warehouses, shipping, and scanners. See [operational-merchandise-architecture.md](./operational-merchandise-architecture.md).

**`OperationalCheckoutOrchestrationManager`** reshapes the same composition document into **`mel_operational_checkout`** for cart/checkout/order/event surfaces (grouped cards, readiness rollup, pickup slice, continuity hints, deterministic guidance). It must not re-classify lines or mutate Commerce; see [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md).

## Forbidden

- Re-mapping entitlement or reservation state in Twig or checkout JS.
- Stock decrement, warehouse identifiers, shipment fields, or scanner secrets in composition variables.

## Related documentation

- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md)
- [operational-fulfillment-execution-convergence.md](./operational-fulfillment-execution-convergence.md)
- [customer-operational-commerce-experience.md](./customer-operational-commerce-experience.md)
- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
