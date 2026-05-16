# Operational merchandise architecture (Phase 4D)

## Authority

| Layer | Owns |
| --- | --- |
| **Drupal Commerce** | Products, variations, pricing, carts, orders, checkout line items. |
| **MEL operational spine** | Readiness semantics, fulfillment lifecycle vocabulary, reservation governance, entitlement projections, scanner execution, operational continuity. |
| **Operational merchandise services (`myeventlane_commerce`)** | **Read-only normalization** of operational Commerce product payloads, Event Studio `operational_merchandise` authoring, customer-safe projections, and **purchase composition** grouping for UI. They **do not** decrement stock, allocate inventory, run warehouses, orchestrate shipping, execute scans, mutate entitlements, or emit QR/replay/scanner secrets. |

## Commerce product types (architecture)

Dedicated Commerce **product types** (and matching variation types) isolate non-ticket operational catalog from ticket entitlement products:

- `operational_merchandise` — physical merch, pickup-oriented messaging.
- `operational_bundle` — grouped operational packages (still line-item backed).
- `hospitality_package` — hospitality expectation copy at product level.
- `timed_collection_product` — timed window / slot semantics for collection.

**Ticket products remain isolated**; ticket issuance and entitlement authority are unchanged.

## Field contract: `field_mel_operational_product`

Stored on operational product bundles as JSON (normalized by `OperationalMerchandiseManager`).

Top-level contract flag: `mel_operational_product: true`.

**Allowed keys (whitelist):** `operational_product_type`, `fulfillment_mode`, `reservation_mode`, `pickup_mode`, `readiness_mode`, `continuity_mode`, `capability_reference`, `customer_visibility`, `collection_rules`, `hospitality_rules`, `operational_summary`, `operational_chips`.

**Forbidden keys (stripped recursively):** `qr_payload`, `replay_token`, `inventory_quantity`, `stock_level`, `warehouse_ids`, `shipment_state`, `scanner_tokens`, `scanner_secret`, `device_fingerprint`, and similar execution material.

## Event Studio: `operational_merchandise` authoring

Persisted under `field_mel_op_capabilities` as part of the operational capabilities document. Vendors link **operational** products to an event (`linked_products`), assign **roles** (`merch_pickup`, `operational_bundle`, `hospitality`, `timed_collection`, `parking`), and optional **bundle_group** labels for composition. `OperationalMerchandiseManager::normalizeEventMerchandiseAuthoring()` validates product IDs, enforces operational bundles, requires `field_event` alignment to the event, and merges normalized `product_payload` from the product field. Vendors may also **create** new operational products from Event Studio on explicit save via the [Vendor Product Creation Wizard](vendor-product-creation-wizard.md) (Commerce entities only through `VendorOperationalProductCreationManager`).

## Purchase composition

`OperationalPurchaseCompositionManager` builds `mel_operational_purchase_composition` documents: grouped **merchandise**, **hospitality**, **timed_collection**, **bundles**, and **parking** rows from **Commerce order items** (or event preview from authoring). It attaches read-only **governance** summaries via `OperationalMerchandiseGovernanceManager` (severity, degraded rows, continuity conflicts). **No** checkout flow mutation and **no** bypass of Commerce order items.

## Customer surfaces

Theme and checkout-flow templates render the composition strip next to existing **customer operational experience** and **fulfillment execution** strips. Twig/CSS are presentation-only; optional JS is progressive enhancement only.

**Operational checkout orchestration** (`mel_operational_checkout`, `OperationalCheckoutOrchestrationManager`) adds a grouped **checkout plan** card fed only from purchase composition output. It does not replace merchandise normalization or composition classification; see [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md).

**Phase 4F — Customer booking add-ons:** the paid event book page can embed `EventOperationalAddonCartForm` when `EventOperationalAddonBuilder` finds eligible operational products linked via `field_event`; see [customer-operational-addons-booking.md](./customer-operational-addons-booking.md).

## Anti-patterns

- Duplicating `FulfillmentLifecycleManager`, `InventoryReservationGovernanceManager`, scanner authority, or entitlement issuance in merchandise services or Twig.
- Pushing warehouse IDs, shipment state, stock counts, or scanner tokens through operational merchandise variables.
- Treating composition or governance output as a source of truth for fulfillment execution or inventory.

## Related documentation

- [vendor-productisation-studio.md](./vendor-productisation-studio.md) — Event Studio vendor authoring for operational productisation (Phase 4E).
- [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md)
- [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md)
- [operational-fulfillment-execution-convergence.md](./operational-fulfillment-execution-convergence.md)
- [customer-operational-commerce-experience.md](./customer-operational-commerce-experience.md)
- [vendor-operational-capability-studio.md](./vendor-operational-capability-studio.md)
- [fulfillment-lifecycle-convergence.md](./fulfillment-lifecycle-convergence.md)
- [operational-commerce-capability-linking.md](./operational-commerce-capability-linking.md)
- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
