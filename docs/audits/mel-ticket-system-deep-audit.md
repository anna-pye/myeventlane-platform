# MEL Ticket System Deep Audit

Date: 2026-05-02

Branch: `feature/mel-event-ticket-creation-audit`

Source HEAD: `6c973869 feat(booking): add BookingFlowResolver as canonical booking decision layer`

Scope: audit only. No code changes.

## Executive Summary

The ticket system has a clear intended architecture:

- `mel_ticket_type` is the ticket tier source of truth.
- `node.event.field_ticket_types` is the ordered event-to-tier attachment.
- `TicketTierLifecycleService` is intended to be the canonical mutation pipeline for create, attach, update, detach, archive, reorder, and paid sync.
- `TicketTypeManager` projects published paid tiers into one Commerce `ticket` product with one `ticket_variation` per paid tier.
- `TicketSelectionForm`, `TicketAvailabilityService`, and Commerce subscribers enforce purchasability at add-to-cart and order placement.

The rebuild should keep that shape, but the current implementation has drift and production risks:

- Creation and editing are split across vendor builder, Event Studio, legacy wizard, standalone entity forms, help actions, hooks, and sync services.
- Deletion is not consistently modeled: vendor UI has both "remove" and "archive", while the entity delete route hard-deletes `mel_ticket_type` without a Commerce orphan sync hook.
- Commerce sync is mostly accurate for title, price, event, and product references, but it has no transactional boundary around ticket creation plus variation creation.
- Sold-count and capacity logic only counts completed Commerce orders; that is consistent for final sales, but cart holds are not reserved, so concurrent checkouts rely on late per-event locks.
- Several paths still use procedural `\Drupal::service()` and broad hooks for ticket sync, which duplicates service responsibilities and makes drift easier.

Overall risk rating before rebuild: **High** for ticket editing/deletion, Commerce projection drift, and oversell edge cases; **Medium** for create flow because the main vendor builder already uses the lifecycle service.

## Canonical Model

### `mel_ticket_type`

Defined in `web/modules/custom/mel_ticket/src/Entity/TicketType.php`.

Important base fields:

- `title`: public ticket name.
- `ticket_kind`: `rsvp`, `paid`, or `external`.
- `price`: Commerce Price field used only for paid tickets.
- `capacity`: tier-level cap, required for paid and RSVP by entity presave business rules.
- `vendor_id`: owning user.
- `event`: inverse reference back to the event node.
- `commerce_variation`: paid tier projection into `commerce_product_variation`.
- `visibility_mode`, `waitlist_*`, `group_sale_*`: purchase access and quantity rules.
- `status`: published flag.

`preSave()` normalizes mutually exclusive fields:

- External clears capacity, RSVP limit, and price.
- RSVP clears price and external URL.
- Paid clears external URL and forces `is_reusable` to false.

`assertBusinessRules()` throws `EntityStorageException` for invalid combinations. That gives a fail-loud invariant at entity save time, but it also means UI paths must catch and present storage exceptions cleanly.

### Event Relationship

The system uses two links:

- Forward ordered relation: `node.event.field_ticket_types`.
- Inverse relation: `mel_ticket_type.event`.

`TicketTierLifecycleService::reconcileEventTicketReferences()` exists because some historical paths wrote only the inverse `event` field and left `field_ticket_types` empty. This is a major consistency smell: the rebuild should make one write path own both sides.

## Where Tickets Are Created

### Canonical vendor builder

Primary creation path:

- `web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php`
  - `handleAction()` dispatches `ticket_create`.
  - `createTicket()` reads the new card values.
  - `normalisePayload()` builds `mel_ticket_type` values.
  - `TicketTierLifecycleService::createAttachAndSync()` creates, attaches, saves, and syncs.

This path supports paid, RSVP, and external tickets. It validates:

- Required title.
- Paid price greater than zero.
- Paid currency format.
- Capacity at least 1 for paid and RSVP.
- External URL as `https://`.

