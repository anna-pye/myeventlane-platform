# Event Commerce Boundaries

Status: internal governance  
Scope: event, ticket, Commerce product, and fulfilment ownership

## Principle

Event Studio may surface Commerce operations, but it must not become the Commerce implementation. Money-moving behavior, checkout, carts, Stripe Connect, ticket issuance, and fulfilment state transitions stay with their owning domains.

No future merchandise, add-on, fulfilment, scanning, POS, or analytics feature may bypass Studio for operational UI, and no Studio section may bypass Commerce or ticket domain services for business logic.

## Domain Ownership

| Domain | Owns | Must Not Own |
| --- | --- | --- |
| Event | Visibility, branding, publishing, schedule, operational readiness | Product SKU behavior, ticket issuance, shipping states |
| Ticket | Attendance access, ticket lifecycle, capacities, sales windows | Merchandise, fulfilment products, Stripe charge model |
| Commerce product | Merchandise, add-ons, purchasable upgrades, fulfilment products | Event publish state, ticket scan state |
| Fulfilment | Shipping, pickup, redemption, inventory fulfilment states | Checkout payment state, ticket lifecycle source of truth |

Tickets are not merchandise. Merchandise is not fulfilment. Add-ons are not ticket entities. Future code must preserve separate ownership even when these purchasables share one Commerce cart.

## Purchasable Classifications

Canonical event-commerce classifications live in `web/modules/custom/myeventlane_event/src/Value/EventCommercePurchasableType.php` and are exposed through `web/modules/custom/myeventlane_event/src/Service/EventCommerceClassificationRegistry.php`.

Current classifications:

| Classification | Domain | Meaning |
| --- | --- | --- |
| `ticket` | Ticket | Attendance access purchasable governed by ticket lifecycle and capacity rules. |
| `addon` | Commerce product | Optional event-linked purchasable such as parking, meals, shuttle, camping, or VIP extras. |
| `merchandise` | Commerce product | Physical or digital product sold alongside an event without becoming a ticket. |
| `donation` | Commerce product | Voluntary purchasable that remains a separate Commerce order item boundary. |
| `upgrade` | Commerce product | Purchasable enhancement that must not replace ticket lifecycle logic. |
| `bundle_future` | Commerce product | Reserved classification for a future governed bundle architecture. |

Classifications are metadata contracts. They do not create fields, products, variations, fulfilment records, carts, checkout panes, or Stripe side effects.

## Existing Canonical Services

| Concern | Service or file |
| --- | --- |
| Ticket product sync | `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php` |
| Ticket tier lifecycle | `web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php` |
| Booking mode resolution | `web/modules/custom/myeventlane_event/src/Service/BookingFlowResolver.php` |
| Event Commerce resolution | `web/modules/custom/myeventlane_event/src/Service/EventCommerceResolver.php` |
| Event Commerce classifications | `web/modules/custom/myeventlane_event/src/Service/EventCommerceClassificationRegistry.php` |
| Ticket availability | `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php` |
| Order item classification | `web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php` |
| Ticket issuance | `web/modules/custom/myeventlane_tickets/src/Ticket/TicketIssuer.php` |
| Vendor ownership | `web/modules/custom/myeventlane_checkout_flow/src/Service/VendorOwnershipResolver.php` |

## EventCommerceResolver Contract

`EventCommerceResolver` is read-only. It may:

- Resolve the event ticket product from `field_product_target`.
- Resolve products that reference an event through `field_event`.
- Resolve ticket product variations for display or validation.
- Resolve the event represented by an order item using the same order as ticket issuance: variation `field_event`, product `field_event`, then order item `field_target_event`.
- Resolve unique events across mixed order items.
- Classify or map existing event-to-Commerce relationships when the owning domain supplies the product bundles and semantics.
- Expose grouped read-only relationships by canonical purchasable classification.
- Treat the event `field_product_target` product and its purchasables as `ticket`.

It must not:

- Create or update products.
- Mutate carts.
- Change checkout.
- Trigger Stripe or payment side effects.
- Alter orders.
- Issue tickets.
- Change Stripe or Connect behavior.
- Create fulfilment records.
- Infer future product bundle names without an owning domain supplying them.

