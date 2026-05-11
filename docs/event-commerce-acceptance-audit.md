# Event Commerce Acceptance Audit

Status: acceptance audit  
Scope: Event Commerce resolver, classification registry, purchasable classifications, and mixed-cart governance foundation  
Date: 2026-05-11

## Objective

This audit validates that the event-commerce governance foundation is operationally passive. The reviewed layer may resolve existing relationships, classify existing purchasables from explicit metadata, and expose read-only groupings. It must not mutate carts, orders, checkout, products, ticket lifecycle, RSVP state, Stripe behavior, payment routing, capacities, or fulfilment state.

No merchandise UI, add-on forms, fulfilment implementation, checkout panes, order processors, Stripe logic, Studio sections, entity fields, or workflows were added as part of this audit.

## Audited Components

- `web/modules/custom/myeventlane_event/src/Service/EventCommerceResolver.php`
- `web/modules/custom/myeventlane_event/src/Service/EventCommerceClassificationRegistry.php`
- `web/modules/custom/myeventlane_event/src/Value/EventCommercePurchasableType.php`
- `web/modules/custom/myeventlane_event/myeventlane_event.services.yml`
- `docs/event-commerce-boundaries.md`
- `docs/event-commerce-classifications.md`
- `docs/mixed-cart-governance.md`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventReadinessService.php`
- `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.services.yml`
- `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml`
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`
- `web/modules/custom/myeventlane_tickets/src/Ticket/TicketIssuer.php`
- `web/modules/custom/myeventlane_tickets/src/EventSubscriber/OrderPaidSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/PaymentGateway/StripeConnect.php`
- `web/modules/custom/myeventlane_commerce/src/Service/StripeConnectPaymentService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php`

## Boundary Findings

`EventCommerceResolver` is read-only. It resolves the canonical ticket product from `field_product_target`, event-linked products from `field_event`, ticket product variations, event relationships from order items, and normalized relationship groups. It uses entity queries and entity loads only. It does not call `save()`, `delete()`, `addOrderItem()`, `submitForm()`, Stripe APIs, payment services, checkout services, cart services, capacity services, or ticket issuer services.

`EventCommerceClassificationRegistry` is read-only. It returns canonical metadata from `EventCommercePurchasableType`, normalizes known classification IDs, and classifies products or variations only from explicit bundle maps supplied by owning domains. It does not infer bundle behavior and does not persist state.

`EventCommercePurchasableType` is a metadata contract only. It defines canonical classification constants and metadata such as domain, attendance access, and fulfilment requirement. It creates no fields, no products, no variations, no order items, no checkout panes, and no Stripe side effects.

Service-boundary search found the resolver and classification registry defined only in `myeventlane_event.services.yml`. They are not injected into checkout processors, order refresh processors, payment gateways, Stripe services, ticket issuance subscribers, RSVP services, Studio services, readiness services, or vendor publishing gates.

## Mutation Checks

Searches inside resolver/classification paths found no operational mutation calls:

- No `save()` or `delete()`.
- No `addOrderItem()` or Commerce cart manager usage.
- No `submitForm()` or checkout pane implementation.
- No order refresh, checkout flow, payment gateway, or Stripe calls.
- No ticket issuer, attendee creation, QR generation, or email dispatch calls.
- No capacity mutation or lifecycle archive/publish calls.

The only array mutations in the resolver are in-memory grouping helpers used to normalize return values. They do not write entities or session/cart state.

## Operational Flow Audit

### RSVP Event Flow

RSVP runtime remains owned by the existing RSVP and attendee modules. Public RSVP forms, RSVP confirmation, RSVP thank-you handling, RSVP dashboard/listing routes, and RSVP mailer paths do not inject or call the event-commerce resolver or classification registry.

No classification logic affects RSVP creation, save, publish, frontend rendering, submission, confirmation, or dashboard visibility.

### Paid Ticket Flow

