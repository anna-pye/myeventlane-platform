# Task 5D — Event Studio ticket save persistence (field_ticket_types canonical sync)

**Date:** 2026-04-28

## Problem

Paid ticket builder actions (create, save & sync) ran and Commerce variation sync logged success, but Event Studio still treated tickets as not persisted because **`node.field_ticket_types`** did not list the `mel_ticket_type` IDs, while ticket entities correctly referenced the event via **`mel_ticket_type.event`** (inverse reference).

Example (**event 1567**, local DDEV):

| Before fix | After reconcile |
|------------|-----------------|
| `field_ticket_types`: empty | `field_ticket_types`: `88`, `89` (ticket entities tied to event 1567) |
| `field_product_target`: product 90 | unchanged (already set) |
| Inverse query `event = 1567`: tickets 88, 89 | same |

`TicketTierLifecycleService::loadOrderedTicketsForEvent()` only reads **`field_ticket_types`**, so the ticket card list and `findEventTicket()` saw **no tiers** when the node field was empty, even though tiers existed.

## Root cause (exact)

**Ticket save creates/updates `mel_ticket_type` entities and Commerce variations, but Event Studio does not persist/read the ticket as canonical on the event node when `field_ticket_types` was never written or was cleared**, because:

1. **`loadOrderedTicketsForEvent()`** ([`TicketTierLifecycleService.php`](../../web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php)) returns tiers **only** from `node.field_ticket_types`.
2. Ticket entities can exist with **`event` → event nid** without the matching **`field_ticket_types` → ticket ids** on the node (historical paths or failed bidirectional sync).

Related UX fix (Task 5C): [`mel-event-studio.js`](../../web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js) preserves hidden tier JSON and counts AJAX cards; that does not replace **node field** persistence.

## Persistence rule (canonical)

For a given event node:

- **`field_ticket_types`** must reference every `mel_ticket_type` whose **`event`** field points at that node (ordered: preserve existing field order for IDs still valid; append missing inverse IDs sorted by ticket id).
- **[`TicketTierLifecycleService::reconcileEventTicketReferences()`](../../web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php)** implements this merge idempotently (no-op when already aligned).

## Files changed

| File | Change |
|------|--------|
| [`web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`](../../web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php) | Add `reconcileEventTicketReferences()`; notice/error logging; `syncPaidTiers()` after successful save |
| [`web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php`](../../web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php) | Inject `EntityTypeManagerInterface`; call reconcile + reload event at start of `build()`; call reconcile after `handleAction` |
| [`web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml`](../../web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml) | Pass `@entity_type.manager` into `myeventlane_vendor.ticket_builder` |
| [`web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php`](../../web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php) | Call reconcile + reload node at start of `onEventStudioSaveComplete()` (non-draft) |

## Verification (CLI)

```bash
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php
php -l web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php
php -l web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php
```

Example reconcile smoke test (event 1567):

```bash
ddev drush php-eval '$s=\Drupal::service("myeventlane_event.ticket_tier_lifecycle"); $n=\Drupal::entityTypeManager()->getStorage("node")->load(1567); $s->reconcileEventTicketReferences($n);'
```

Expected log: `Reconciled field_ticket_types on event 1567: 88,89.`  
Expected DB: `field_ticket_types` populated with those target IDs.

Watchdog spot-check:

```bash
ddev drush ws --count=80 | grep -Ei "event_studio|ticket|variation|product|publish|readiness|error|exception|orphan|Reconciled" || true
```

## Browser verification (manual)

On `/vendor/events/1567/edit` (and similar):

1. Tickets / RSVP shows ticket cards matching inverse tier count after load.
2. Saving tickets does not clear `field_ticket_types` when tiers remain on the event.
3. Publish readiness / insights (with Task 5C JS) align when node field is populated.
4. Paid publish still enforced by Stripe charge-ready gate (unchanged).
5. No duplicate products/variations from reload alone (reconcile does not create tiers).

## Follow-ups (optional)

- Investigate **`myeventlane_commerce` “No mel_ticket_type maps variation …”** if it still appears after refs are canonical — may indicate orphaned variations from tier churn, separate from this field sync.
- Confirm **`appendTicketToEvent`** on new creates always persists (if gaps remain, reconcile covers legacy data).

## Ready to commit?

Yes, after browser confirmation on a affected event (e.g. 1567) and green CLI checks above.
