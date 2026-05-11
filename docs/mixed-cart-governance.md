# Mixed Cart Governance

Status: internal governance  
Scope: event-linked tickets, add-ons, merchandise, donations, and future upgrades in one Drupal Commerce cart

## Principle

MEL must support mixed event carts through Drupal Commerce-native architecture.

Example cart:

- `2` VIP tickets.
- `1` parking add-on.
- `1` T-shirt.
- `$10` donation.

This must remain one cart, one checkout, and one payment flow. Event Studio must not fork checkout or create a parallel payment system for event commerce expansion.

## Ownership

| Concern | Owner |
| --- | --- |
| Attendance access, capacities, sales windows, issuance | Ticket domain |
| Product pricing, purchasable add-ons, merchandise, donations, upgrades | Commerce product domain |
| Cart, checkout, order items, payment flow | Drupal Commerce |
| Shipping, pickup, redemption, stock fulfilment, delivery states | Fulfilment domain |
| Operational orchestration, section navigation, readiness presentation | Event Studio |

Event Studio may orchestrate operational views, but Drupal Commerce remains the transactional engine.

## Order Item Boundaries

Mixed carts must preserve clean Commerce order item boundaries:

- Ticket order items represent attendance access.
- Add-on order items represent optional event-linked purchasables.
- Merchandise order items represent products requiring future fulfilment rules when applicable.
- Donation order items represent voluntary purchasables and must not be special-cased as tickets.
- Upgrade order items represent purchasable enhancements and must not mutate ticket lifecycle by default.

Ticket issuance must continue to inspect ticket-owned order item semantics. Merchandise, add-ons, donations, and upgrades must not accidentally issue attendance access.

## Event Linking

Canonical event linkage may come from:

- Event `field_product_target` for the ticket product.
- Product `field_event` for event-linked products.
- Order item `field_target_event` where the order item type owns that relationship.

Relationship services should resolve links rather than serializing product arrays on nodes, hardcoding ids, or duplicating entity references across unrelated fields.

## Checkout Rules

Do not:

- Fork checkout for merchandise or add-ons.
- Create a non-Commerce cart for event extras.
- Trigger Stripe directly from Event Studio.
- Change Connect account type, platform charge model, application fees, payout behavior, webhook semantics, or connected-vendor handling without explicit approval.
- Collapse add-ons, merchandise, donations, or upgrades into ticket entities.

Do:

- Keep mixed carts Drupal Commerce-native.
- Keep each purchasable represented by the correct Commerce entity and order item boundary.
- Resolve event relationships through read-only services where operational UI needs grouped context.
- Add future order processors, checkout panes, or fulfilment integrations only through their owning domains.

## Studio Rules

Future mixed-cart operational sections must:

- Use explicit Studio routes and `EventStudioSection` plugins.
- Reuse `EventStudioAccess` and vendor workspace parity checks.
- Preserve the `administer nodes` override.
- Deny customers and anonymous users server-side.
- Avoid raw Commerce admin widgets.
- Lazy-load and paginate operational lists.
- Avoid rendering large Commerce entity trees on initial workspace load.
- Isolate autosave and save boundaries by section.

## Readiness Rules

Mixed-cart readiness is not active in this phase.

Future readiness providers may evaluate merchandise, add-ons, fulfilment, or inventory only after those owning domains have stable persisted data, access boundaries, tests, and clear enforcement versus presentation rules.

Readiness evaluation must remain server-side and must not call Stripe or remote APIs during page render.
