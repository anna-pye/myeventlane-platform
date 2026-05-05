# MEL Vendor Console v2 — TASK 16 orphan variation cleanup

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Safe **inspect + explicit dry-run / `--apply` unpublish-only** workflow for **orphan** Commerce `ticket_variation` rows on an event’s ticket product. **No** checkout enforcement changes, **no** deletes, **no** silent repair during HTTP.

---

## 1. Problem statement

TASK 14/15 **`mel:tickets:repair`** aborts with **`orphan_variation_not_repairable`** when a **published** `ticket_variation` exists on the event’s **`field_product_target`** product but **no** `mel_ticket_type` row for this event (inverse **`event`** link, non-archived) references that variation via **`commerce_variation`**. Reconcile cannot infer a mapping without guessing.

Operators need **explicit** tooling to:

1. **Inspect** orphan variations, **order usage** (counts only — no PII), and **safe-to-unpublish** signals.  
2. **Dry-run cleanup** by default; **unpublish only** safe orphans with **`--apply`**.  
3. Optionally **retry reconcile/repair** after cleanup **only** when TASK 14/15 abort reasons are clear — **never** auto-clean during repair.

---

## 2. Terminal proof TASK 16 was missing (pre-implementation)

Commands run **before** TASK 16 implementation (2026-05-05):

```bash
grep -R "mel:tickets:orphans\|mel:tickets:cleanup-orphans\|inspectOrphanVariations\|cleanupOrphanVariations" \
  -n web/modules/custom/myeventlane_vendor docs 2>/dev/null || true
# (no output — no matches)

ddev drush list | grep "mel:tickets" || true
```

**Observed `mel:tickets` commands:**

- `mel:tickets:audit`
- `mel:tickets:repair`

**Missing:**

- `mel:tickets:orphans`
- `mel:tickets:cleanup-orphans`

---

## 3. Known broken examples (local / illustrative IDs)

Do **not** hard-code these in runtime logic; use for manual Drush verification:

| Event nid | Product id | Notes (from TASK brief) |
| --------- | ----------- | ------------------------ |
| 1592 | 97 | Paid; `field_ticket_types` missing inverse types 102, 103; orphan variation 4173; repair blocked **`orphan_variation_not_repairable`** |
| 1584 | 95 | Paid; orphan variations 4130, 4134, 4135; repair blocked **`orphan_variation_not_repairable`** |
| 1094 | — | Paid display warning example |
| 1378 | — | Paid display warning example |

---

## 4. Orphan variation definition

Aligned with **`EventTicketReconciliationService::auditEvent()`** logic for **`orphan_variation_not_repairable`**:

A **`ticket_variation`** on the event’s **`field_product_target`** Commerce **ticket** product is an **orphan** when:

1. Variation **bundle** is `ticket_variation`.  
2. Variation is **published** (unpublished variations are out of scope for this TASK’s orphan list).  
3. **`TicketAvailabilityService::resolveTierForVariation()`** does **not** return a mapped tier from **`field_ticket_types`** (same as audit).  
4. Among all `mel_ticket_type` entities with **`commerce_variation`** = this variation, **none** have an inverse **`event`** reference to this event (non-archived), i.e. **`belongs`** count is **zero** — so there is **no** “safe inverse” row to merge onto **`field_ticket_types`**.

**Not** orphans for this TASK:

- Inverse-only tiers that match the variation but are missing from **`field_ticket_types`** — repairable via **`reconcileEventTicketReferences()`** (`variation_without_ticket_type` with **`reconcile_event_ticket_references`**).  
- **`ambiguous_variation_mapping`** — manual data fix, not orphan cleanup.

---

## 5. Order usage detection strategy

**Goal:** Count **Commerce order items** whose **`purchased_entity`** is the variation ID. **No** customer names, emails, attendee names, or order-level narrative detail in CLI output or logs.

**Implementation:**

- Entity API: `commerce_order_item` storage query on **`purchased_entity`** = variation ID; load items and parent **`commerce_order`** entities.  
- Classify each item’s order **`state`** (workflow id from **`OrderInterface::getState()->getId()`**):

**Protected (“completed usage”) — never unpublish if any items fall here**

Mirrors **`VendorEventOrdersController::INCLUDED_STATES`** (paid/finalised / fulfilment pipeline):

- `completed`, `partially_refunded`, `refunded`, `placed`, `fulfilled`, `fulfillment`

**Draft / cart**

- `draft` — typical cart / pre-placement basket line.

