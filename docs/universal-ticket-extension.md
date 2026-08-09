# Universal Ticket Extension

Phase 2A extends the existing `myeventlane_ticket` content entity into the canonical operational entitlement object. This document records the current implementation before schema and scanner changes so future work does not create a second entitlement, QR, wallet, scanner, or ownership system.

## Phase 2A Foundation Status

`myeventlane_tickets` owns the universal entitlement fields, services, scanner
integration, and runtime behaviour. `mel_universal_ticket` is retained for one
transition release as a compatibility shim so existing environments can
transfer legacy field-provider metadata before Drupal uninstalls it.

Deployment order for the transition release is mandatory:

1. Run database updates, including `myeventlane_tickets_update_8011()`.
2. Confirm the ticket and redemption-log fields identify
   `myeventlane_tickets` as their last-installed provider.
3. Import configuration, which disables `mel_universal_ticket`.
4. Rebuild caches and verify ticket issue, QR, scan, download, and wallet paths.

The old service ID `mel_universal_ticket.capability_manager` remains an alias of
`mel_ticket_capability.manager`. New consumers must use the canonical service.

The requested `field_*` capability names map to existing code-defined base fields on `myeventlane_ticket`:

- `field_entitlement_type` reuses `entitlement_type`
- `field_redemption_limit` reuses `redemption_limit`
- `field_redemption_count` reuses `redemption_count`
- `field_fulfilment_status` reuses `fulfilment_status`
- `field_collect_window` reuses `collect_window`
- `field_collect_location` reuses `collect_location`
- `field_vehicle_registration` reuses `vehicle_registration`
- `field_vendor_reference` reuses `vendor_reference`
- `field_expires_at` reuses `expires_at`
- `field_metadata_json` reuses `metadata_json`

This avoids duplicate storage and preserves existing ticket rows, revisions, QR compatibility, wallet rendering, and Commerce order flows.

## Canonical Object

`myeventlane_ticket` is defined in `web/modules/custom/myeventlane_tickets/src/Entity/Ticket.php`.

The entity type uses:

- Entity ID: `myeventlane_ticket`
- Base table: `myeventlane_ticket`
- Data table: `myeventlane_ticket_field_data`
- Entity label: `ticket_code`
- Entity owner: `purchaser_uid`
- Storage handler: `Drupal\Core\Entity\Sql\SqlContentEntityStorage`
- Access handler: core `Drupal\Core\Entity\EntityAccessControlHandler`

Current base fields are code-defined, not config-sync field storage:

- `ticket_code`
- `event_id`
- `order_id`
- `order_item_id`
- `purchased_entity`
- `ticket_type_config`
- `mel_ticket_type`
- `purchaser_uid`
- `holder_name`
- `holder_email`
- `status`
- `checked_in_at`
- `checked_in_by`
- `created`
- `changed`

The entity schema is managed through `myeventlane_tickets.install`, including historical updates that install entity tables, add `checked_in_by`, and create the current `myeventlane_ticket_checkin_log` table.

## Issuance Flow

Ticket issuance is owned by `myeventlane_tickets`.

The primary issuance path is:

1. Commerce emits `OrderEvents::ORDER_PAID`.
2. `web/modules/custom/myeventlane_tickets/src/EventSubscriber/OrderPaidSubscriber.php` receives the paid order.
3. `web/modules/custom/myeventlane_tickets/src/Ticket/TicketIssuer.php` creates one `myeventlane_ticket` per order item quantity.

`TicketIssuer` links each issued ticket to:

- Event: `event_id`
- Commerce order: `order_id`
- Commerce order item: `order_item_id`
- Purchased variation: `purchased_entity`
- MEL ticket type: `mel_ticket_type`, when resolvable
- Purchaser / owner: `purchaser_uid`

Event resolution follows the existing chain:

1. Purchased variation `field_event`
2. Product `field_event`
3. Order item `field_target_event`

Universal entitlement generation must extend this issuer. It must not add a parallel generation pipeline.

## QR Payload Flow

The canonical ticket QR implementation lives in `web/modules/custom/myeventlane_tickets/src/Ticket/TicketQrPayload.php`.

The current signed format is:

```text
mel:v1:{ticket_code}:{event_id}:{issued_ts}:{signature}
```

The signature is an HMAC-SHA256 over:

