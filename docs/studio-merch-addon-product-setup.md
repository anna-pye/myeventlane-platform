# Event Studio: merch and add-on product setup

Status: vendor-facing Commerce product editor in Studio  
Scope: Event Studio `extras` section — not tickets, fulfilment execution, or Stripe charge model

## Product editor layout

**Merch & add-ons** (`/vendor/events/{node}/studio/extras?extra={product_id}`) uses a two-column **Commerce product editor** on desktop (single column on mobile). Ticket sales monitoring lives on **Manage event** (`/vendor/events/{event}`, route `myeventlane_vendor.console.event_workspace`) — not on this surface.

### Guided accordion layout

The editor uses collapsible **`<details>`** panels (keyboard-friendly) so vendors are not faced with one long form:

| Panel | Contents |
| --- | --- |
| **Basics** | Product name, description, status, default price/SKU |
| **Photos** | Image gallery |
| **Product options** | Vendor-managed variation rows (label, SKU, price, visible) + quick presets |
| **Stock and limits** | Summary chips first; per-option stock behind **Edit stock per option** |
| **Booking preview** | Guest-facing summary (options count, price range, stock) |
| **Collection and documents** | Pickup note, booking visibility, print guidance (Manage event link) |

A **compact summary** line appears under the top nav (status · option count · from-price).

**Default open panels:** new product → Basics only; edit → Product options + Booking preview; validation errors → Basics + Product options + Stock.

Sticky footer: **Save product**, **Save and add another**, **Back to all extras**.

### Operational documents (product editor)

Card **“Packing slips, parking slips and labels”** explains that print tools live on **Manage event** once the item starts selling (merch packs, parking passes, food/drink orders, pickup labels). CTA: **Back to Manage event** (`myeventlane_vendor.console.event_workspace`). No fake print or download links in the editor. Full spec: [vendor-operational-documents.md](./vendor-operational-documents.md).

### Site fee copy (list mode)

Intro shows `PlatformFeeSettings::buildExtrasStudioFeeCopy()` — e.g. “Merch and add-ons use the same checkout. The MyEventLane site fee for extras is X%.” When extras fee defaults to double the ticket fee, copy explains why extras may be higher (stock and order handling). Configure at **Admin → Config → MyEventLane → General settings** → **Platform fee percentage (merch & add-ons)**.

### Operational documents (list mode)

Note: *After sales begin, you’ll be able to generate packing slips, parking slips, and labels from Manage event.* Full spec: [vendor-operational-documents.md](./vendor-operational-documents.md).

| Section | Purpose |
| --- | --- |
| Product basics | Type, name, short description, status (Active / Hidden / Draft) |
| Product images | `field_mel_extra_images` media gallery |
| Pricing and SKU | Price, SKU (auto-generated when blank) |
| Quantity / stock | Single stock card (add-ons) |
| Product options | Vendor-managed option rows (one Commerce variation each): label, SKU, price, visible, variation ID |
| Stock and limits | Per-option stock, limit per order, show remaining (same variation IDs as Product options) |
| Guest preview | Option count, price range, stock summary, booking visibility |
| Collection and fulfilment | Pickup note; shipping/tracking deferred |
| Booking visibility | Show on booking page (requires Active) |
| Booking preview | Guest preview with stock and visibility state |

### Stock summary-first UX

The **Stock and limits** panel shows a compact summary first (option count, unlimited / sold out / low stock chips, per-order limit status) and the copy *Stock is enforced when customers add extras to cart.*

Detailed controls live inside **Edit stock per option** (`<details>`). That block is **closed by default** when every option has blank stock and no per-order limits. It opens automatically when any option has finite stock, is sold out (`0`), has a per-order limit, or when the form was submitted with stock validation errors.

Helper copy appears **once** above the per-option table (not repeated on every row): **Blank = unlimited. 0 = sold out. Positive numbers are enforced.** and **Limit per order is optional.**

Desktop uses a compact table (Option | Stock quantity | Limit per order | Show remaining). Mobile stacks each row as a compact card with short labels.

**Product options are vendor-managed Commerce variations.** Each option row maps to one variation ID shared by **Product options** (catalog) and **Stock and limits** (inventory). Size checkboxes are no longer the source of truth. Option labels can include colour manually (e.g. `Black / S`); a guided **colour matrix is deferred**. Quick presets populate rows before save. Removed options are unpublished, not deleted. Existing size-based products load as labelled option rows with variation IDs preserved on save.

