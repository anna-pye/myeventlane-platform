# Customer operational add-ons on event booking (Phase 4F)

## Architecture

Paid event booking (`/event/{node}/book`, `BookController`) embeds a **read-only** catalog slice built by `myeventlane_commerce.event_operational_addon_builder` (`EventOperationalAddonBuilder`) and a **Commerce cart** form `EventOperationalAddonCartForm`. **Drupal Commerce** remains authoritative for carts, checkout, order items, pricing, stores, and order state.

- **No** parallel cart system, **no** manual stock decrement, **no** warehouse or shipping orchestration, **no** QR/scanner/entitlement mutations from this layer.
- Add-to-cart uses `commerce_cart.cart_provider` and `commerce_cart.cart_manager` (`addEntity()`), mirroring ticket selection patterns.

## Services

| ID | Class | Role |
| --- | --- | --- |
| `myeventlane_commerce.event_operational_addon_builder` | `EventOperationalAddonBuilder` | Entity query for operational product bundles with `field_event` = event, published products, published variations; customer-safe operational copy via `OperationalMerchandiseManager`. |
| `myeventlane_commerce.operational_merchandise_manager` | `OperationalMerchandiseManager` | Reused for normalization + `buildCustomerSafeProductPresentation()` (existing contract). |

Form dependencies (constructor / `create()`): `entity_type.manager`, `commerce_cart.cart_provider`, `commerce_cart.cart_manager`, `event_operational_addon_builder`, `commerce_price.currency_formatter`, `logger.factory`.

## Customer flow

1. Customer opens the paid event book page.
2. If at least one eligible operational product exists for the event, the **“Add something extra”** section renders below the ticket matrix (same main column).
3. Customer sets quantities (0–10 UI cap; **not** inventory enforcement unless Commerce stock modules apply their own rules elsewhere).
4. Submit calls `CartManagerInterface::addEntity()` per line with `combine = TRUE`, resolves store from the product’s Commerce stores (first store), sets `field_target_event` on new order items when present (same presave contract as tickets).
5. Redirect returns to the same event book route with a status message; cart/checkout CTAs elsewhere are unchanged.

## Security checks

- Submitted variation IDs are validated against a **fresh allowlist** from `EventOperationalAddonBuilder::buildForEvent()` (no trust in hidden defaults for linkage).
- Product must be **published**, bundle ∈ `OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES`, `field_event` must match the event node, variation must be **published**, product must expose at least one Commerce store.
- Customer payloads strip forbidden keys (see `EventOperationalAddonBuilder::FORBIDDEN_CUSTOMER_ADDON_KEYS`) in addition to `OperationalMerchandiseManager` normalization.

## Deliberately not implemented

- Stock reservation, warehouse routing, shipment creation, scanner/QR mutation, entitlement issuance, checkout pane changes, multi-step checkout redirects from this form, and “hard” per-line inventory caps (UI cap only).

## Theming

- Library: `myeventlane_commerce/operational_addons` → `css/mel-operational-addons.css`.
- Submit uses existing `mel-btn mel-btn--primary` classes for coral CTA consistency with the theme.

## Tests

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Unit/EventOperationalAddonBuilderTest.php --do-not-cache-result
./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalMerchandiseKernelTest.php --filter EventOperationalAddonBuilder --do-not-cache-result
```

Kernel tests live in `OperationalMerchandiseKernelTest` (shared Commerce fixtures). If `SIMPLETEST_DB` is required in your environment:

```bash
ddev exec bash -lc 'export SIMPLETEST_DB=sqlite://localhost/tmp/test.sqlite && ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalMerchandiseKernelTest.php --filter EventOperationalAddonBuilder --do-not-cache-result'
```

**Cart form automated tests:** Full Commerce cart kernel coverage is not included here (heavy fixture surface). Manual QA covers add-to-cart, invalid variation rejection, and multi-store behaviour.

## Manual QA checklist

- [ ] Paid event **without** operational products: add-on section **hidden**; booking otherwise normal.
- [ ] Paid event **with** linked operational product(s): section shows title, summary/chips, price, quantity; submit with all zeros shows neutral status.
- [ ] Submit quantity > 0: items appear in Commerce cart; `field_target_event` populated when configured.
- [ ] Unpublished product or variation: disappears from list after change (cache rebuild / refresh).
- [ ] Product linked to **different** event: never listed.
- [ ] Tampered POST (invalid variation id): validation error, nothing added to cart.
- [ ] Mixed ticket + add-on: both land in appropriate Commerce carts per store rules.

## Related documentation

- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md)
- [vendor-product-creation-wizard.md](./vendor-product-creation-wizard.md)
- [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md)
