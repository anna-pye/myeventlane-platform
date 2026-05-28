# Vendor operational add-on order visibility (Phase 4F Commit 3)

## Purpose

Give **organisers** a read-only list of **operational add-on** purchases (merch, hospitality, timed collection, bundles, parking) tied to an event, so they can plan on-site fulfilment. **Drupal Commerce** remains the system of record for orders, items, prices, and state. This layer does **not** change orders, stock, shipping, warehouses, scanners, QR payloads, or entitlements.

## Route and UI

| Item | Value |
| --- | --- |
| Path | `/vendor/events/{event}/addons` |
| Route name | `myeventlane_vendor.console.event_operational_addon_orders` |
| Controller | `Drupal\myeventlane_vendor\Controller\VendorOperationalAddonOrdersController::addons()` |
| Shell | Same `mel_event_workspace` vendor console layout as other event tools (tabs + main column). |

The route is registered in `myeventlane_vendor` (with the other `/vendor/events/{event}/…` console routes) to avoid a **commerce ↔ vendor circular module dependency**, while the **read model** lives in `myeventlane_commerce` as specified below.

## Service contract

| Service ID | Class |
| --- | --- |
| `myeventlane_commerce.vendor_operational_addon_order_builder` | `Drupal\myeventlane_commerce\Service\VendorOperationalAddonOrderBuilder` |

### Methods (caller enforces access)

- `buildForEvent(NodeInterface $event, AccountInterface $account): array` — document for `mel_vendor_operational_addon_orders`. The account parameter is reserved for future use; **the controller must** call `VendorConsoleBaseController::assertEventOwnership()` before invoking the builder.
- `buildForOrder(OrderInterface $order, AccountInterface $account, int $eventId): array` — single-order slice for the same document shape (optional reuse from future order views).
- `orderHasOperationalAddons(OrderInterface $order, ?int $eventId = NULL): bool` — uses `OperationalPurchaseCompositionManager::composeForOrder()` only (no manual bundle typing).
- `shouldSurfaceVendorAddonsTab(NodeInterface $event): bool` — `TRUE` when the event has configured operational add-ons **or** at least one qualifying completed/placed order contains operational lines for the event.
- `stripForbiddenRecursive(array $data): array` — strips vendor-forbidden keys recursively (list-safe).

### Data source

Order discovery and state filtering follow the same approach as `VendorEventOrdersController::getOrdersForEvent()` (event-linked order items, variation/product `field_event` fallbacks, store-scoped fallback, states `completed`, `partially_refunded`, `refunded`, `placed`, `fulfilled`, `fulfillment`). **Line detail** comes from `OperationalPurchaseCompositionManager::composeForOrder()`, then lines are **restricted to items that belong to the event** using the same event-matching rules as the vendor orders list.

## Access model

- Route `requirements`: `myeventlane_vendor.access.vendor_console:access` (same family as event orders).
- Controller: `assertEventOwnership($event)` — vendor console trust, admin bypass, node owner, or `field_event_vendor` → `field_vendor_users` membership (aligned with `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()`).

## Privacy model

- Customer label: purchaser **display name** when available; otherwise `Customer #<uid>`; otherwise **Guest customer**. Raw order email is **not** exposed on the add-on orders page (vendor **Orders** may still show email separately).
- No attendee Q&A payloads, no internal cost/margin, no scanner/warehouse/shipment material. Keys stripped match `VendorOperationalAddonOrderBuilder::FORBIDDEN_VENDOR_ORDER_KEYS`.

## What vendors see

- Summary: count of orders with add-ons, add-on line count, counts per operational group.
- Per order: order number, placed date, customer-safe label, grouped lines with product/variation labels, operational summary text, readiness/pickup strings when present, customer-facing chips.

## Deliberately not implemented

- Fulfilment execution state, “mark collected”, stock decrement, shipping labels, warehouse routing, QR wallet / scanner redemption, entitlement mutation, checkout or order writes.

## Dashboard / navigation integration

- **Tabs:** `VendorEventTabsService` adds an **Add-on orders** tab when the route exists and the surface gate passes (`paid tickets` + `shouldSurfaceVendorAddonsTab()`).
- **Workspace shortcuts:** `VendorEventWorkspaceViewModelBuilder` exposes `actions.addon_orders` when paid/both mode, the route is allowed for the account, and the surface gate passes; `mel-event-workspace.html.twig` renders **View add-on orders**.
- **Local tasks:** `myeventlane_vendor.links.task.yml` secondary tab for the same route.

## Cache metadata (MVP hardening)

| Surface | Tags / contexts / max-age |
| --- | --- |
| Vendor add-on orders page | `collectCacheTagsForEvent()`: event + linked `commerce_product` + qualifying `commerce_order` tags; contexts `user`, `user.permissions`, `languages:language_interface`; **max-age 300** (`VendorOperationalAddonOrderBuilder::VENDOR_ADDON_ORDERS_MAX_AGE`) |
| Event book add-on form | Event tags + `commerce_product:{id}`; contexts `user`, `session`, `languages:language_interface` |
| Customer guidance strip | `commerce_order` tags; contexts `user`, `languages:language_interface` |

Full scripted QA: [operational-addons-mvp-qa.md](./operational-addons-mvp-qa.md).

## Manual QA checklist

- [ ] Non-vendor / anonymous user cannot open `/vendor/events/{id}/addons`.
- [ ] Event owner and vendor team member (linked vendor users) can open the page; unrelated authenticated user cannot.
- [ ] Admin (`administer nodes`) can open the page.
- [ ] Event with **no** operational catalog and **no** qualifying orders: tab disabled with reason; workspace shortcut absent; page shows empty state when reached via URL.
- [ ] Event with **catalog only**: tab enabled; page may show empty orders with intro.
- [ ] Event with **purchased** operational add-ons: orders listed; no forbidden JSON keys in View Source / Devel (spot check).
- [ ] Mobile: cards readable, no horizontal overflow.

## Test commands

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Unit/VendorOperationalAddonOrderBuilderTest.php --do-not-cache-result
./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalAddonGuidanceBuilderTest.php --do-not-cache-result
```

Kernel coverage for vendor access is not added in this slice (would duplicate large Commerce fixtures); rely on manual QA above plus existing vendor console kernel tests where applicable.

## Related documentation

- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
- [customer-operational-addon-confirmation-guidance.md](./customer-operational-addon-confirmation-guidance.md)
- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [operational-purchase-composition-convergence.md](./operational-purchase-composition-convergence.md)
- [operational-cart-checkout-orchestration.md](./operational-cart-checkout-orchestration.md)
