# Operational add-ons MVP — manual QA script

End-to-end checks for Phase 4F (vendor catalog → customer booking → post-purchase guidance → vendor order visibility). **Read-only / presentation only** — no fulfilment mutation, stock, shipping, scanners, or QR.

## Prerequisites

- Local site via DDEV (`ddev drush cr` after deploy).
- Vendor user with event workspace access and at least one paid event.
- Stripe/test checkout enabled for ticket purchases.

## A. Setup

1. Create or open a **paid** event with a published ticket product (`field_product_target`).
2. In Event Studio (or Commerce admin), **create/link** an operational product:
   - Bundle ∈ operational types (`operational_merchandise`, `hospitality_package`, `timed_collection_product`, `operational_bundle`, or parking type per site config).
   - `field_event` = this event.
   - At least one **published** variation with price.
   - Product assigned to a **Commerce store** (required — products without stores must not appear on the book page).
3. Publish product and variation.

## B. Customer booking

1. Visit `/event/{nid}/book` as an anonymous or customer account.
2. Confirm **“Add something extra”** section appears below tickets with lede copy about collecting at the event.
3. Add ticket(s) to cart; add at least one add-on quantity &gt; 0; submit **Add extras to cart**.
4. Complete checkout (test card).
5. Confirm add-on line items appear in cart/checkout with correct event linkage (`field_target_event` when configured).

## C. Customer post-purchase

For the completed order:

1. **Checkout completion** — “Your add-ons” guidance strip when order includes operational lines; absent for tickets-only orders.
2. **My Tickets** — same guidance on the order card.
3. **Order detail** (`myeventlane_order_detail` or equivalent) — guidance present.
4. **Commerce order user view** (`/user/{uid}/orders/{order}`) — guidance present.
5. Guidance must **not** show QR payloads, scanner language, warehouse/shipping promises, or stock guarantees (support hint may note shipping/QR are not shown yet).

## D. Vendor visibility

1. Log in as **event owner** or vendor team member linked via `field_event_vendor` → `field_vendor_users`.
2. Open event workspace `/vendor/events/{event}`.
3. Confirm shortcut **“View add-on orders”** when catalog exists or purchases exist.
4. Open `/vendor/events/{event}/addons`.
5. Confirm summary counts, order cards, grouped lines (merch/hospitality/timed/bundles/parking), chips, prepare hint.
6. Customer label shows **display name** or **Customer #id** or **Guest customer** — not raw email on this page.
7. Log in as **unrelated** authenticated user → route forbidden.
8. **Anonymous** → denied (vendor console gate).

## E. Negative cases

| Case | Expected |
| --- | --- |
| Tickets-only order | No add-on guidance strip on customer surfaces |
| Unpublished product | Hidden from book page after cache refresh |
| Unpublished variation | Hidden from book page |
| Product `field_event` = different event | Never listed on book page |
| Tampered variation id on POST | Validation error; nothing added to cart |
| Product with **no** Commerce store | Not listed on book page; submit fails closed if forced |
| Unpublished product after purchase | Vendor list may still show historical order lines; catalog tab rules per `shouldSurfaceVendorAddonsTab()` |

## Cache / freshness notes

- Vendor add-on orders page uses event + `commerce_product` tags, qualifying `commerce_order` tags when enumerated, `user` + `user.permissions` contexts, and **max-age 300s** as a conservative bound when tag enumeration is costly.
- Event workspace shortcut visibility is computed per request; a new purchase may take up to **5 minutes** to appear if order cache tags were not collected — refresh after `ddev drush cr` if testing immediately.

## Automated tests

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/EventOperationalAddonBuilderTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalAddonGuidanceBuilderTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/VendorOperationalAddonOrderBuilderTest.php \
  --do-not-cache-result

ddev exec bash -lc 'export SIMPLETEST_DB=sqlite://localhost/tmp/test.sqlite && ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_commerce/tests/src/Kernel/OperationalMerchandiseKernelTest.php --filter EventOperationalAddon --do-not-cache-result'
```

## Related documentation

- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
- [customer-operational-addon-confirmation-guidance.md](./customer-operational-addon-confirmation-guidance.md)
- [vendor-operational-addon-order-visibility.md](./vendor-operational-addon-order-visibility.md)
- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
