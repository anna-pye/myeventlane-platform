# Customer operational add-on confirmation guidance (Phase 4F Commit 2)

## Architecture

Read-only **presentation** layer: customers who purchased operational Commerce add-ons see a compact **“Your add-ons”** strip on checkout completion, My Tickets, booking detail, and the Commerce order user view. **Drupal Commerce** remains authoritative for carts, orders, prices, checkout state, and inventory.

- **Service:** `myeventlane_commerce.operational_addon_guidance_builder` → `OperationalAddonGuidanceBuilder`.
- **Inputs:** `mel_operational_purchase_composition` document from `OperationalPurchaseCompositionManager::composeForOrder()` (and optionally the `mel_operational_checkout` contract array for extra deterministic guidance sentences).
- **No** second line classifier, **no** new order-item storage, **no** checkout panes, **no** checkout mutation, **no** stock decrement, **no** warehouse/shipping orchestration, **no** scanner or QR payload changes, **no** entitlement issuance.

## Reused services

| Component | Role |
| --- | --- |
| `OperationalPurchaseCompositionManager` | Single grouping + customer-safe line payloads (`composeForOrder`, `buildOrderRenderArrayFromDocument`). |
| `OperationalCheckoutOrchestrationManager` | `buildOrderRenderArrayFromComposition()` / `buildCheckoutContractFromComposition()` reuse the same `finalizeContractFromComposition` path as cart/checkout strips. |
| `OperationalAddonGuidanceBuilder` | Maps composition (+ optional checkout contract) into a small customer document; strips forbidden keys recursively. |

## Theme integration

| Surface | Integration |
| --- | --- |
| **Checkout completion** | `myeventlane_theme_preprocess_commerce_checkout_form()` → `_myeventlane_theme_attach_operational_addon_guidance()`; variable `operational_addon_guidance` rendered in `commerce-checkout-completion.html.twig` after event/ticket summary and before donation / “what’s next”. |
| **My Tickets** | `myeventlane_theme_preprocess_myeventlane_my_tickets()` sets `order_data.operational_addon_guidance` via `_myeventlane_theme_mel_operational_addon_guidance_for_order()`. |
| **Order detail** | `myeventlane_theme_preprocess_myeventlane_order_detail()` → `_myeventlane_theme_attach_operational_addon_guidance()`. |
| **Commerce order (user)** | `myeventlane_theme_preprocess_commerce_order()` → same attach helper. |

Request-local caching: `_myeventlane_theme_mel_operational_composition_document()` and `_myeventlane_theme_mel_operational_checkout_render_for_order()` avoid duplicate `composeForOrder` / orchestration work per order in a single request.

## Customer-visible output

- Hook `mel_operational_addon_guidance`, template `mel-operational-addon-guidance.html.twig`, library `myeventlane_commerce/operational_addon_guidance`.
- Friendly copy: “Your add-ons”, “Collect at the event”, “Show your order confirmation if asked”, “Hospitality access is linked to this booking”, plus a muted note that **shipping** and **QR wallet collection** are **not** shown here yet.
- If the order has **only** tickets (no operational product groups), the strip is omitted.

## Deliberately not implemented

- Shipping labels, tracking, warehouse routing, stock reservation messaging beyond Commerce.
- QR wallet / scanner redemption flows.
- Staff or vendor dashboards; this is customer-safe copy only.

## Manual QA checklist

- [ ] Order with **tickets only**: no add-on strip on completion, My Tickets, order detail, or `commerce_order` user view.
- [ ] Order with **operational merch** (and/or hospitality / timed / bundles / parking): strip appears once per surface; no raw JSON or internal keys.
- [ ] Completion page: strip sits **after** event/ticket summary and **before** “What’s next” / organiser trust blocks.
- [ ] Mobile: readable spacing, chips wrap, no horizontal overflow.
- [ ] Cache rebuild after deploy: `ddev drush cr` (or equivalent).

## Automated tests

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalAddonGuidanceBuilderTest.php --do-not-cache-result
```

Kernel coverage for orchestration/composition is unchanged; optional regression:

```bash
ddev exec bash -lc 'export SIMPLETEST_DB=sqlite://localhost/tmp/test.sqlite && ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalCheckoutOrchestrationManagerTest.php --do-not-cache-result'
```

## Related documentation

- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
- [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md)
- [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md)
- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