## Event-Linked Product Model

The canonical relationship model is:

- Events may reference their canonical ticket product through `field_product_target`.
- Commerce products may reference their event through `field_event`.
- Commerce order items may reference the event through `field_target_event` where the order item type owns that relationship.
- Relationship services resolve links. Product arrays must not be serialized onto event nodes.

Future merchandise and add-on work should prefer event-linked Commerce products and resolver-backed read models over duplicated entity references or hardcoded ids.

## Add-ons And Donations

Add-ons are optional purchasables. They must remain Commerce products or variations linked operationally to events and must not mutate ticket definitions, capacities, attendance access, or ticket issuance.

Donations remain Commerce purchasables. They must keep clean order item boundaries and must not become special-case ticket logic.

## Mixed Cart Governance

MEL must support mixed event carts through Drupal Commerce-native carts, checkout, order items, and payments. A cart may contain:

- `2` VIP tickets.
- `1` parking add-on.
- `1` T-shirt.
- `$10` donation.

This must remain one cart, one checkout, and one payment flow. Do not fork checkout systems or introduce a parallel payment path for merchandise, add-ons, donations, or upgrades.

## Studio Allowed Touches

Event Studio may:

- Show operational Commerce sections.
- Link to explicit Studio routes for tickets, merchandise, add-ons, orders, and fulfilment.
- Display empty states before a domain is implemented.
- Call read-only resolvers for existing event-linked entities.
- Delegate saves to owning domain services.

Event Studio must not:

- Duplicate product sync.
- Duplicate ticket issuance.
- Build parallel checkout flows.
- Mutate carts or orders.
- Read raw payment state for UI decisions.
- Change platform fees, application fees, Connect accounts, payout behavior, or webhook semantics.

## Fulfilment Boundary

Fulfilment is not implemented in this phase. Future fulfilment work must define:

- Inventory ownership.
- Shipping and pickup state machines.
- Redemption rules.
- Refund and cancellation interactions.
- Customer-visible vs vendor-only state.
- Audit logs for state transitions.

Until then, fulfilment sections remain governed placeholders.

Future fulfilment extensions may support shipping, event pickup, QR redemption, or digital fulfilment. They must not hardcode ticket assumptions into fulfilment state.

## Readiness Preparation

Commerce and fulfilment readiness may be added only through future provider contracts after the owning domains have stable persisted data, access boundaries, and verification paths. This phase does not activate merchandise, fulfilment, or inventory readiness providers.

Future provider examples:

- Merchandise readiness.
- Fulfilment readiness.
- Inventory readiness.

These providers must remain read-only during evaluation and must avoid heavy queries on workspace load.

## Studio Access And Performance

Future Commerce Studio sections must:

- Reuse `EventStudioAccess` and `EventVendorAccessChecker`.
- Preserve the `administer nodes` override.
- Preserve vendor-team access checks.
- Block customers and anonymous users server-side.
- Avoid exposing raw Commerce admin routes in Studio.
- Lazy-load expensive data.
- Paginate operational lists.
- Isolate autosave boundaries.
- Avoid rendering large Commerce entity trees inline.

Future merchandise and add-on operational tables must use Studio operational table standards: operational actions, inline validation, isolated saves, responsive overflow handling, and mobile-safe layouts.

## Analytics Boundary

Future analytics must keep metrics separate:

- Attendance metrics.
- Revenue metrics.
- Merchandise metrics.
- Operational metrics.
- Conversion metrics.

Do not create giant mixed analytics queries. Use domain-specific pre-aggregation, pagination, caching, or queues when needed.

## Stripe And Checkout Safety

Commerce expansion must follow `docs/universal-ticket-extension.md`, `docs/event-commerce-classifications.md`, `docs/mixed-cart-governance.md`, and `.cursor/rules/mel-stripe-connect-safety.mdc`.

Changes to charge model, account type, application fees, payout behavior, webhook semantics, or connected-vendor handling require explicit product and technical sign-off before implementation.
