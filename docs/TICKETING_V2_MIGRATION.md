# Ticketing v2 — migration notes

## Assumptions

- Production sites already ran `mel_ticket` install / config import so `field_ticket_types` targets `mel_ticket_type` (not paragraphs).
- Historical `myeventlane_schema_update_9002()` is **not** re-run; it remains in `myeventlane_schema.install` for DBs that applied it long ago.

## Paragraph → mel_ticket_type

1. Export ticket data from legacy `ticket_type_config` paragraphs (if any rows remain).
2. For each paragraph row, create a `mel_ticket_type` with matching kind/price/capacity/sale window (paid tiers require `ticket_kind = paid` + `commerce_price`).
3. Append new IDs to `node.event.field_ticket_types` in display order.
4. Run `TicketTypeManager::syncTicketTypesToVariations()` (or save event with paid/both type) to create or refresh Commerce variations.
5. After verification, delete paragraph entities and uninstall obsolete paragraph fields (separate update hook; not shipped in this phase).

## Orphan Commerce variations

- Re-run sync for each event product; `TicketTypeManager::removeOrphanedVariations()` unpublishes variations not referenced by active paid `mel_ticket_type` rows attached to the event.

## Issued tickets (`myeventlane_ticket`)

- `TicketIssuer` already resolves `mel_ticket_type` where possible; add a one-off script to set `mel_ticket_type` from order item / variation mapping for rows still NULL (query + load + save).

## Config import

- After pull: `drush cim` (or partial import) for `commerce_product.commerce_product_type.ticket` → `multipleVariations: true` if the active site still has `false`.

## Cache

- `drush cr` after deploy; container rebuild picks up removed `mel_ticket.services.yml` and new services.
