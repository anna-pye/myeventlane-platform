# /event/{nid}/book ticket availability (TASK 5G)

## Plan (concise)

- **Message source:** `TicketSelectionForm` → `filterPurchasableVariations()` empty and no waitlist rows.
- **Chain:** `BookController::buildPaidForm` → `TicketSelectionForm` with `field_product_target` product → `$ticketAvailability->filterPurchasableVariations($node, $product)`.
- **Rules:** Each **published** Commerce variation on the product must pass `TicketAvailabilityService::assertPaidVariationLineConstraints`, including **`resolveTierForVariation()`** (mel_ticket_type **`commerce_variation`** → variation id), tier access, status, price match, capacity.

## Event 1567 — state before this fix (data)

- **Product 90** `variation_ids` was **`["4121","4122","4122"]`** (duplicate **4122**).
- **Tickets 88 / 89** had **`commerce_variation`** → **4121** / **4122**, prices aligned, `visibility_mode` public, `status` on.

## Root cause (one sentence)

**`/event/1567/book` showed no tickets because the ticket product’s variation list contained a duplicate variation id, so purchasable-variation resolution and short-lived runtime cache could disagree with the real sellable set; we now dedupe on sync, skip duplicate iterations when filtering, tag/invalidate cache on sync, and correct cache `set()` tag usage.**

(If mappings were missing, the same empty state appears because **`resolveTierForVariation`** fails when **`commerce_variation`** is empty — that was not the case once sync completed.)

## Availability rule (exact)

For each **published** variation returned by `$product->getVariations()` (deduped by variation id):

1. Variation belongs to the event’s **`field_product_target`** product.
2. Some **`mel_ticket_type`** on **`event.field_ticket_types`** references that variation via **`commerce_variation`**.
3. Tier published, access rules allow public booking, **`TicketStatusEvaluator`** = active (not upcoming/ended/sold out/inactive), price matches tier, capacity allows at least 1.

## Files changed

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php` | Dedupe ids when appending a new variation; **`normalizeProductVariationIds()`** after sync; **`Cache::invalidateTags`** for product + event node. |
| `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php` | Dedupe cached id list + variation loop; attach **cache tags** `commerce_product:{pid}` and `node:{nid}` on purchasable-variation cache write (fixes incorrect nested `tags` array on first pass). |

## Commands run

```bash
git branch --show-current
git status --short
git log -10 --oneline
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php
php -l web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php
```

**Repair product 90 + prove dedupe (optional):**

```bash
ddev drush php-eval '\Drupal::service("myeventlane_event.ticket_type_manager")->syncTicketTypesToVariations(\Drupal::entityTypeManager()->getStorage("node")->load(1567));'
```

Expect log: `Deduped Commerce variation references on product 90 (was ["4121","4122","4122"], now [4121,4122]).`

## Browser verification

1. Open `/event/1567/book`.
2. Confirm ticket rows, labels, prices, quantity widgets, **Add to cart**.
3. Confirm no duplicate rows for the same tier.
4. Re-save event in Studio if needed to trigger sync once.

## Follow-ups

- If tickets still empty after dedupe: check **`TicketStatusEvaluator`** (sale window, sold counts vs capacity), **`TicketTierAccessService`** (hidden / access code / group-only), and **`commerce_variation`** empty on tier entities.
- **`BookController::melEventHasRsvp`** still references `mel_debug` logger — unrelated to paid book page.

## Ready to commit

After browser check on `/event/1567/book` and `composer validate` / `drush cr` / `php -l` clean — yes.