**Other states** (e.g. `canceled` or uncommon transitions)

- **Conservative default:** treat as **`manual_review`** for unpublish (`safe_to_unpublish` false) unless product explicitly narrows this later.

**Counts:** number of **order item entities** (not aggregated quantities), split into **completed**, **draft**, **other**; **total** = sum of all three buckets.

---

## 6. Safe cleanup rules

1. **Default dry-run**; **writes** only when **`apply`** is **true**.  
2. **Unpublish only** — set the Commerce **`status`** field to **unpublished** and **`setPublished(FALSE)`** when available; **never** delete variations, products, ticket types, or orders; **never** change price or SKU. Cleanup **must** reload from storage after save and report **`unpublished`** only when the reload confirms unpublished state (see §11 hotfix).  
3. Target must be on this event’s **`field_product_target`** ticket product and pass the **orphan** definition above.  
4. **Never unpublish** if **any** order item for that variation is in a **protected** state (§5).  
5. **Draft** order items: unpublish **only** if **`allow_draft_usage`** is **true** (default **true**); when draft usage exists, report counts clearly; operators may set **`allow_draft_usage`** false to refuse breaking active carts.  
6. **Unknown** order states: **manual_review**, skip unpublish.  
7. Optional **`variation_ids`** filter: only considered if that ID appears in the orphan inspect set for the event.  
8. After successful unpublish(s), optional **`then_reconcile`** (**default true**): reload event, re-audit; call existing **`repairEvent(..., ['apply' => true])`** **only** if **`repairAbortReason()`** is **null** and there is something to repair — **do not** weaken abort rules.

**Logging:** Channel **`myeventlane_ticket_reconciliation`** — summaries on **`--apply`** cleanup/reconcile tails; **not** per healthy ticket on page render.

---

## 7. Unsafe / manual cases

- **Protected order usage** (§5) on the variation — **manual_review**; do not unpublish.  
- **Ambiguous** duplicate **`commerce_variation`** mappings across tiers for the event — repair abort; not orphan cleanup.  
- **`paid_without_prices`**, missing ticket product, or other TASK 14/15 issues — may remain after orphan unpublish; **`remaining_blockers`** in cleanup result.  
- **Title-only** inference of mappings — **forbidden** (TASK rules).

---

## 8. Drush command design

| Command | Purpose |
| ------- | ------- |
| **`mel:tickets:orphans`** | **`--event={nid}`** (required), **`--format=table|json`** — runs **`inspectOrphanVariations()`**. |
| **`mel:tickets:cleanup-orphans`** | **`--event={nid}`** (required); **`--variation=`** optional (repeatable or comma-separated); **`--apply`** to persist unpublish; **`--no-reconcile`** to skip post **`repairEvent`** tail; **`--disallow-draft-usage`** to refuse unpublish when draft/cart order items exist; **`--format=table|json`**. Dry-run default. |

**Repair UX:** When **`mel:tickets:repair`** skips for **`orphan_variation_not_repairable`**, print explicit guidance to run **`mel:tickets:orphans`** then dry-run **`mel:tickets:cleanup-orphans`**.

---

## 9. Verification plan

1. Pre-proof grep + `drush list` documented in §2.  
2. `php -l` on changed PHP files.  
3. `composer validate`; `ddev composer dump-autoload`; `ddev drush cr`.  
4. Post-impl grep shows symbols + command names under `myeventlane_vendor` and `docs`.  
5. `ddev drush list \| grep mel:tickets` — expect **audit**, **cleanup-orphans**, **orphans**, **repair**.  
6. Run **`mel:tickets:orphans`** for events **1592**, **1584**, **1094**, **1378** (environment permitting).  
7. Dry-run **`mel:tickets:cleanup-orphans`** for the same (no **`--apply`** without approval).  
8. **`mel:tickets:audit`** unchanged semantics for blockers after dry-run (no data change).  
9. `npm run mel:lint` && `npm run mel:build`.  
10. `ddev drush ws --count=50` — no reconciliation flood from read-only commands.

**Drush `php:eval` — copy-paste literally (ellipsis `…` is not PHP):**

```bash
# Variations 4173, 4174, 4175 (adjust IDs per environment)
ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("commerce_product_variation"); foreach ([4173,4174,4175] as $id) { $v=$s->load($id); if (!$v) { print "$id missing\n"; continue; } print $id." ".$v->label()." ".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL; }'

# Single variation 4173 after cleanup (cache reset + reload)
ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("commerce_product_variation"); $s->resetCache([4173]); $v=$s->load(4173); if (!$v) { print "4173 missing\n"; } else { print $v->id()." ".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL; }'
```

