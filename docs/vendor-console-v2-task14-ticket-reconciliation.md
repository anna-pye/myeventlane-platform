# MEL Vendor Console v2 — TASK 14 ticket reconciliation

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Diagnostics and **safe** repair tooling for ticket mapping / paid display data gaps. **No** checkout enforcement changes, **no** Stripe/RSVP/Event Studio rebuild, **no** weakening `TicketAvailabilityService`.

---

## 1. Problem statement

Production Watchdog noise and blocked purchases trace to three recurring patterns:

1. **`No mel_ticket_type maps variation {variation_id} for event {event_id}; blocking purchase.`**  
   Emitted by `TicketAvailabilityService::assertPaidVariationLineConstraints()` when `resolveTierForVariation()` returns `NULL` — purchase is blocked (TASK 13 retained this).

2. **`Variation {variation_id} excluded from selection for event {event_id}: …`**  
   Usually capacity/waitlist tier rules (`notice`); distinct from mapping but appears in the same flows.

3. **`Paid display pricing could not resolve any paid ticket prices for event {event_id}.`**  
   Emitted by `BookingFlowResolver::getDisplayPricing()` when `TicketTypeManager::loadPublishedPaidTicketPrices()` returns empty for `MODE_PAID`. Vendor UI intentionally avoids calling `getDisplayPricing()` on listing/workspace loads (TASK 13) but public/event pages still can.

**TASK 14 goal:** Give vendors/admins **audit + optional repair** (Drush-first) so broken mappings can be found and fixed **without** bypassing validation or mutating Commerce orders.

---

## 2. Watchdog examples (local test IDs only)

Do **not** hardcode these in runtime logic; use for manual Drush verification:

| Symptom | Example IDs (local) |
| ------- | ------------------- |
| Mapping / blocking purchase | Event `1592`, variations `4172`, `4171`; event `1584`, variations `4135`, `4134`, `4130` |
| Paid display pricing warning | Events `1094`, `1378` |

Suggested commands:

```bash
ddev drush mel:tickets:audit --event=1592
ddev drush mel:tickets:repair --event=1592
ddev drush mel:tickets:repair --event=1592 --apply   # only after reviewing dry-run
```

---

## 3. Source services inspected

| Area | File / symbol |
| ---- | ---------------- |
| Purchase mapping enforcement | `TicketAvailabilityService::resolveTierForVariation()`, `assertPaidVariationLineConstraints()` |
| Paid display pricing | `BookingFlowResolver::getDisplayPricing()`, `TicketTypeManager::loadPublishedPaidTicketPrices()` |
| Ticket ↔ event references | `TicketTypeManager::loadEventTicketTypes()`, `loadAllEventTicketTypes()` |
| Canonical inverse reconciliation | `TicketTierLifecycleService::reconcileEventTicketReferences()` |
| Commerce projection | `TicketTierLifecycleService::syncPaidTiers()` → `TicketTypeManager::syncTicketTypesToVariations()` (**not** invoked by TASK 14 repair — see §6) |
| Vendor presentation (TASK 13) | `VendorEventPresentationAlertsBuilder` |

---

## 4. Canonical mapping model (verified)

1. **Event → Commerce product**  
   Field **`field_product_target`** on the event node (`event` bundle) references the ticket **`commerce_product`**.

2. **Event → ticket types (`mel_ticket_type`)**  
   Field **`field_ticket_types`** (multi-valued entity reference) lists ticket types for the event.

3. **Inverse link**  
   Each **`mel_ticket_type`** has an **`event`** field referencing the event node.  
   `TicketTypeManager::loadAllEventTicketTypes()` merges **field order + inverse query** for display and price loading.

4. **Ticket type → Commerce variation**  
   Field **`commerce_variation`** on `mel_ticket_type` references the purchasable **`ticket_variation`**.

5. **Checkout mapping rule (strict)**  
   `TicketAvailabilityService::resolveTierForVariation()` iterates **only** `field_ticket_types` referenced entities — **not** inverse-only rows.  
   Therefore: ticket types that exist only via the inverse `event` reference **do not map** for purchase until they appear on **`field_ticket_types`**.

6. **Published / archived**  
   Archived ticket types are skipped in `resolveTierForVariation()`. Publish flags on tier and variation are enforced in `assertPaidVariationLineConstraints()`.

7. **Paid display prices**  
   `loadPublishedPaidTicketPrices()` uses `loadAllEventTicketTypes()` and returns prices for **published**, non-archived **paid** tiers with a resolvable `toPriceValue()`.  
   Empty results under **`BookingFlowResolver::MODE_PAID`** trigger the Watchdog warning inside `getDisplayPricing()` (vendor paths avoid calling it where noted in TASK 13).