### Duplicate ticket path

`EventTicketsBuilder::duplicateTicket()` copies many fields from an existing event ticket and calls `createAttachAndSync()`.

Risk: duplication copies paid price, capacity, visibility, waitlist, and group fields, then creates a new Commerce variation. That is mostly correct, but there is no explicit UI warning that a paid copy creates a separate sellable tier with separate capacity and sold count.

### Event Studio path

`web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php`

- `onEventStudioSaveComplete()` calls `reconcileEventTicketReferences()`, then `applyStudioTierRows()`.
- New rows call `TicketTierLifecycleService::persistNewTicketType($values)`.
- The event reference merge happens later by setting `field_ticket_types`.
- Paid sync happens in `syncCommerceAndPublishCatalogSignal()`.

Risk: Event Studio creates ticket entities and only later merges the node reference. This is better than direct storage creation, but still less atomic than `createAttachAndSync()`.

### Help Centre default ticket path

`web/modules/custom/myeventlane_help_centre/src/Controller/HelpActionController.php`

- `doAddDefaultTicket()` creates a default RSVP "General admission" tier using `createAttachAndSync()`.

This is a valid lifecycle path, gated by `create mel_ticket_type entities` and `assign mel_ticket_type entities`.

### Standalone entity add form

`web/modules/custom/mel_ticket/src/Form/TicketTypeForm.php`

- Uses Drupal `ContentEntityForm`.
- `save()` calls `parent::save()` directly.
- Sync is not done by the form itself; it relies on `myeventlane_event_entity_insert()` and `myeventlane_event_entity_update()` in `myeventlane_event.module`.

Risk: this bypasses the lifecycle service despite the lifecycle service comment saying forms and controllers must not call storage creation directly. It is partly compensated by hooks, but the form does not attach ticket IDs to events or reconcile inverse refs.

## Where Tickets Are Saved

### New ticket save

`TicketTierLifecycleService::persistNewTicketType()`:

- Normalizes paid currency.
- Resolves event from explicit argument or `event` value.
- Checks paid currency consistency against existing event tickets.
- Calls `mel_ticket_type` storage `create($values)`.
- Saves the ticket.

`TicketTierLifecycleService::createAttachAndSync()`:

- Calls `persistNewTicketType()`.
- Calls `appendTicketToEvent($event, $ticketId, TRUE)`.
- Event save triggers `syncPaidTiers()`.

### Existing ticket save

Main vendor path:

- `EventTicketsBuilder::saveInlineEdit()`
- `applyPayloadToExistingTicket()`
- `TicketTierLifecycleService::updateTicketType()`

`updateTicketType()`:

- Normalizes existing paid currency.
- Validates paid currency against the event.
- Saves the ticket.
- Syncs paid tiers only if `ticketBelongsToEvent()` returns true.

Event Studio path:

- `MelTicketTypeManager::applyRowToTicket()`
- `TicketTierLifecycleService::updateTicketType()`

Standalone entity form path:

- `TicketTypeForm::save()` uses `parent::save()`.
- `myeventlane_event_entity_update()` tries to sync any events that reference this ticket in `field_ticket_types`.

Risk: update coverage differs by path. The lifecycle path checks currency and syncs directly; the standalone form relies on a hook that only queries `field_ticket_types`, not the inverse `mel_ticket_type.event` field.

## Where Commerce Sync Happens

### Primary sync service

`web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`

`syncTicketTypesToVariations(NodeInterface $event)`:

- Requires an event node.
- Only runs for `field_event_type` `paid` or `both`.
- Loads all event ticket types from both `field_ticket_types` and inverse `mel_ticket_type.event`.
- Blocks sync if published paid tickets use mixed currencies.
- Gets or creates the event Commerce product.
- Loops published paid tickets.
- Calls `syncTicketTypeToVariation()` for each.
- Unpublishes orphaned product variations not tied to active published paid tickets.
- Syncs product title to event title.
- Deduplicates product variation IDs.
- Invalidates product and event cache tags.