---

## 11. Hotfix — cleanup success verification & fresh loads (2026-05-05)

**Observed bug:** `mel:tickets:cleanup-orphans --apply` could print **`unpublished`** while **`mel:tickets:orphans`** / **`mel:tickets:audit`** still showed the variation as published after cache rebuild.

**Root causes (addressed in code):**

1. **Trusting stale objects** — `Product::getVariations()` can return in-memory variation entities that do not reflect the latest `status` from storage. Inspect and audit now resolve variation IDs from the product, **reset variation storage cache**, and **load variations fresh** from `commerce_product_variation` storage (same pattern for the ticket product reference).
2. **Incomplete unpublish write** — unpublish now sets **`status`** (when the field exists) **and** calls **`setPublished(FALSE)`** when available, then **`save()`**, **`resetCache([variation_id])`**, **`load()`** again. **`unpublished`** is reported **only** if `$reloaded && !$reloaded->isPublished()`; otherwise the change row is **`skipped`** with *Attempted to unpublish but variation remained published after save. Manual review required.* and a **`remaining_blockers`** entry; a **`warning`** is logged to **`myeventlane_ticket_reconciliation`** (no false success).
3. **Post-reconcile audit cache** — before **`auditEvent()`** after repair apply, **`commerce_product_variation`**, **`commerce_product`**, **`mel_ticket_type`**, and **`node`** caches are reset, then the event is reloaded (**`finalizeRepairAudit()`**).

**Hooks / sync (reviewed, not disabled):**

- **`myeventlane_commerce_entity_presave()`** (`myeventlane_commerce.module`) runs on **product** presave and may **`save()`** variations when syncing **`field_event`**; it does not target variation unpublish directly. No global disable was applied.
- Broader grep for **`syncTicketTypesToVariations`** / republish patterns is reserved for separate investigation if reload-after-save still shows published.

**Operator rule:** Success output requires **reload confirmation**; stale product variation objects must **not** be trusted for inspect/audit status.

---

## 12. Hotfix — repair lifecycle orphan unpublish & CLI success semantics (2026-05-05)

**Observed bug:** After **`mel:tickets:repair --apply`**, lifecycle logs could claim **Unpublished orphaned variation {id}** while **`mel:tickets:audit`** still emitted **`orphan_variation_not_repairable`** for the same variation — unsafe “success” semantics.

**Root cause A:** **`TicketTypeManager::removeOrphanedVariations()`** used **`setPublished(FALSE)`** only (no reliable **`status`** write), with **no post-save reload verification**, so logs could claim unpublish when storage still showed published.

**Root cause B:** Post-repair **`auditEvent()`** could run without flushing **`commerce_product_variation` / `commerce_product` / `mel_ticket_type` / `node`** caches, reinforcing stale reads in edge cases.

**Fixes:**

- **`removeOrphanedVariations()`** reloads the product by id, iterates variation ids from storage, unpublishes with **`status`** + **`setPublished(FALSE)`**, **`save()`**, **`resetCache`**, **`load()`** — logs **Unpublished orphaned variation** only when reload confirms unpublished; otherwise **`warning`** and **`syncTicketTypesToVariations()`** returns **FALSE** (lifecycle logs sync failure).
- **`syncTicketTypeToVariation()`** resets **`mel_ticket_type`** cache and reloads the tier before reading **`commerce_variation`**, reducing unnecessary “Created variation” when the DB already mapped a variation.
- **`repairEvent()`** uses **`finalizeRepairAudit()`** after apply paths; returns **`success`** (bool) and **`remaining_orphan_variation_ids`** when **`orphan_variation_not_repairable`** still appears in the fresh audit.
- **`mel:tickets:repair`** CLI prints a **warning** when **`applied`** and **`success === false`**, lists variation IDs, and tells operators **not** to blindly re-run repair.

**Operator rule:** **`mel:tickets:repair`** “repair success” in output means **no orphan variation blockers** in the **post-repair fresh audit** — not merely that reconcile ran.

---

## 13. Residual risks (TASK 17+)

- **Order state taxonomy** may evolve; protected-state list must stay aligned with finance/vendor order surfacing.  
- Unpublishing with **draft** lines may strand in-progress checkouts — operators must read draft counts.  
- **Multiple orphan SKUs** after partial cleanup may still block repair until all listed orphans are addressed or manually mapped.
