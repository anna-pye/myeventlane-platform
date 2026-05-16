# Vendor Productisation Studio

## Purpose

The Vendor Productisation Studio is the Event Studio surface where vendors **author** operational Commerce offers for an event: merchandise, hospitality packages, timed collection, parking add-ons, and operational bundles. It is **authoring and linkage only**.

## Authority

- **Commerce** owns products, variations, pricing, carts, checkout, and orders.
- **Event node** stores a JSON document in `field_mel_op_capabilities` (schema version 2) including `operational_merchandise` with:
  - `linked_products` — normalized links derived from productisation rows and legacy JSON (Phase 4D).
  - `productisation_items` — vendor copy, visibility hints, and Commerce IDs for Studio round-trip.

## Productisation types

| Type | Merchandise role (derived link) | Capability type used for linkage validation |
|------|--------------------------------|---------------------------------------------|
| `merchandise` | `merch_pickup` | `merch_pickup` |
| `hospitality_package` | `hospitality` | `hospitality_access` |
| `timed_collection` | `timed_collection` | `timed_collection` |
| `parking_addon` | `parking` | `parking_access` |
| `operational_bundle` | `operational_bundle` | `vip_access` (bundle umbrella for Commerce validation) |

## Services

- `myeventlane_event_studio.vendor_productisation_studio_manager` — payload normalization, forbidden-key stripping, linkage validation delegation, merge of derived `linked_products` before `OperationalMerchandiseManager::normalizeEventMerchandiseAuthoring()`.
- `myeventlane_event_studio.vendor_productisation_studio_builder` — render arrays, cards, CTAs, validation summary, customer preview strip (includes the same customer-safe capability projection as storefront via `CustomerOperationalCommerceExperienceBuilder`).

## Commerce linkage boundary

- Vendors may only **select existing** Commerce products that belong to the event (`field_event`) and use **operational** product bundles (`OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES`).
- `OperationalCapabilityCommerceLinkManager` validates product/store/variation relationships **read-only** (loads entities to verify, does not mutate catalog).

## Save boundary

- Saves flow through `OperationalCapabilityStudioManager::persistToEvent()` → event field JSON only.
- **No** Commerce `->save()` on products or variations from productisation services.
- **No** checkout, cart, order, entitlement, or scanner mutation.

## Autosave boundary

- Autosave uses `EventStudioAutosaveService` (private tempstore) keyed by event + section (`merchandise`).
- Autosave stores draft `mel` fragments only; it does **not** save the node or Commerce entities.

## Customer preview boundary

- Per-row product preview uses `OperationalMerchandiseManager::buildCustomerSafeProductPresentation()` (same contract as operational commerce).
- Capability strip reuses `CustomerOperationalCommerceExperienceBuilder::buildFromOperationalDocument()` via Twig include of `mel-customer-operational-experience` (no duplicated projection rules in Twig).

## Forbidden execution boundaries

Authoring payloads must never carry execution tokens or inventory/shipment semantics. Stripped keys include: `qr_payload`, `replay_token`, `scanner_*`, `inventory_quantity`, `stock_*`, `warehouse_*`, `shipment_*`, `entitlement_token`, `device_fingerprint`, etc. (see `VendorProductisationStudioManager::FORBIDDEN_PRODUCTISATION_KEYS`).

## Future phases (out of scope here)

- Product creation wizard inside Event Studio.
- Inventory execution and stock decrement.
- Shipping orchestration and warehouse planning.
- Staff fulfilment tooling and scanner policy editing (remains in operational tooling, not vendor authoring).
