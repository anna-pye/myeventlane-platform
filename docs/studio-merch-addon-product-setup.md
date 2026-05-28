# Event Studio: merch and add-on product setup

Status: vendor-facing Commerce product editor in Studio  
Scope: Event Studio `extras` section — not tickets, fulfilment execution, or Stripe charge model

## Product editor layout

**Merch & add-ons** (`/vendor/events/{node}/studio/extras`) uses a card-based **Commerce product editor** with sections:

| Section | Purpose |
| --- | --- |
| Product basics | Type (merchandise / add-on subtype), name, short description, status (Active / Hidden / Draft) |
| Product images | `field_mel_extra_images` media gallery (all operational bundles) |
| Pricing and SKU | Price, SKU (auto-generated when blank; size suffixes for variants) |
| Quantity | Quantity note in operational metadata (not stock enforcement) + warning panel |
| Options and variants | Size checkboxes + variant preview table (merchandise with `field_mel_size`) |
| Collection and fulfilment | Pickup / collection note; fulfilment tracking deferred notice |
| Booking visibility | Show on booking page (requires Active status) |

Save actions: **Save product**, **Save and add another**, **Back to all extras**.

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
| Stock / `field_capacity` | **No** | **No** |

Quantity is stored as a **quantity note** inside `field_mel_operational_product` metadata (`operational_chips` + `collection_rules.vendor_quantity_note`). It is **not** inventory enforcement.

## Image support status

**Configured and wired.** `field_mel_extra_images` uses the Commerce `media_library_widget`. Studio attaches the default entity form display widget on create (stub product) and edit. Images save via `VendorOperationalProductCreationManager::applyEventExtraFieldUpdates()` and form display `extractFormValues()`.

## Variant support status

**Merchandise** (`operational_merchandise` + `field_mel_size`): vendor selects sizes; one variation per size with SKU suffix. Preview table shown in editor.

**Add-ons**: single variation by default (no size field on non-merch variation types).

## Product list cards

Each card shows: thumbnail or placeholder, type pill, status (Active/Hidden/Draft), variant count, price, quantity note, booking visibility, actions (Edit product, Hide/Show on booking page, View booking page).

## Authority

- **Commerce** owns products, variations, carts, checkout.
- **`VendorOperationalProductCreationManager`** owns create/update (explicit save only).
- **`EventStudioExtrasProductEditorBuilder`** owns form layout only.
- **`OperationalProductStudioFieldRegistry`** reports field support (read-only).

## Why extras do not issue tickets

See [commerce-merch-addon-phase0-safety.md](./commerce-merch-addon-phase0-safety.md). Operational lines are excluded from ticket issuance and ticket-only Stripe revenue sums.

## Deferred

- Stock enforcement and Commerce Stock integration on operational variations.
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
  --do-not-cache-result
```

## Related docs

- [operational-merchandise-architecture.md](./operational-merchandise-architecture.md)
- [commerce-merch-addon-phase0-safety.md](./commerce-merch-addon-phase0-safety.md)
- [customer-operational-addons-booking.md](./customer-operational-addons-booking.md)
- [vendor-product-creation-wizard.md](./vendor-product-creation-wizard.md)