`syncTicketTypeToVariation()`:

- Uses `commerce_variation` if present and owned by the same product.
- Updates existing variation title, price, and `field_event`.
- Otherwise creates a `ticket_variation`, saves it, stores it back on the ticket, and attaches it to the product.

### Lifecycle sync callers

`TicketTierLifecycleService` calls sync from:

- `appendTicketToEvent()`
- `detachTicketFromEvent()`
- `reorderTicketsOnEvent()`
- `archiveTicketOnEvent()`
- `updateTicketType()`
- `reconcileEventTicketReferences()`

### Other sync callers

- `EventProductManager::doSyncProducts()`
- `EventWizardTicketsForm::submitForm()`
- `MelTicketTypeManager::syncCommerceAndPublishCatalogSignal()`
- `myeventlane_event_entity_insert()`
- `myeventlane_event_entity_update()`

Risk: there are too many sync entry points. Most call the same manager, which is good, but broad hooks and explicit calls can cause repeated syncs, stale-event syncs, and hard-to-reason sequencing.

## Purchase-Time Flow

### Ticket selection

`web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`

- Builds public selection from `TicketAvailabilityService::filterPurchasableVariations()`.
- Validates selected quantities against:
  - event-level capacity,
  - current cart quantities for the event,
  - variation/tier mapping,
  - tier capacity,
  - access code/group/waitlist access,
  - sale window,
  - published status,
  - price drift,
  - group quantity rules.
- Repeats the key checks in `submitForm()` before adding to cart.
- Adds selected variations to the cart and sets `field_target_event` on the order item.

### Add-to-cart and placement enforcement

`TicketAvailabilityCommerceSubscriber`:

- Revalidates availability on `CartEvents::CART_ENTITY_ADD`.
- Adds an order-level lock during `commerce_order.place.pre_transition`.

`TicketCapacityOrderSubscriber`:

- Acquires per-event locks on `commerce_order.place.pre_transition`.
- Revalidates each event/variation line while the per-event lock is held.
- Releases locks at kernel terminate.

This is the strongest part of the system. The remaining risk is that cart add does not reserve stock; only final placement is serialized.

## Stock, Quantity, and Sold Count Logic

### Tier capacity

Tier capacity lives on `mel_ticket_type.capacity`.

For paid tickets, `TicketAvailabilityService::assertTierCapacity()` calculates:

- `pool = capacity - completed_sold - active_waitlist_offer_reserved`.
- Blocks requested line quantity if it exceeds pool.

For waitlist offer buyers, `buyerLimitForActiveOffer()` allows purchase up to the reserved offer quantity and remaining capacity.

### Event capacity

Event-level capacity is enforced by `myeventlane_capacity`:

- `field_event_capacity_total` first.
- Fallback to `field_capacity`.
- Counts RSVP and paid quantities depending on `field_event_type`.

Risk: `EventCapacityService` catches exceptions in RSVP/paid count paths and returns zero or continues. That is fail-open capacity behavior if entity/table loading breaks.

### Commerce variation stock

No current sync writes a Commerce variation stock field. The config search did not show active `field_stock` config for `ticket_variation`; `myeventlane_metrics` still has legacy stock-display logic that checks `field_stock` if it exists.

Architectural conclusion: `mel_ticket_type.capacity` is the real stock source. Rebuild should not introduce Commerce stock as a second source unless it fully replaces the custom capacity services.

### Sold count

`TicketVariationSoldService` is the paid sold-count source:

- Queries `commerce_order_item` by `field_target_event` and `purchased_entity`.
- Loads each order item.
- Loads the parent order.
- Counts only orders whose state is `completed`.

`TicketTierAnalyticsService` and `TicketStatusEvaluator` use the same completed-order basis.

Accuracy tradeoff:

