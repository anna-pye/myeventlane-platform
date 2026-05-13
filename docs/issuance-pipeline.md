# Operational issuance pipeline

This document describes the **canonical operational issuance lifecycle** for paid Commerce ticket orders, how it differs from **attendee/roster** handling, and what was removed during issuance pipeline convergence (Phase 2B, Commit 4).

## Canonical lifecycle

The platform treats **`myeventlane_ticket` entities** as the single source of operational entitlement truth (codes, QR payloads, PDFs, wallet links, scanner state). Commerce **order items** remain **purchase context** (SKU, quantity, price, checkout answers), not operational truth.

Intended flow:

1. **`OrderEvents::ORDER_PAID`** — Commerce marks the order paid.
2. **`OrderPaidSubscriber`** (`myeventlane_tickets`) — Calls **`TicketIssuer::issueForOrder()`**.
3. **`TicketIssuer`** — Creates one **`myeventlane_ticket`** row per unit quantity for eligible order items (variation-backed, event-resolvable).
4. **QR payloads** — Built from ticket entities via **`TicketQrPayload`** and **`QrCodeGenerator`** (also surfaced through **`UniversalTicketViewModelBuilder`**).
5. **PDFs** — Generated through **`TicketPdfGenerator`** (`myeventlane_tickets.ticket_pdf_generator`), using the canonical view model for issued tickets. Legacy paths (order item, attendee, RSVP) remain for compatibility and are **not** part of the paid-order issuance slice; they are isolated in compatibility adapters (see [legacy-pdf-compatibility.md](./legacy-pdf-compatibility.md)).
6. **Wallet** — The **`myeventlane_wallet`** module is currently **scaffold/stub** (builders and routes exist; pass generation is not production-complete). **`UniversalTicketViewModelBuilder`** exposes wallet **action URLs** that reference existing routes; **no** full wallet convergence was done in this commit. Future wallet generation must **derive from issued ticket entities**, not raw order items.
7. **Notifications** — Order confirmation PDFs at send time are merged by **`MessagingManager`** → **`OrderConfirmationAttachmentResolver`** → **`TicketPdfGenerator::getPdfContentForTicket()`** per ticket row for the order. Ticket-ready email uses **`TicketMailer`**, which attaches PDFs from **`TicketPdfGenerator`** for assigned tickets.

```mermaid
flowchart LR
  OrderPaid[ORDER_PAID]
  Sub[OrderPaidSubscriber_tickets]
  Issuer[TicketIssuer]
  Ent[myeventlane_ticket]
  Qr[TicketQrPayload_QrCodeGenerator]
  Pdf[TicketPdfGenerator]
  Msg[MessagingManager_OrderConfirmationAttachmentResolver]
  Mail[TicketMailer]

  OrderPaid --> Sub --> Issuer --> Ent
  Ent --> Qr
  Ent --> Pdf
  Ent --> Msg
  Ent --> Mail
```

## Event subscribers and entry points

| Entry | Module | Role |
| --- | --- | --- |
| `OrderEvents::ORDER_PAID` | `myeventlane_tickets` | **`OrderPaidSubscriber`** → **`TicketIssuer`** (canonical issuance). |
| `commerce_order.place.post_transition` | `myeventlane_commerce` | **`OrderCompletedSubscriber`** → **`event_attendee`** creation, `extra_data` sync, roster repair. **Not** ticket entity issuance. |
| Entity insert / domain events | `myeventlane_domain_events` | Downstream signals (e.g. payment succeeded, ticket issued); unchanged in this slice. |
| Messaging send pipeline | `myeventlane_messaging` | **`OrderConfirmationAttachmentResolver`** merges ticket PDFs for `order_confirmation`. |

A vestigial **`OrderPaidSubscriber`** under **`myeventlane_commerce`** (logging stub, never registered in `myeventlane_commerce.services.yml`) was **removed** to avoid confusion with the real tickets subscriber.

## Attendee (`event_attendee`) vs ticket (`myeventlane_ticket`)

