# Operational purchase composition convergence (read-only)

## Scope

**Purchase composition** surfaces (checkout sidebar, cart, order detail, My Tickets) assemble **Commerce order context** with **operational expectation copy** from Event Studio and tickets-layer read-models. This document anchors how those surfaces stay **composition-only**: they **delegate** normalization to authoritative managers and attach sibling projections instead of re-deriving policy.

## Fulfillment execution sibling

Phase 4C adds **`OperationalFulfillmentExecutionProjectionBuilder::buildCustomerRenderArray()`** as a **separate** themed strip (`mel_operational_fulfillment_execution_customer`) built from `OperationalIntegrityInspector` merged diagnostics and `OperationalFulfillmentExecutionManager`. Composition templates must **not** merge execution JSON inside Event Studio blocks or duplicate lifecycle/reservation/scanner logic.

## Forbidden

- Re-mapping entitlement or reservation state in Twig or checkout JS.
- Stock decrement, warehouse identifiers, shipment fields, or scanner secrets in composition variables.

## Related documentation

- [operational-fulfillment-execution-convergence.md](./operational-fulfillment-execution-convergence.md)
- [customer-operational-commerce-experience.md](./customer-operational-commerce-experience.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
