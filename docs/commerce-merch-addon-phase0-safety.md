# Commerce merchandise and add-on Phase 0 safety

Status: implemented safety slice  
Scope: ticket issuance and Stripe Connect ticket revenue boundaries only

## What was unsafe

Before this slice:

- `TicketIssuer` issued `myeventlane_ticket` rows for any Commerce variation with a resolvable event (variation `field_event`, product `field_event`, or order item `field_target_event`).
- Operational merchandise and add-on products use the same event linkage fields, so parking, T-shirts, hospitality, and similar lines could create attendance tickets by mistake.
- `StripeConnectPaymentService::calculateTicketRevenue()` summed all paid, non-donation, non-boost order items, so operational extras could inflate vendor Connect transfers and application-fee bases.

## Ticket issuance guard

`TicketIssuer` now injects `myeventlane_commerce.ticket_backed_order_item_classifier` (`TicketBackedOrderItemClassifier`).

- `isOrderItemEligibleForTicketIssuance()` requires `TicketBackedOrderItemClassifierInterface::isTicketBackedOrderItem()` **and** existing event resolution.
- `issueForOrder()` and `countExpectedIssuanceUnits()` use the same eligibility path.
- RSVP flows are unchanged (no Commerce ticket issuer involvement).

## Stripe ticket revenue guard

`StripeConnectPaymentService::calculateTicketRevenue()` and `calculateTicketRevenueCentsForEvent()` include only line items where the ticket-backed classifier returns TRUE.

- Donation and boost exclusions are unchanged (`OrderItemClassifier` / boost bundle checks run first).
- No new Stripe API calls, webhook handlers, or charge-model changes.
- **Deferred:** separate Connect transfer rules for operational merchandise/add-on revenue (document gap only; do not infer payout splits in this slice).

## Classification ownership

| Concern | Owner |
| --- | --- |
| Ticket-backed vs operational order items | `TicketBackedOrderItemClassifier` (`myeventlane_commerce`) |
| Operational Commerce bundle allow-list | `OperationalProductBundles` (`myeventlane_core`) |
| Bundle → canonical classification map | `OperationalProductBundles::productBundleClassifications()` |
| Canonical classification metadata | `EventCommercePurchasableType` + `EventCommerceClassificationRegistry` (`myeventlane_event`) |
| Read-only event/product grouping | `EventCommerceResolver` (pass explicit maps from `OperationalProductBundles`) |

Operational bundle map (conservative):

| Product bundle | Classification |
| --- | --- |
| `operational_merchandise` | `merchandise` |
| `operational_bundle` | `addon` |
| `hospitality_package` | `addon` |
| `timed_collection_product` | `addon` |

## Deferred

- Fulfilment, operational inventory execution, and scanner flows.
- Merch/add-on UX expansion beyond existing Phase 4D–4F surfaces.
- Dedicated Connect transfer allocation for operational revenue.
- Browser-level mixed-cart QA (see below).

## Manual browser matrix (still required)

Checkout path: **2 tickets + parking + T-shirt → paid → ticket count equals ticket quantity only** (operational lines must not increase `myeventlane_ticket` rows).
