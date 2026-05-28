# Event Commerce Classifications

Status: internal governance  
Scope: canonical purchasable classifications for event-linked Commerce operations

## Purpose

MEL event commerce uses explicit classifications so operational behavior is not inferred from arbitrary Commerce product bundles.

The canonical code contract lives in `web/modules/custom/myeventlane_event/src/Value/EventCommercePurchasableType.php`. The reusable read-only registry lives in `web/modules/custom/myeventlane_event/src/Service/EventCommerceClassificationRegistry.php`.

Classifications are metadata. They do not create products, variations, order items, carts, checkout panes, fulfilment records, or Stripe side effects.

## Canonical Classifications

| Classification | Owns | Meaning |
| --- | --- | --- |
| `ticket` | Ticket domain | Attendance access purchasable governed by ticket lifecycle, capacities, sales windows, and issuance. |
| `addon` | Commerce product domain | Optional event-linked purchasable such as parking, meal package, shuttle, camping, or VIP extras. |
| `merchandise` | Commerce product domain | Physical or digital product sold alongside an event without becoming attendance access. |
| `donation` | Commerce product domain | Voluntary Commerce purchasable kept as its own order item boundary. |
| `upgrade` | Commerce product domain | Purchasable enhancement that does not replace or mutate ticket lifecycle logic. |
| `bundle_future` | Commerce product domain | Reserved classification for future governed bundle architecture. |

## Hard Rules

- Tickets are not merchandise.
- Merchandise is not fulfilment.
- Add-ons are not ticket entities.
- Donations are not ticket logic.
- Future bundles must not bypass ticket, Commerce product, or fulfilment ownership.

## Classification Sources

The event ticket product is classified as `ticket` when it is resolved from the event `field_product_target` relationship.

Other products and purchasables may be classified only from explicit owning-domain maps, such as a product bundle to classification map supplied by owning services. Operational Commerce bundles use `Drupal\myeventlane_core\Commerce\OperationalProductBundles::productBundleClassifications()` as the single bundle map. The resolver must not guess that a bundle is merchandise, add-on, donation, or upgrade without that owning-domain contract.

## Resolver Behavior

`EventCommerceResolver` may expose:

- Event ticket product relationships.
- Event-linked Commerce products.
- Ticket purchasables.
- Grouped relationships by canonical classification.
- Unique events represented by mixed order items.

It must remain read-only. It must not mutate carts, orders, products, variations, inventory, checkout, Stripe, fulfilment, or ticket issuance.

## Future Extension Rules

Future domains that introduce product bundles must document:

- Bundle ids.
- Classification mapping.
- Owning service.
- Persistence model.
- Access rules.
- Operational table behavior.
- Readiness participation, if any.

Adding a product bundle is not enough to create behavior. Behavior belongs in the owning domain service and must be surfaced in Studio only through governed sections.
