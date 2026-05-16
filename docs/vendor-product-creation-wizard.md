# Vendor Product Creation Wizard

## Purpose

The **Vendor Product Creation Wizard** is an Event Studio authoring path that lets vendors **create and link** operational Commerce products (products + variations) from the productisation workflow. It runs **only on explicit Save** of the productisation form — never during autosave.

## Authority

- **Commerce** remains the system of record for catalog entities, carts, checkout, and orders.
- **Creation** is delegated to `myeventlane_event_studio.vendor_operational_product_creation_manager` (`VendorOperationalProductCreationManager`), which uses `commerce_product` and `commerce_product_variation` APIs only (no parallel catalog).
- **Event node** still stores authoring in `field_mel_op_capabilities` → `operational_merchandise.productisation_items` and derived `linked_products` after normalization.

## Vendor store boundary

- Target store is resolved by `OperationalCapabilityCommerceLinkManager::resolveStoreIdForOperationalProductCreation()` (vendor store first, then `field_event_store` on the event when no vendor store applies).
- Non-admin accounts may not create when the resolved store does not match `resolveVendorStoreIdForEvent()` when a vendor store is present (prevents cross-vendor store assignment).
- Currency for vendors must match the store default currency unless the account may administer nodes.

## Supported productisation types

Wizard rows map to existing operational Commerce bundles (see `OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES` and `myeventlane_commerce_update_10004`):

| Productisation type | Commerce product bundle | Variation bundle |
|---------------------|-------------------------|------------------|
| `merchandise` | `operational_merchandise` | `operational_merchandise_var` |
| `hospitality_package` | `hospitality_package` | `hospitality_package_var` |
| `timed_collection` | `timed_collection_product` | `timed_collection_var` |
| `parking_addon` | `operational_merchandise` | `operational_merchandise_var` |
| `operational_bundle` | `operational_bundle` | `operational_bundle_var` |

## `field_mel_operational_product` contract

- Only **whitelisted** operational metadata is written, after `OperationalMerchandiseManager::normalizeProductFieldValue()`.
- Customer title and summary are **plain text** (HTML stripped, length capped).
- Price is validated as a non-negative decimal and stored on the **variation** via Commerce `Price`.

## Payload contract (creation)

Required keys (after forbidden-key stripping): `productisation_type`, `event_id` (must match the event being edited), `title`, `customer_summary`, `price_amount`, `currency_code`, `customer_visibility`, `fulfillment_mode`, `reservation_mode`.

Optional: `sku`, `pickup_mode`, `timed_collection_window_copy`, `hospitality_benefits_summary`, `parking_guidance`, `bundle_capability_types`, `existing_product_id` (idempotency when a product was already created for this row).

Forbidden keys are stripped recursively (see `VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS`), including inventory, warehouse, shipment, scanner, QR, entitlement, and ticket identifiers.

## Autosave boundary

- `EventStudioAutosaveController` continues to persist **tempstore drafts only** for the `merchandise` section.
- Draft JSON may include wizard field values; **no** Commerce `save()` runs on autosave.
- Product and variation creation runs only from `EventStudioProductisationForm::submitForm()` after validation.

## Explicit non-goals

- No stock decrement or inventory execution.
- No warehouse planning or shipping orchestration.
- No scanner mutation, QR payload authoring, or entitlement issuance.
- No cart, checkout, or order mutation from this wizard.

## Tests

- **Kernel:** `Drupal\Tests\myeventlane_commerce\Kernel\OperationalMerchandiseKernelTest` — methods named `testVendorProductCreationWizard…` (Commerce kernel fixtures + `field_event_store` on the test event for store resolution when `myeventlane_vendor` is not in the kernel module list). `OperationalCapabilityCommerceLinkManager::resolveActingVendorStoreId()` uses `EntityTypeManagerInterface::hasDefinition('myeventlane_vendor')` before touching vendor storage so lightweight kernels do not fatal. Run e.g.  
  `export SIMPLETEST_DB=sqlite://localhost/tmp/test.sqlite && ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalMerchandiseKernelTest.php --filter VendorProductCreationWizard`
- **Unit:** `Drupal\Tests\myeventlane_event_studio\Unit\VendorOperationalProductCreationManagerTest` — forbidden-key contract checks.

## Related docs

- [Vendor Productisation Studio](vendor-productisation-studio.md)
- [Operational merchandise architecture](operational-merchandise-architecture.md)
- [Operational cart & checkout orchestration](operational-cart-checkout-orchestration.md)
- [Customer operational Commerce experience](customer-operational-commerce-experience.md)
