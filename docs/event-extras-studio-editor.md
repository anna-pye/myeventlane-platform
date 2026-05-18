# Event Extras Studio editor

## Purpose

Single vendor-facing surface to create and manage **event extras** (merch, food & drink, VIP, pickup items, bundles) inside Event Studio, backed by Drupal Commerce operational products.

**Route:** `/vendor/events/{event}/studio/extras`  
**Route name:** `myeventlane_event_studio.workspace_extras`

Legacy URLs `/studio/merchandise`, `/studio/addons`, and `/studio/add-ons` redirect here (bookmarks preserved).

## Architecture

| Layer | Responsibility |
|-------|----------------|
| `EventStudioEventExtrasForm` | Vendor UI (probe → edit → list), no Commerce jargon |
| `EventStudioEventExtrasBuilder` | List cards + customer preview rows |
| `VendorOperationalProductCreationManager::saveEventExtraForVendor()` | Create/update products, variations, fields, access |
| `EventOperationalAddonBuilder` | Customer booking page (unchanged) |
| `OperationalExtraVisualPresenter` | Images, sizes, pickup note presentation |

Commerce remains authoritative for catalog, carts, checkout, and orders. **No** stock, warehouse, shipping, scanner, QR, entitlement, or checkout mutation in this flow.

## Vendor flow (Probe → Present → Listen → Ask → Invite)

1. **Probe** — choice cards: Merchandise, Food & drink, VIP / hospitality, Pickup item, Bundle / package.
2. **Present** — editor: name, description, price, sizes (merch), pickup note, show on booking, images (after first save or on edit).
3. **Listen** — guest preview card on edit.
4. **Ask** — save, save & add another, list actions (edit, hide/show on booking).
5. **Invite** — footer CTAs: preview booking page, continue to publish.

## Access rules

- `EventStudioAccess` + `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()`
- Product must have `field_event` = current event
- Product bundle ∈ `OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES`
- Product store must match resolved vendor/event store
- Ticket products and other vendors’ products are rejected

## Field mapping

| Vendor field | Commerce |
|--------------|----------|
| Extra name | `commerce_product.title` |
| Short description | `field_mel_extra_short_desc` |
| Pickup note | `field_mel_extra_pickup_note` |
| Images | `field_mel_extra_images` (media) |
| Show on booking | `status` (published) |
| Sizes | `field_mel_size` per `operational_merchandise_var` variation |
| Price | variation `price` |
| Event link | `field_event` (set automatically, not editable) |
| Store | `stores` (set automatically) |

Internal operational JSON: `field_mel_operational_product` (sanitized; vendors cannot submit forbidden keys).

## Extra type → Commerce bundle

| UI type | Productisation type | Commerce product bundle |
|---------|---------------------|-------------------------|
| Merchandise | `merchandise` | `operational_merchandise` |
| Food & drink | `timed_collection` | `timed_collection_product` |
| Pickup item | `timed_collection` | `timed_collection_product` |
| VIP / hospitality | `hospitality_package` | `hospitality_package` |
| Bundle / package | `operational_bundle` | `operational_bundle` |

## Navigation

- Sidebar: **Extras** (unified), **Collection** (formerly Fulfilment label only).
- **Merchandise** and **Add-ons** hidden from navigation; old routes redirect.

## Admin

Users with `administer commerce_product` see **Advanced Commerce edit** on the extra editor only.

## QA checklist

See task spec: vendor create T-shirt with sizes → booking page → cart → checkout → tickets/order → cross-vendor denial.

## Non-goals

- Fulfilment execution, scanners, inventory decrement
- Shipping / warehouse orchestration
- Replacing Commerce checkout or cart APIs
- Removing admin Commerce product edit globally

## Related docs

- [event-extras-studio-audit.md](event-extras-studio-audit.md) — Phase 0 audit
- [vendor-productisation-studio.md](vendor-productisation-studio.md) — legacy productisation studio (still available at merchandise route redirect target)