- Correct for completed-sale reporting and final sell-through.
- Does not count carts, pending payment, failed payment, or abandoned checkout.
- Refund/cancel effects depend on whether the order remains `completed`; this audit did not find a refund-adjusted sold-count path in the ticket capacity services.

Performance risk: sold counts are entity-query plus per-item order loads. `TicketAvailabilityService` batches variation IDs, but `TicketVariationSoldService::countCompletedSoldForVariations()` still loads matching order items and then loads each parent order one by one.

## Editing Existing Tickets

Main vendor inline edit supports:

- Title.
- Capacity for paid/RSVP.
- Paid price using existing currency or event default.
- Published status.
- Visibility.
- Short description.
- Waitlist settings.
- Group-sale settings.

Gaps:

- Ticket kind is effectively not editable in inline edit. That is probably good, but should be explicit in rebuild UX.
- Price currency cannot be changed through vendor inline edit after creation.
- Sale start/end and RSVP limit are supported in lifecycle payloads but are not obviously exposed in the current inline edit path.
- `applyPayloadToExistingTicket()` uses `isset()` for some nullable fields, so payload entries intentionally set to `NULL` are skipped for `capacity`, `price`, `external_url`, and `rsvp_limit`.
- UI catches storage exceptions and often shows generic "couldn't save" messaging, which protects users from raw errors but can obscure the exact validation failure.

## Deletion and Archival

There are three different behaviors:

### Remove from event

`EventTicketsBuilder::removeTicket()` calls `TicketTierLifecycleService::detachTicketFromEvent()`.

Result:

- Removes the ticket ID from `field_ticket_types`.
- Saves the event.
- Syncs paid tiers.
- Does not unpublish or delete the `mel_ticket_type`.

Risk: this creates an inverse-reference inconsistency if the ticket's `event` field still points to the event. Later `reconcileEventTicketReferences()` and `loadAllEventTicketTypes()` can re-discover the ticket.

### Archive

`EventTicketsBuilder::archiveTicket()` calls `TicketTierLifecycleService::archiveTicketOnEvent()`.

Result:

- Detaches from event.
- Sets ticket `status` to 0.
- Saves ticket.
- Syncs paid tiers.

This is the safer vendor deletion model because paid Commerce variations become orphaned and are unpublished during sync.

Risk: because archive first detaches and then unpublishes, there is a short two-save sequence where sync can run once while the ticket may still have an inverse `event` reference.

### Entity delete form

`mel_ticket_type` declares Drupal core `ContentEntityDeleteForm` at `/ticket-type/{mel_ticket_type}/delete`.

Risk:

- No `hook_entity_delete()` for `mel_ticket_type` was found.
- Hard-delete can bypass `archiveTicketOnEvent()`.
- Commerce variation orphan cleanup only runs when an event sync runs.
- If the deleted ticket was only discoverable by inverse `event`, the existing insert/update hook pattern will not help deletion.

Rebuild recommendation: model vendor "delete" as archive or a lifecycle hard-delete method with explicit event sync and Commerce orphan behavior. Avoid exposing raw entity delete for production vendor flows.

## Inconsistencies and Broken Flows

### 1. Dual source relationship drift

The system uses both `field_ticket_types` and `mel_ticket_type.event`. Multiple services have reconciliation logic because some paths historically wrote only one side.

Impact:

- Removed tickets can reappear.
- Standalone form updates may fail to sync if the event only exists in the inverse field.
- Public availability can fail if code reads only `field_ticket_types`.

### 2. Remove versus archive UX is ambiguous

Vendor builder exposes both remove and archive behaviors.

Impact:

- "Remove from this event" does not mean deleted, unpublished, or unsellable in all discovery paths.
- "Archive" is safer but not necessarily the obvious user choice.

### 3. Standalone entity form bypasses lifecycle service

`TicketTypeForm` saves directly via `ContentEntityForm`, and hooks attempt to sync after insert/update.

Impact:

