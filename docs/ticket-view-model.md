# Ticket View Model

Phase 2B makes `myeventlane_ticket` the canonical operational source of truth for issued entitlements. Commerce order items remain purchase, checkout, and payment context; customer-facing entitlement surfaces should not reconstruct operational truth from order item fields when an issued ticket exists.

## Builder Service

The canonical builder service is:

```text
myeventlane_tickets.universal_ticket_view_model_builder
```

Implementation:

```text
web/modules/custom/myeventlane_tickets/src/Service/UniversalTicketViewModelBuilder.php
```

The builder accepts `Drupal\myeventlane_tickets\Entity\Ticket` entities and returns DTO-style associative arrays for My Tickets, wallet builders, PDFs, entitlement cards, scanner-adjacent displays, and future merch, parking, hospitality, food, or drink surfaces.

## Operational Truth

`myeventlane_ticket` remains central because it already carries the operational fields needed after purchase:

- ticket identity: `id`, `uuid`, `ticket_code`
- event reference: `event_id`
- purchase context references: `order_id`, `order_item_id`, `purchased_entity`
- holder state: `holder_name`, `holder_email`, `purchaser_uid`
- lifecycle state: `status`, `checked_in_at`, `checked_in_by`
- entitlement state: `entitlement_type`, `redemption_limit`, `redemption_count`
- fulfilment state: `fulfilment_status`, `collect_window`, `collect_location`
- operational extensions: `vehicle_registration`, `vendor_reference`, `expires_at`, `metadata_json`

Order items can still identify the purchase and preserve existing route compatibility, but they are not the operational entitlement record.

## Output Contract

`UniversalTicketViewModelBuilder::build()` returns a normalized array with these top-level keys:

- `ticket`: id, uuid, code, status, status label, entitlement type, entitlement label.
- `event`: id, label, URL, start/end raw values and timestamps, location.
- `holder`: holder name, holder email, purchaser uid.
- `qr`: canonical QR payload and optional QR data URI.
- `redemption`: redemption limit, count, remaining count, multi-use flag.
- `expiry`: raw expiry value, timestamp, expired flag.
- `fulfilment`: status, status label, collection location/window, vehicle registration, metadata.
- `vendor`: display id, label, and source.
- `badges`: normalized operational badge tokens and labels.
- `actions`: PDF and wallet action metadata.
- `scanner`: scan eligibility, status token, status message.

Consumers should use these structures directly instead of building parallel arrays in controllers or Twig.

## QR Compatibility

The view model builder does not generate or parse QR payloads itself. It delegates to:

```text
myeventlane_tickets.ticket_qr_payload
```

That preserves existing `mel:v1:` signed payloads, structured `mel:v1:json:` payloads, and configured legacy/code-only compatibility. QR image data is produced only through the existing QR generator service.

## Action Compatibility

PDF actions point to the existing canonical ticket-code route:

```text
myeventlane_tickets.download_pdf_by_code
```

Wallet actions preserve existing order-item route contracts while this phase prepares wallet builders to consume ticket view models:

```text
myeventlane_wallet.apple
myeventlane_wallet.google
```

If wallet routes are not available in a runtime module graph, the builder leaves the generated URL empty while preserving the route metadata. Later wallet convergence should keep the same access boundaries and route compatibility unless a dedicated migration is approved.

## Access Boundary

The builder is a normalization service, not an access service. Callers must enforce the correct server-side boundary before passing tickets into the builder:

- customers: only their own ticket entitlements
- vendors: only tickets tied to their events or fulfilment scope
- staff: scoped operational visibility
- admins: explicit full-access permissions

Do not rely on Twig hiding or frontend filtering. Any new surface consuming this builder must still use entity access, scoped queries, route access, or existing ownership services.

## Backwards Compatibility

This slice does not remove legacy order-item PDF routes, attendee fallbacks, wallet routes, scanner routes, or `mel:v1:` QR support. It introduces the shared model those surfaces will converge on in later Phase 2B slices.

## QR inclusion flag

`UniversalTicketViewModelBuilder::build($ticket, bool $include_qr = TRUE, bool $allow_qr_unavailable = FALSE)` controls QR generation and missing-secret behaviour.

| Caller | `$include_qr` | `$allow_qr_unavailable` |
|--------|----------------|-------------------------|
| My Tickets overview (`/my-tickets`) | `FALSE` | n/a (QR skipped) |
| My Tickets order detail | `TRUE` | `TRUE` (via `MyTicketsOrderViewModelBuilder`) |
| PDF / Apple Wallet / default `build()` | `TRUE` (default) | `FALSE` (default; fail-loud) |

When signing is required (`qr_payload_mode` ≠ `code_only`) and `MEL_QR_SECRET` (or settings equivalent) is missing:

- `$allow_qr_unavailable = TRUE` → `qr.unavailable = TRUE` (customer order detail trust panel)
- otherwise → `RuntimeException` (PDF / wallet / canonical default paths)

`code_only` mode does not require a signing secret; `drush mel:qr-secret-status`, `mel:health`, and `mel:healthcheck` treat a missing secret as PASS in that mode.

Direct `TicketQrPayload::buildForTicket()` callers (e.g. scanners) still fail loud when signing is required and the secret is missing.