8. **Who creates/syncs variations**  
   `TicketTypeManager::syncTicketTypesToVariations()` / `TicketTierLifecycleService::syncPaidTiers()` — used by lifecycle/Event Studio flows; **`syncTicketTypesToVariations()` may unpublish “orphan” variations** not tied to active published paid tiers. TASK 14 repair **does not** call this stack to avoid unintended variation publication changes during CLI repair.

---

## 5. Safe repair criteria

**Allowed automated repair (TASK 14):**

- **`TicketTierLifecycleService::reconcileEventTicketReferences($event)`** when audit proves **`field_ticket_types` is missing one or more non-archived ticket types** that already reference this event via **`event`** and are safe to merge using the service’s deterministic merge rule (preserves valid ordering, appends missing inverse IDs).

This matches historical data drift documented in `TicketTierLifecycleService::reconcileEventTicketReferences()` (inverse saves without updating the node field).

**Important implementation detail:** `reconcileEventTicketReferences()` ends by calling **`syncPaidTiers()`**, which delegates to **`TicketTypeManager::syncTicketTypesToVariations()`**. That sync may **unpublish** Commerce variations it considers orphaned relative to active published paid tiers (it does not delete rows). Operators must treat **`mel:tickets:repair --apply`** as invoking this existing lifecycle behaviour—not a pure node-field tweak.

**Repair is only applied when:**

- `--event={nid}` is provided (repair is single-event scoped).
- Dry-run output lists the intended change.
- **`--apply`** is passed (otherwise default dry-run).
- No **`ambiguous_variation_mapping`** issue exists for the event (multiple `mel_ticket_type` rows pointing at the same variation within the reconciled event ticket set).
- No **`orphan_variation_not_repairable`** issue exists for the event (published `ticket_variation` on the event product still lacks any inverse `mel_ticket_type` row for this event — reconcile/sync could otherwise behave unpredictably relative to orphan SKUs).

**Logging:** Channel **`myeventlane_ticket_reconciliation`** logs audit/repair/skip/exception summaries — not per-ticket spam during HTTP requests.

---

## 6. Unsafe / ambiguous cases (report only)

Do **not** auto-repair when:

- More than one **`mel_ticket_type`** for the same **`commerce_variation`** among tickets belonging to the event (ambiguous).
- Published product variation has **no** ticket type with **`commerce_variation` = that variation** (“orphan” variation — needs product/ticket editor or lifecycle ops, not silent CLI mapping).
- **Missing product** (`field_product_target` empty) for paid-capable event types.
- **Paid price gaps** (`paid_without_prices`): missing tier prices, unpublished tiers, wrong kind, mixed currency blocks — require editorial fixes or existing studio flows; **no fake prices**.
- **Price mismatch** tier vs variation (`assertPriceMatchesTier`) — not silently “fixed” in TASK 14 (no price inference).
- **`syncTicketTypesToVariations()` / `syncPaidTiers()`** — excluded from default repair because **`removeOrphanedVariations()` unpublishes** variations not in the active sync set; that exceeds “mapping-only” repair scope for TASK 14.

---

## 7. Proposed command/service design

| Component | Location |
| --------- | -------- |
| Service | `Drupal\myeventlane_vendor\Service\EventTicketReconciliationService` (`myeventlane_vendor.event_ticket_reconciliation`) |
| Drush | `Drupal\myeventlane_vendor\Commands\EventTicketReconciliationCommands` |
| Registration | `web/modules/custom/myeventlane_vendor/drush.services.yml` |

**Commands:**

- `drush mel:tickets:audit [--event=N] [--limit=50] [--format=table|json]`
- `drush mel:tickets:repair --event=N [--apply] [--format=table|json]` — **dry-run default**; **`--apply`** required to persist **`reconcileEventTicketReferences()`**.

---

## 8. Verification plan

1. `php -l` on new/changed PHP.
2. `composer validate`
3. `ddev drush cr`
4. `ddev drush php:eval` load service `myeventlane_vendor.event_ticket_reconciliation`.
5. `ddev drush mel:tickets:audit --limit=10` and spot-check known IDs if present.
6. `ddev drush mel:tickets:repair --event=<nid>` (dry-run), then **`--apply` only** after human review.
7. `ddev drush ws --count=50` — confirm no reconciliation flood on audit alone.
8. `npm run mel:lint` && `npm run mel:build` per workspace rules.
9. Manual browser smoke (optional): `/vendor/events`, workspace — TASK 13 chips/alerts still visible; broken tickets remain unpurchasable until data fixed.

---

## 9. Residual risks (TASK 15+)

- Orphan Commerce variations still require intentional product/ticket handling (no TASK 14 auto-delete/unpublish).
- Ambiguous duplicate **`commerce_variation`** references need manual data cleanup.
- Paid pricing gaps may reflect business choices (unpublished tiers) rather than field drift — diagnostics must stay explanatory, not mutating.