Paid ticket creation, editing, archiving, and Commerce projection remain owned by `TicketTierLifecycleService`, `TicketTypeManager`, and existing ticket forms/controllers. The resolver/classification layer is not called by ticket lifecycle code.

Ticket selection and add-to-cart remain owned by `TicketSelectionForm`, which uses Commerce cart services directly and continues to set `field_target_event` on ticket order items. The new governance layer does not add or remove cart items.

Ticket issuance remains owned by `TicketIssuer` through `OrderPaidSubscriber`. Issuance still runs from Commerce order paid events and creates ticket entities from order items with purchasable Commerce product variations and event relationships. The governance layer does not call the issuer and does not alter issuer behavior.

Residual guard: current issuance is not classification-driven. Before any future non-ticket event-linked purchasable is made orderable, issuance must continue to be constrained to ticket-owned order item semantics so add-ons, merchandise, donations, upgrades, and future bundles cannot issue attendance access.

### Mixed Event States

Draft, published, unpublished, archived ticket, RSVP, paid, and hybrid readiness remain evaluated by existing event type, ticket lifecycle, and readiness code. `EventReadinessService` uses `TicketTypeManager`, `VendorPublishRequirementsGate`, and `PaidPublishStripeGate`; it does not use classification services.

Archived tickets remain excluded by ticket lifecycle/readiness checks. The resolver does not publish, unpublish, archive, detach, attach, or sync ticket rows.

### Checkout Flow

Checkout remains Drupal Commerce-native. The checkout flow is still `MelEventCheckoutFlow`, extending Commerce checkout panes, with existing panes and services. The governance layer does not define checkout panes, checkout flows, order processors, order refresh processors, or cart forms.

Cart item creation, quantity handling, and removal remain Commerce/cart-owned. Ticket add-to-cart remains in `TicketSelectionForm`; update/remove flows remain Commerce cart UI behavior.

### Ticket Issuance

Attendance access remains ticket-domain-owned. `OrderPaidSubscriber` calls `TicketIssuer::issueForOrder()` on the existing Commerce order paid event. `TicketIssuer` generates ticket rows, ownership, ticket code, order linkage, order item linkage, purchased entity linkage, and MEL ticket type linkage without using classification services.

`OrderCompletedSubscriber` continues to create attendee records from ticket holder paragraphs on order placement and separates RSVP versus paid records by price/source. It does not call the classification layer.

### Stripe Flow

Stripe Connect behavior remains unchanged. `StripeConnect` still delegates payment intent creation to the existing Stripe gateway parent after merging parameters from `StripeConnectPaymentService`. `StripeConnectPaymentService` still uses existing order item classifier logic, store Stripe fields, and order item price/bundle checks.

No event-commerce classification metadata is passed to Stripe metadata. No resolver or classification registry service is injected into Stripe gateway, Stripe validation, vendor onboarding, payout routing, or Connect publish gates.

### Studio And Readiness

Event Studio readiness remains operationally unchanged. Tickets are active readiness participants; merchandise, add-ons, fulfilment, orders, and analytics sections are metadata/deferred operational sections and do not add Commerce mutation forms in this phase.

Autosave and publish remain owned by existing Studio save/readiness services and vendor access checks. The commerce governance resolver is not injected into Studio save, autosave, readiness, section manager, or controller services.

### Access Flow

Studio access remains enforced server-side by `EventStudioAccess` and vendor access services. The governance layer does not change route access requirements or vendor/customer isolation.

## Database And Config Safety

No schema or update-path changes were introduced by the governance layer. `ddev drush updb -y` reported no pending updates.

`ddev drush config:status` reported no differences between the active database configuration and `config/sync`, confirming the audit did not create hidden config mutations or config drift.

Searches of `config/sync` found no references to `EventCommerceResolver`, `EventCommerceClassificationRegistry`, or event-commerce classification services.

## Required Assertions