- No direct lifecycle attach/reconcile.
- Currency validation is duplicated in the form.
- Sync behavior depends on `field_ticket_types` query results.

### 4. Procedural hooks duplicate service responsibilities

`myeventlane_event_entity_insert()` and `myeventlane_event_entity_update()` use `\Drupal::entityTypeManager()` and `\Drupal::service()` to sync tickets.

Impact:

- Violates the current Drupal 11 service-boundary expectation for business logic.
- Adds a second sync mechanism outside lifecycle orchestration.

### 5. Event Studio create flow is not atomic

Event Studio creates ticket rows, then later merges event references, then syncs Commerce.

Impact:

- Partial failures can leave ticket entities saved but not attached.
- Existing reconciliation compensates after the fact.

### 6. Commerce sync is not transactional

`syncTicketTypeToVariation()` can:

- Create a variation.
- Save the ticket with `commerce_variation`.
- Save the product with variation references.

Impact:

- If a save fails mid-sequence, the system can leave a variation without a ticket pointer or a ticket pointer without product normalization.
- Product variation dedupe helps after the fact but does not make the sequence atomic.

### 7. Orphan variation handling is publish-state-only

`removeOrphanedVariations()` unpublishes product variations that are no longer active paid tier projections.

Impact:

- Old variations remain attached to the product unless product reference cleanup happens elsewhere.
- That preserves order history, which is good, but rebuild UX should clearly distinguish inactive historical variations from sellable tiers.

### 8. Sold counts ignore refunds and pending payment state

Sold count counts completed orders only.

Impact:

- Pending payment carts do not reserve capacity.
- Refunded tickets may continue counting as sold if the order state remains completed.
- Oversell protection relies on final placement locks, not earlier cart availability.

### 9. Event capacity can fail open

`EventCapacityService` catches exceptions while counting RSVP and paid sales and can return lower counts.

Impact:

- A data/query failure can make remaining capacity look larger than reality.
- This conflicts with the fail-loud requirement for capacity-critical paths.

### 10. Public selection cache may briefly show stale tiers

`TicketAvailabilityService::filterPurchasableVariations()` caches purchasable variation IDs for 90 seconds with event/product tags.

Impact:

- Availability display may briefly lag if cache invalidation misses a ticket/capacity transition.
- Server-side add-to-cart and placement validation still recheck, so this is mainly UX drift, not a purchase bypass.

## Multiple Ticket Creation

Current support:

- Multiple paid tiers per event are supported.
- Each published paid tier gets a separate Commerce variation.
- Currency consistency is enforced per event for paid tiers.
- Duplicate ticket creates another tier and another variation.

Risks:

- SKU generation uses event ID, label slug, and `time()`. Rapid duplicate creation in the same second with the same label can collide if Commerce enforces unique SKUs at save time.
- No transaction wraps multiple paid tier creation and Commerce projection.
- UX can make "Save & sync" create a pending new ticket if the new card has a title, which is helpful but implicit.

## Variation Sync Accuracy

Accurate today:

- Paid tier title syncs to variation title.
- Paid tier price syncs to variation price.
- Variation `field_event` syncs to event.
- Event `field_product_target` is created or corrected to a product that owns the event.
- Product title syncs to event title.
- Mixed published paid currencies block sync.
- Public availability blocks purchases if tier and variation prices drift.

Not synced:

- Tier capacity to Commerce stock.
- Tier visibility/waitlist/group rules to variation fields.
- Tier short description to `field_ticket_description`, despite a variation field existing in config.
- Sale start/end to Commerce availability fields.

Architectural conclusion: Commerce variations are checkout purchasables, not the complete ticket definition. Rebuild should keep `mel_ticket_type` as the policy source or intentionally move all policy fields into Commerce, but not split them further.

## Race Conditions

Confirmed mitigations:

