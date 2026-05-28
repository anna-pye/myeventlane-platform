# Operational extras stock fields

Status: implemented (add-to-cart enforcement slice)  
Scope: operational Commerce variations for merch and add-ons — not tickets, fulfilment, or Commerce Stock module

## Fields added

All fields live on **Commerce product variations** (not products):

| Field | Type | Label | Rule |
| --- | --- | --- | --- |
| `field_mel_stock_quantity` | integer | Stock quantity | Min 0. Blank = unlimited. `0` = sold out. |
| `field_mel_limit_per_order` | integer | Limit per order | Min 1 when set. Optional. |
| `field_mel_show_remaining` | boolean | Show remaining quantity | Default false. |

### Variation bundles

- `operational_merchandise_var`
- `operational_bundle_var`
- `hospitality_package_var`
- `timed_collection_var`

Config source: `config/sync/field.storage.commerce_product_variation.field_mel_*` and matching `field.field.commerce_product_variation.*` YAML.

Ticket variations continue to use `field_capacity`, `field_limit_per_order`, and `field_show_remaining` — separate from operational extras.

## Blank vs zero stock

| Value | Meaning |
| --- | --- |
| Blank / not set | **Unlimited** stock for that variation |
| `0` | **Sold out** — cannot add to cart |
| Positive integer | Finite stock enforced at add-to-cart |

Limit per order is optional. When set, it must not exceed stock quantity when stock is finite and greater than zero.

## Operational documents (deferred)

Packing slips, parking slips, item labels, and grouped fulfilment CSV/PDF are **not** generated from Studio. Manage event shows **Operational documents** placeholders; see [vendor-operational-documents.md](./vendor-operational-documents.md).

## Platform fee (checkout)

Operational extras incur a separate platform fee adjustment at checkout (`myeventlane_operational_extras_platform_fee`), defaulting to **double** the ticket `platform_fee_percent`. Config: `myeventlane_core.settings` → `operational_extras_platform_fee_percent`. Does not change ticket fee or Stripe Connect application fee on tickets.

## Event Studio product editor

**Stock summary-first UX:** the **Stock and limits** accordion panel shows summary chips (options, unlimited, sold out, limits) and *Stock is enforced when customers add extras to cart.* Per-option fields are progressive disclosure inside **Edit stock per option** (closed when all stock is blank/unlimited and no limits are set).

**Studio product editor:** each **product option** row maps to one Commerce variation. Catalog fields (label, SKU, price, visible) live in **Product options**; stock fields (`field_mel_stock_quantity`, `field_mel_limit_per_order`, `field_mel_show_remaining`) live in **Stock and limits** using the **same variation ID** and `editor[product_options][rows][delta]` form parents. Helper copy for blank/0/positive stock and optional limits appears once above the stock table, not on every row.

Size checkboxes are not the source of truth; **colour matrix is deferred**. Legacy size-only products load as editable option rows with IDs preserved on save.

**Add-ons (single variation):** legacy single-variation quantity card may still apply outside the multi-option editor path.

**Quantity note** (`field_mel_operational_product` metadata) remains an optional internal helper — not the stock source of truth.

Booking page preview shows aggregated stock label from `OperationalVariationStockResolver::summarizeProductStock()`.

Studio list cards show total stock state: Unlimited, N in stock, Low stock (≤5 total), Sold out, and limit per order when uniform across variations.

**Print/packing/parking/label generation** is not in the product editor — see Manage event ([vendor-operational-documents.md](./vendor-operational-documents.md)).

## Add-to-cart enforcement

`OperationalVariationStockResolver` + `EventOperationalAddonCartForm` enforce server-side:

1. Cannot exceed variation stock quantity.
2. Cannot exceed limit per order.
3. Existing cart quantity for the same variation counts toward limits.
4. Stock `0` blocks add.
5. Blank stock = unlimited (limit per order still applies if set).

Booking UI sets quantity `max` where possible and disables cards/options that are fully sold out. **No stock decrement** on add-to-cart in this slice.

## Backward compatibility

Products/variations without the new fields default to **unlimited** stock (field empty). Booking and Studio must not fatal on legacy products.

## Deferred

- Stock reservation / holds during checkout
- Decrement on payment / increment on refund
- Warehouse or inventory ledger
- Fulfilment / shipping state machines
- QR pickup redemption tied to stock
- Commerce Stock module integration

## Related docs

- [studio-merch-addon-product-setup.md](./studio-merch-addon-product-setup.md)
- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [commerce-merch-addon-phase0-safety.md](./commerce-merch-addon-phase0-safety.md)

## Ticket sales monitoring (read-only)

Event Studio **Manage event** (`myeventlane_vendor.console.event_workspace`, `/vendor/events/{event}`) includes:

- read-only **Ticket sales** panel (attendance capacity)
- read-only **Merch & add-on sales** panel (operational extras by category)

**Merch & add-ons** (`myeventlane_event_studio.workspace_extras`) remains product stock setup only and links back to Manage event for sales monitoring.

See also `docs/vendor-commerce-sales-monitoring.md` for order states, exclusions, and deferred export/distribution work.

| Domain | Surface | Data source | Purpose |
| --- | --- | --- | --- |
| Ticket sales | Manage event (`/vendor/events/{event}`) | Ticket tiers + ticket-backed completed order items | Attendance capacity monitoring |
| Merch & add-ons stock | Merch & add-ons Studio | `field_mel_stock_quantity` on operational variations | Product stock at add-to-cart |

These domains are **never combined**. There is no total stock across tickets and extras.

### Ticket sales panel

- Read-only summary per paid ticket type: name, price, capacity, sold, remaining, % sold, status.
- Sold count: **completed** Commerce orders only, quantities from order items where `TicketBackedOrderItemClassifier::isTicketBackedOrderItem()` is true.
- Operational extras, donations, boosts, and non-ticket line items do **not** increase ticket sold counts.
- Remaining = capacity − sold when capacity is finite; otherwise shows **Unlimited** / **No limit set**.
- Status uses existing ticket lifecycle evaluation (`TicketStatusEvaluator`) — no tier mutation from this panel.
- **Edit tickets** links to the existing Studio Tickets section (`myeventlane_event_studio.workspace_tickets`).
- Studio top-bar **Manage event** returns to `myeventlane_vendor.console.event_workspace` (`/vendor/events/{event}`), not the Studio overview route.

Service: `EventStudioCommerceSalesSummaryBuilder` (`myeventlane_event_studio.commerce_sales_summary_builder`).

Future analytics may expand beyond this lightweight event-scoped slice.

## Tests

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalVariationStockResolverTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/EventOperationalAddonBuilderTest.php \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/EventStudioExtrasProductEditorTest.php \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/EventStudioCommerceSalesSummaryBuilderTest.php \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/EventStudioManageEventIaTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/TicketBackedOrderItemClassifierTest.php \
  --do-not-cache-result
```