- EventCommerceResolver is read-only: verified.
- Classification registry does not mutate runtime state: verified.
- Checkout still Commerce-native: verified.
- Ticket issuance still ticket-domain-owned: verified.
- RSVP flow unchanged by classification layer: verified.
- Stripe flow unchanged by classification layer: verified.
- Readiness unchanged operationally: verified.
- No new save boundaries introduced by resolver/classification paths: verified.
- No new Commerce mutations introduced by resolver/classification paths: verified.
- No order-item behavior changes introduced by resolver/classification paths: verified.

## Verification Commands

- `ddev exec php -l web/modules/custom/myeventlane_event/src/Service/EventCommerceResolver.php`: pass.
- `ddev exec php -l web/modules/custom/myeventlane_event/src/Service/EventCommerceClassificationRegistry.php`: pass.
- `ddev exec php -l web/modules/custom/myeventlane_event/src/Value/EventCommercePurchasableType.php`: pass.
- `composer validate`: pass.
- `ddev drush status`: Drupal 11.3.8 bootstrapped successfully at `https://myeventlane.ddev.site`.
- `ddev drush cr`: pass.
- `ddev drush updb -y`: pass, no pending updates.
- `ddev drush config:status`: pass, no active/sync differences.
- `git diff --check`: pass.
- `ddev drush ev` fixture check: confirmed published RSVP event `1381` and published paid event `1592`.
- `ddev drush ev` resolver smoke check: confirmed `myeventlane_event.commerce_resolver` and `myeventlane_event.commerce_classification_registry` are registered, event `1592` resolves ticket product `97`, and one ticket purchasable variation is returned without writes.
- DDEV HTTP smoke checks: `/node/1381` returned `200`, `/node/1592` returned `200`, `/event/1381/rsvp` redirected to `/event/1381/book` and returned `200`, and anonymous `/vendor/events/1592/studio` returned `403`.

Real browser validation is recorded below.

## Browser Validation

Browser validation against DDEV was started at `https://myeventlane.ddev.site` through the Cursor browser automation path. That pass did not return a final result within the audit window, so the browser portion is not marked complete.

Command-line DDEV smoke checks were completed for the same fixture routes:

- Published RSVP event page `1381`: reachable.
- Published paid event page `1592`: reachable.
- RSVP route for event `1381`: reachable through the existing booking redirect.
- Anonymous Studio access for event `1592`: denied with `403`.

The following manual browser matrix items remain open before treating this as a fully browser-passed acceptance audit:

- Submit a real RSVP through the DDEV browser and confirm the thank-you/dashboard visibility path.
- Add a paid ticket to cart, update quantity, remove an item, proceed through checkout, and verify whether payment completion is blocked by local Stripe configuration.
- Complete or explicitly block ticket issuance verification from a paid order in the browser.
- Verify Studio autosave/readiness/ticket editing through authenticated browser interactions.

## Residual Risks

The current governance layer is passive because it is not wired into operational mutation paths. Future event-linked purchasables must not rely on this absence as a safety mechanism. Before merchandise, add-ons, donations, upgrades, or future bundles become orderable alongside tickets, the owning domain must enforce explicit order item boundaries and ticket issuance exclusions.

`TicketIssuer` currently resolves attendance access from Commerce variation/order item event relationships, not from `EventCommercePurchasableType` metadata. That is acceptable for the current shipped ticket-only paid flow, but future non-ticket event-linked products must be guarded so they cannot satisfy ticket issuance conditions accidentally.

The audit did not change Stripe architecture and did not validate live Stripe payment settlement beyond existing DDEV/browser checkout smoke limits.

## Future Guarded Extension Notes

Future commerce expansion may only proceed after the acceptance audit remains green and the owning domains provide explicit contracts for bundle IDs, classification maps, access rules, persistence, order item semantics, readiness behavior, and test coverage.

Merchandise, add-ons, fulfilment, mixed-cart tooling, checkout extensions, order processors, and Stripe changes must be implemented in their owning domains. Event Studio may surface operational views and empty states, but must not become the transactional implementation.