```text
{ticket_code}:{event_id}:{issued_ts}
```

`TicketQrPayload` also supports a configured `code_only` mode and accepts legacy non-prefixed input as a raw ticket code.

The QR image path is:

1. `TicketPdfTemplateBuilder::build()`
2. `TicketQrPayload::buildForTicket()`
3. `QrCodeGenerator::buildDataUri()`
4. `ticket-pdf.html.twig`

The `mel:v1:` prefix remains canonical. New entitlement metadata must extend this payload family and preserve existing scans.

## Scanner Flow

The canonical scanner route family is defined in `web/modules/custom/myeventlane_tickets/myeventlane_tickets.routing.yml`.

Important routes include:

- `myeventlane_tickets.ticket_checkin`
- `myeventlane_tickets.ticket_checkin_validate`
- `myeventlane_tickets.ticket_scan`
- `myeventlane_tickets.ticket_checkin_api_submit`
- `myeventlane_tickets.ticket_checkin_api_batch`

The route/controller/service path is:

1. `TicketScanController` renders the scanner shell and attaches scanner settings.
2. `TicketCheckinApiController` accepts live and offline scanner submissions.
3. `TicketCheckinForm` supports manual entry.
4. `TicketCheckinService` performs validation and mutation.
5. `TicketCheckinLogger` writes every validation attempt to `myeventlane_ticket_checkin_log`.

Current validation checks include:

- Route event must be an event node.
- Scanner user must have event ticket access.
- Signed QR event must match route event.
- Loaded ticket `event_id` must match route event.
- Refunded, void, and already checked-in tickets cannot be admitted again.

Universal scan operations must extend this funnel. Business rules must not move into controllers, Twig, or JavaScript.

## Wallet And Customer Surface

Customer ticket display currently uses Commerce orders and booking detail pages rather than a separate entitlement wallet entity.

The customer hub path is:

- Route/controller: `web/modules/custom/myeventlane_checkout_flow/src/Controller/MyTicketsController.php`
- Overview template: `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-my-tickets.html.twig`
- Detail template: `web/modules/custom/myeventlane_checkout_flow/templates/myeventlane-order-detail.html.twig`
- Theme overrides delegate to the module templates.

Apple and Google pass routes exist in `myeventlane_wallet`, but the current wallet builders are pass-generation placeholders. This slice documents the wallet surface only and does not rebuild it.

Future wallet grouping should add entitlement grouping to the existing My Tickets / order detail surfaces. It must not create a second wallet model.

## Ownership And Access

Customer ownership is the ticket entity owner:

- `purchaser_uid` is the owner key.
- `TicketIssuer` sets it from the Commerce order customer.

Event and vendor ownership are enforced by existing route and service patterns:

- `myeventlane_tickets.access.event_tickets`
- `myeventlane_tickets.event_access`
- `myeventlane_vendor.event_access_checker`
- Event owner checks
- Event `field_event_vendor` to vendor `field_vendor_users`

Vendor store relationships already exist through Commerce store `field_vendor_reference` and event/vendor resolver services. Entitlement access should reuse those relationships and must not invent a new vendor ownership model.

## Parallel-Looking Systems To Avoid Expanding

The repository contains older or adjacent scan mechanisms that are not the canonical `myeventlane_ticket` entitlement path:

- Legacy order-item ticket codes such as `MEL-{event}-{order}-{order_item}-{hash}` are supported as scan fallbacks.
- RSVP QR and paragraph check-in flows exist outside `myeventlane_tickets`.
- `myeventlane_commerce` has order-completion PDF/wallet orchestration stubs that use service locator calls.
- Apple/Google wallet builders currently exist as route/service placeholders.

Phase 2A must preserve compatibility with these paths where they already exist, but universal event entitlement behavior belongs on `myeventlane_ticket`.

## Phase 2A Extension Rules

- Do not create `mel_entitlement`.
- Do not create another QR prefix or scanner protocol.
- Do not create a second customer wallet model.
- Do not duplicate ticket issuance.
- Do not store ownership in fulfilment or redemption logs.
- Keep all access checks server-side.
- Use scoped entity queries and `accessCheck(TRUE)` for user-facing queries. Internal scanner resolution may use `accessCheck(FALSE)` only when already protected by route/event access and documented in code.