## Field support (audit summary)

### Product bundles (`operational_merchandise`, `hospitality_package`, `timed_collection_product`, `operational_bundle`)

| Field | Purpose |
| --- | --- |
| `title` | Product name (Commerce core) |
| `status` | Published flag |
| `stores` | Commerce store assignment |
| `field_event` | Event linkage |
| `field_mel_extra_short_desc` | Short customer description |
| `field_mel_extra_pickup_note` | Pickup / collection copy |
| `field_mel_extra_images` | Media gallery (image media) |
| `field_mel_operational_product` | Operational JSON metadata |

No `body` field. No dedicated stock/capacity field on products.

### Variation bundles

| Field | Merchandise var | Other operational vars |
| --- | --- | --- |
| `price`, `sku`, `status` | Yes (Commerce core) | Yes |
| `field_mel_size` | Yes | No |
| `field_mel_stock_quantity` | Yes | Yes |
| `field_mel_limit_per_order` | Yes | Yes |
| `field_mel_show_remaining` | Yes | Yes |
| Ticket `field_capacity` | **No** | **No** |

Stock is enforced at add-to-cart via variation stock fields. Blank stock = unlimited; `0` = sold out. See [operational-extras-stock-fields.md](./operational-extras-stock-fields.md).

Optional **quantity note** in `field_mel_operational_product` metadata is an internal helper only — not inventory enforcement.

## Image support status

**Configured and wired.** `field_mel_extra_images` uses the Commerce `media_library_widget`. Studio attaches the default entity form display widget on create (stub product) and edit. Images save via `VendorOperationalProductCreationManager::applyEventExtraFieldUpdates()` and form display `extractFormValues()`.

## Variant support status

**Merchandise** (`operational_merchandise` + `field_mel_size`): vendor selects sizes; one variation per size with SKU suffix. Preview table shown in editor.

**Add-ons**: single variation by default (no size field on non-merch variation types).

## Product list cards

Each card shows: thumbnail or placeholder, type pill, status (Active/Hidden/Draft), variant count, price, stock summary (unlimited / in stock / low / sold out), limit per order when set, quantity note (optional), booking visibility, actions (Edit product, Hide/Show on booking page, View booking page).

## Authority

- **Commerce** owns products, variations, carts, checkout.
- **`VendorOperationalProductCreationManager`** owns create/update (explicit save only).
- **`EventStudioExtrasProductEditorBuilder`** owns form layout only.
- **`OperationalProductStudioFieldRegistry`** reports field support (read-only).

## Why extras do not issue tickets

See [commerce-merch-addon-phase0-safety.md](./commerce-merch-addon-phase0-safety.md). Operational lines are excluded from ticket issuance and ticket-only Stripe revenue sums.

## Deferred

- Stock reservation, decrement-on-payment, and Commerce Stock integration.
- Shipping / fulfilment state machines and vendor fulfilment dashboard.
- Dedicated Connect revenue split for operational lines.
- Raw Commerce admin as the primary vendor path (staff-only advanced link when permitted).
- “Feature this extra” prominence (no field/metadata contract yet).

## Manual browser checklist

- [ ] Vendor opens Merch & add-ons on a paid event.
- [ ] Create T-shirt: name, description, price, SKU, sizes S/M/L, quantity note, images, Active + show on booking.
- [ ] Save; card shows image, type, price, variants, quantity note, status.
- [ ] Create Parking add-on: price, quantity note, pickup note, Active.
- [ ] Both appear on `/event/{node}/book` add-on section.
- [ ] Ticket setup unchanged; checkout does not issue tickets for extras only.

## Tests

```bash
./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/EventStudioExtrasProductEditorTest.php \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/EventStudioMerchAddonStudioTest.php \
  web/modules/custom/myeventlane_event_studio/tests/src/Unit/VendorOperationalProductCreationManagerTest.php \
  web/modules/custom/myeventlane_commerce/tests/src/Unit/OperationalVariationStockResolverTest.php \
  --do-not-cache-result
```

## Related docs

- [operational-extras-stock-fields.md](./operational-extras-stock-fields.md)
- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [commerce-merch-addon-phase0-safety.md](./commerce-merch-addon-phase0-safety.md)
- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
- [vendor-product-creation-wizard.md](./vendor-product-creation-wizard.md)
