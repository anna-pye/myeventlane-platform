# Vendor Productisation Studio

## Purpose

The Vendor Productisation Studio is the Event Studio surface where vendors **author** operational Commerce offers for an event: merchandise, hospitality packages, timed collection, parking add-ons, and operational bundles. It is **authoring and linkage** (plus **validated catalog create** on explicit save via the wizard).

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
- `myeventlane_event_studio.vendor_operational_product_creation_manager` — validated operational Commerce product + variation creation on **explicit productisation save** (see [Vendor Product Creation Wizard](vendor-product-creation-wizard.md)).

## Commerce linkage boundary

- Vendors may **select existing** Commerce products that belong to the event (`field_event`) and use **operational** product bundles (`OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES`), or **create** new operational products via the wizard on explicit save (see [Vendor Product Creation Wizard](vendor-product-creation-wizard.md)).
- `OperationalCapabilityCommerceLinkManager` validates product/store/variation relationships **read-only** for linkage checks (creation uses the same resolver for store context; catalog writes happen only in `VendorOperationalProductCreationManager`).

## Save boundary

- Saves flow through `OperationalCapabilityStudioManager::persistToEvent()` → event field JSON only.
- **No** ad-hoc Commerce `->save()` on products or variations from productisation **services**; the dedicated creation manager performs **only** the validated create path on explicit form submit.
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

- Inventory execution and stock decrement.
- Shipping orchestration and warehouse planning.
- Staff fulfilment tooling and scanner policy editing (remains in operational tooling, not vendor authoring).
