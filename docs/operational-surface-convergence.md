# Operational Surface Convergence

## My Tickets

My Tickets now derives issued ticket and entitlement rendering from the canonical `myeventlane_tickets.universal_ticket_view_model_builder` service.

The convergence keeps the existing customer routes, order cards, booking detail flow, payment receipt links, ticket PDF links, and legacy order-item fallback. The customer-facing UX is not redesigned; issued ticket rows simply use the same normalized operational model that future wallet, PDF, scanner-adjacent, and entitlement surfaces can share.

Builder-driven rendering matters because operational ticket state is broader than a Commerce order item. The canonical model carries the entitlement type, ticket status, QR payload and data URI, redemption counts, fulfilment state, expiry, collection location/window, vehicle registration, and customer-safe actions from one source of truth.

This removes duplicated operational shaping from `MyTicketsController`, Twig preprocessing, and template-only arrays. The checkout flow controller now delegates My Tickets order presentation to `MyTicketsOrderViewModelBuilder`, which loads issued `myeventlane_ticket` rows for already customer-scoped orders and normalizes each ticket through `UniversalTicketViewModelBuilder`. Legacy order-item rendering remains only as a compatibility fallback for orders that do not yet have issued ticket entities.