**`event_attendee`** is a separate **roster / attendance** domain model (holder identity, accessibility, purchaser linkage, `extra_data` from holder paragraphs). It is created from checkout data on order placement and must **not** be conflated with operational ticket issuance.

**`myeventlane_ticket`** is the **operational entitlement** row used for QR, PDF, wallet links, and check-in. Both may exist for the same order; they serve different purposes.

## Removed duplicate / dead operational paths

- **`OrderCompletedSubscriber`** previously called private helpers that referenced **non-existent service IDs** (`myeventlane_tickets.pdf`, `myeventlane_wallet.pk_pass`, `myeventlane_wallet.google_wallet`). Those code paths **never** ran successfully (`hasService` always false). They were **removed**. Attendee creation is **unchanged**.

- The real PDF service IDs are **`myeventlane_tickets.ticket_pdf_generator`** and the alias **`myeventlane_tickets.pdf_generator`**. Wallet builder IDs include **`myeventlane_wallet.pkpass_builder`** and **`myeventlane_wallet.google_wallet_builder`**.

## Order confirmation PDF attachments

**Canonical path:** **`MessagingManager`** → **`OrderConfirmationAttachmentResolver`** → **`TicketPdfGenerator`** → issued **`myeventlane_ticket`** entities.

**`OrderConfirmationPdfAttachments`** (tickets module) was an alternate merge helper that was **never referenced** outside its service definition. The **class and service registration were removed** after confirming no contrib, decoration, or cross-module references to `myeventlane_tickets.order_confirmation_pdf_attachments`.

## Idempotency (ORDER_PAID replay)

**`TicketIssuer::issueForOrder()`** performs an **order-scoped, deterministic** guard: if **any** `myeventlane_ticket` row already exists for the order ID, issuance **aborts** with an **info** log. It does **not** partially issue, reconcile, or mutate existing ticket rows.

## Service locator cleanup (RSVP)

**`RsvpMailer`** no longer uses `\Drupal::service('myeventlane_tickets.pdf_generator')` for RSVP PDF attachments; it receives **`@?myeventlane_tickets.ticket_pdf_generator`** via constructor injection.

## Tests

Kernel coverage: **`IssuancePipelineConvergenceTest`** (`myeventlane_tickets`).

- Issuance creates one ticket entity per unit quantity.
- **Regression:** calling **`issueForOrder()`** twice on the same order does **not** create additional ticket rows.
- **`getPdfContentForTicket()`** succeeds for issued tickets after holder fields are set (PDF continuity from ticket entities).
- **`OrderConfirmationAttachmentResolver::mergeOrderConfirmationAttachments()`** appends one PDF per issued ticket for `order_confirmation` while preserving queued non-PDF attachments (same behavior as production **`MessagingManager`** wiring).

## Manual verification checklist

Performed in a full environment (e.g. DDEV) with real mail and checkout:

1. Paid orders still create **`myeventlane_ticket`** rows after payment.
2. Order confirmation emails still include ticket PDFs (resolver path).
3. Assigned-ticket / ticket-ready emails still attach PDFs (**`TicketMailer`**).
4. Ticket download URLs unchanged.
5. Existing QR codes for already-issued tickets unchanged (no signing or payload changes in this slice).
6. Guest checkout still issues tickets when the order is paid.
7. No duplicate ticket rows for repeated paid transitions on the same order (idempotent guard).
8. No duplicate PDF merge from removed dead paths; confirm order confirmation attachment count matches ticket count where expected.
9. No vendor/customer data leakage in logs or attachments (existing access rules unchanged).

## Related documentation

- [operational-surface-convergence.md](./operational-surface-convergence.md) — My Tickets and PDF rendering convergence onto **`UniversalTicketViewModelBuilder`**.
- [legacy-pdf-compatibility.md](./legacy-pdf-compatibility.md) — **Governance:** operational authority vs legacy PDF adapters, inward-only delegation, frozen contracts, forbidden patterns (Commit 5+).
