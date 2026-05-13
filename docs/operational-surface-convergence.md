# Operational Surface Convergence

## My Tickets

My Tickets now derives issued ticket and entitlement rendering from the canonical `myeventlane_tickets.universal_ticket_view_model_builder` service.

The convergence keeps the existing customer routes, order cards, booking detail flow, payment receipt links, ticket PDF links, and legacy order-item fallback. The customer-facing UX is not redesigned; issued ticket rows simply use the same normalized operational model that future wallet, PDF, scanner-adjacent, and entitlement surfaces can share.

Builder-driven rendering matters because operational ticket state is broader than a Commerce order item. The canonical model carries the entitlement type, ticket status, QR payload and data URI, redemption counts, fulfilment state, expiry, collection location/window, vehicle registration, and customer-safe actions from one source of truth.

This removes duplicated operational shaping from `MyTicketsController`, Twig preprocessing, and template-only arrays. The checkout flow controller now delegates My Tickets order presentation to `MyTicketsOrderViewModelBuilder`, which loads issued `myeventlane_ticket` rows for already customer-scoped orders and normalizes each ticket through `UniversalTicketViewModelBuilder`. Legacy order-item rendering remains only as a compatibility fallback for orders that do not yet have issued ticket entities.

## Ticket PDFs

PDF rendering for issued tickets now derives operational entitlement data from the canonical `myeventlane_tickets.universal_ticket_view_model_builder` service, eliminating duplicated normalization in the PDF pipeline.

### What converged

- **`TicketPdfGenerator`** — the `generatePdfForTicket()` and `getPdfContentForTicket()` methods now build the render array from the canonical view model instead of manually extracting event title, location, holder name, and ticket code from entity fields. The view model provides all operational metadata (entitlement type, status, QR payload, fulfilment, expiry, badges) from a single normalized source.

- **`TicketPdfTemplateBuilder`** — when an issued `Ticket` entity is present, the preprocess step populates Twig variables from `UniversalTicketViewModelBuilder::build()`. This includes entitlement type and label, ticket status, redemption metadata, expiry state, fulfilment metadata (status, collect location, vehicle registration), and operational badges. Legacy paths (order-item, attendee, RSVP) fall through to the original QR-only preprocessing unchanged.

- **`ticket-pdf.html.twig`** — the template conditionally renders canonical operational data when `is_canonical` is true: entitlement labels for non-admission types, operational badges, fulfilment metadata, expiry notices, and multi-use redemption counts. The existing PDF layout, styling, QR rendering, and branding are preserved.

### What was not changed

- PDF routes remain unchanged (`/ticket/pdf/{order_item_id}` and `/ticket/{ticket_code}/pdf`).
- QR payload structure and generation are untouched — the view model's QR output is the same as the direct `TicketQrPayload` call.
- Legacy order-item PDF path (`generatePdfForOrderItem`, `getPdfContentForOrderItem`) remains for backward compatibility.
- Event attendee and RSVP PDF paths remain unchanged.
- **Order confirmation PDFs** use **`MessagingManager`** → **`OrderConfirmationAttachmentResolver`** → **`TicketPdfGenerator::getPdfContentForTicket()`** for issued tickets. The duplicate **`OrderConfirmationPdfAttachments`** helper was removed from the codebase and container.
- PDF visual design is not redesigned; the template only adds conditional operational metadata blocks.
- Ticket download controller, access checks, and expiry logic are unchanged.

### Eliminated duplication

Before this convergence, `TicketPdfGenerator::generatePdfForTicket()` and `getPdfContentForTicket()` each independently extracted event title, event start, location (with address field parsing), holder name, and ticket code from the raw entity. This duplicated the same normalization that `UniversalTicketViewModelBuilder` already performs, and omitted operational metadata (entitlement type, fulfilment, expiry, badges) entirely. The preprocess layer in `TicketPdfTemplateBuilder` also independently generated the QR data URI from the raw ticket entity.

Now the canonical path is: `UniversalTicketViewModelBuilder::build()` → normalized model → render array → Twig template. No ad-hoc entity field extraction occurs in the PDF pipeline for issued tickets.

## Operational issuance (Phase 2B)

Paid-order **ticket row issuance** is owned by **`TicketIssuer`** on **`ORDER_PAID`** (see **`OrderPaidSubscriber`** in `myeventlane_tickets`). **`event_attendee`** creation on order placement is a separate roster concern and is **not** duplicate issuance.

Canonical order-confirmation PDF merging is documented in [issuance-pipeline.md](./issuance-pipeline.md).