- Add-to-cart revalidation through `TicketAvailabilityCommerceSubscriber`.
- Final order placement per-event lock through `TicketCapacityOrderSubscriber`.
- Order-level duplicate-submit lock through `TicketAvailabilityCommerceSubscriber`.

Residual risks:

- Cart add has no stock reservation, so two buyers can both put the last ticket in cart.
- Final placement is the first serialized capacity gate.
- Waitlist held quantity is considered, but normal cart holds are not.
- Variation creation/product attachment has no lock or transaction.
- SKU generation can collide under same-second duplicate creation.

## Missing Validation

Important validation exists:

- Entity save enforces kind-specific invariants.
- Vendor builder validates title, capacity, paid price, currency, and external URL.
- Purchase path validates product ownership, tier mapping, tier status, access rules, sale window, price drift, group rules, tier capacity, and event capacity.

Gaps:

- Standalone entity delete has no lifecycle sync validation.
- Standalone entity form can save tickets without ensuring bidirectional event attachment.
- Event Studio validation permits capacity "zero or greater" in one branch, while entity presave requires at least 1 for RSVP/paid; normalization later forces at least 1.
- Existing-ticket inline edit cannot change currency or kind, but that constraint is implicit rather than clearly modeled.
- Refund-aware sold count is not part of tier capacity.
- Capacity count failures are swallowed in `EventCapacityService`.

## Poor UX Patterns

- Multiple ticket-management surfaces exist: Event Studio, legacy wizard tickets step, standalone vendor tickets workspace, standalone entity forms, and help actions.
- "Remove" and "Archive" are not clearly distinct from a vendor mental model.
- "Save & sync" can create a ticket if a new form has a title, which hides a persistence side effect behind a sync label.
- Generic save failure messages hide exact validation issues in some inline edit paths.
- Public ticket selection may show stale availability briefly, then fail at add-to-cart or checkout.

## Rebuild Direction

Do not add a new parallel ticket model. The safest rebuild direction is:

1. Keep `mel_ticket_type` as the canonical tier definition.
2. Make one lifecycle service own every mutation, including hard delete or archive.
3. Pick one event relationship write contract and enforce it everywhere.
4. Treat Commerce variations as purchasable projections only.
5. Add explicit sync/reconcile tooling for historical data, but remove normal runtime dependence on reconciliation.
6. Preserve final order placement locks; decide explicitly whether cart reservations are needed.
7. Make sold count semantics explicit: completed sales only, or completed minus refunded/cancelled tickets.

## Files Audited

- `web/modules/custom/mel_ticket/src/Entity/TicketType.php`
- `web/modules/custom/mel_ticket/src/Form/TicketTypeForm.php`
- `web/modules/custom/mel_ticket/src/Access/TicketTypeAccessControlHandler.php`
- `web/modules/custom/mel_ticket/mel_ticket.permissions.yml`
- `web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`
- `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php`
- `web/modules/custom/myeventlane_event/src/Form/EventWizardTicketsForm.php`
- `web/modules/custom/myeventlane_event/myeventlane_event.module`
- `web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php`
- `web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Form/EventTicketsWorkspaceForm.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/ManageEventTicketsController.php`
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketCapacityService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketVariationSoldService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketStatusEvaluator.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketStatusService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketTierAnalyticsService.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketAvailabilityCommerceSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketCapacityOrderSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketWaitlistOrderSubscriber.php`
- `web/modules/custom/myeventlane_capacity/src/Service/EventCapacityService.php`
- `web/modules/custom/myeventlane_capacity/src/Service/CapacityOrderInspector.php`
- `web/modules/custom/myeventlane_capacity/myeventlane_capacity.module`
- `web/modules/custom/myeventlane_help_centre/src/Controller/HelpActionController.php`

## Verification Performed

- `git status --short`
- `git branch --show-current && git log -1 --oneline`
- Static code audit with file reads and repository search.

Runtime Drupal/Commerce checks were not run for this audit. No code, config, database, or cache changes were made.
