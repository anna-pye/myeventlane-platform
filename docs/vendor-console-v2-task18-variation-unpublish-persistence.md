# MEL Vendor Console v2 — TASK 18 variation unpublish persistence

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Fix orphan cleanup so unpublish **persists** for Commerce `ticket_variation` entities (e.g. variation **4173**, event **1592**, product **97**). **No** deletes; **no** checkout / `TicketAvailabilityService` changes.

---

## 1. Problem statement

TASK 16 verification correctly detected that **`mel:tickets:cleanup-orphans --apply`** did **not** leave the orphan variation unpublished: after `save()` + storage reload, **`isPublished()`** stayed **true** and **`orphan_variation_not_repairable`** remained. Operators saw a false “attempted unpublish” failure even though the CLI sequence matched the documented steps.

---

## 2. Terminal proof (observed before TASK 18)

| Step | Result |
| ---- | ------ |
| `mel:tickets:cleanup-orphans --event=1592` (dry-run) | Safe — **would_unpublish** for orphan **4173** (zero protected/completed usage). |
| `mel:tickets:cleanup-orphans --event=1592 --apply` | **skipped** — *Attempted to unpublish but variation remained published after save. Manual review required.* |
| `mel:tickets:audit --event=1592` | Still blocked by **`orphan_variation_not_repairable`** for **4173**. |

Environment snapshot: event **1592** → **`field_product_target`** product **97**; product exposed variation **4173**; tier mappings did not treat **4173** as the active SKU; order usage **0** total.

---

## 3. Direct save test result (root cause)

Copy-paste repro (Drush):

```bash
ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("commerce_product_variation"); $v=$s->load(4173); print "before=".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL; $v->set("status", 0); if (method_exists($v, "setPublished")) { $v->setPublished(FALSE); } $v->save(); $s->resetCache([4173]); $v=$s->load(4173); print "after=".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL;'
```

**Outcome:** **after** remained **published** with **`raw_status=1`** — the write did **not** persist.

Follow-up probe:

- `$v->set('status', 0)` then immediately reading **`get('status')->value`** still behaved like published (**boolean `status` field does not reliably accept integer `0` the same way as `FALSE`**).
- **`EntityPublishedTrait`** exposes **`setUnpublished()`** (and parameterless **`setPublished()`** which forces **published**). Calling **`setPublished(FALSE)`** is **not** part of the supported API and does not mean “unpublish”.
- **`$v->setUnpublished(); $v->save();`** → storage reload showed **unpublished**; SQL **`commerce_product_variation_field_data.status`** updated accordingly.

**Classification:** Not **A** (stale product graph in cleanup alone) or **C** (product immediately re-saving published child) as the primary failure mode — **B** was triggered by **incorrect unpublish API usage** (`set('status', 0)` + bogus **`setPublished(FALSE)`**), so **`save()` wrote published state**.

---

## 4. Suspected save-path causes (investigated)

| Hypothesis | Verdict |
| ---------- | ------- |
| Hooks republishing after save | Secondary only — direct Drush save with **`setUnpublished()`** persisted; no project **`hook_entity_presave`** on **`commerce_product_variation`** required for the fix. |
| Product **`getVariations()`** stale graph re-saving published rows | Possible in other flows; TASK 16 cleanup already loads from **`commerce_product_variation`** storage for apply. Primary bug was boolean **`status`** assignment. |
| Workspaces / moderation | **`commerce_product_variation`** uses ignored workspace handler in this project; no moderation finding tied to this regression. |

---

## 5. Safe fix strategy

1. **Unpublish API:** Use **`EntityPublishedInterface::setUnpublished()`** for variations; fallback **`set('status', FALSE)`** if needed — **never** `set('status', 0)` for Commerce boolean **`status`**.
2. **Remove invalid calls:** Drop **`setPublished(FALSE)`** (trait **`setPublished()`** is publish-only / no-arg).
3. **Align lifecycle:** Apply the same correction in **`TicketTypeManager::removeOrphanedVariations()`** so repair/unpublish logs match storage.
4. **Diagnostics:** On persistence failure, emit structured CLI fields (variation id, before/after published + raw **`status`**, whether reload confirms published, suggested operator action).
5. **Fallback (explicit `--apply` only):** If unpublish still fails **and** **`total_order_items === 0`**, detach the variation ID from the event ticket product’s **`variations`** field (entity **not** deleted), then verify the ID is absent — satisfies “checkout must not see it” without weakening **`TicketAvailabilityService`**.

---

## 6. Verification plan

| Command | Expectation |
| ------- | ----------- |
| `php -l` on touched PHP files | Clean. |
| `composer validate` | Pass. |
| `ddev drush cr` | Pass. |
| `ddev drush mel:tickets:cleanup-orphans --event=1592 --apply --no-reconcile` | **`unpublished`** for safe orphans (or **`removed_product_reference`** only if fallback path documented). |
| `ddev drush php:eval '…load(4173)…'` | **unpublished** (or not listed on product if fallback). |
| `ddev drush mel:tickets:orphans --event=1592` | No published orphan **4173** if product still references it while published — inspect output. |
| `ddev drush mel:tickets:audit --event=1592` | No **`orphan_variation_not_repairable`** for detached/unpublished **4173**. |
| `ddev drush mel:tickets:repair --event=1592` | Dry-run — no orphan blocker before any **`--apply`**. |

---

## 7. TASK 18 implementation notes (post-merge)

Recorded **2026-05-05** (local DDEV):

- **Root cause:** Boolean **`status`** unpublish must use **`EntityPublishedInterface::setUnpublished()`** (or **`set('status', FALSE)`**). **`set('status', 0)`** does **not** reliably change Commerce’s boolean publish column; **`setPublished(FALSE)`** is **not** a supported unpublish API (**`setPublished()`** is parameterless and publishes).
- **Files changed:** `web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`, `web/modules/custom/myeventlane_vendor/src/Commands/EventTicketReconciliationCommands.php`, `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`, `docs/vendor-console-v2-route-map.md`, this file.
- **Broken repro (`set('status', 0)` + `setPublished(FALSE)`):** Reload stayed **published** / **`raw_status=1`** (matches pre-fix operator report).
- **Direct `php:eval` with `setUnpublished()` + `save()`:** Persisted **`raw_status="0"`**.
- **`mel:tickets:cleanup-orphans --event=1592 --apply --no-reconcile`:** After temporarily republishing **4173** to exercise the apply path, CLI showed **`• variation 4173 — unpublished`** and watchdog **`Orphan ticket variation 4173 unpublished…`**.
- **Fallback product-reference removal:** **Not used** in verification (unpublish succeeded).
- **Final state variation 4173:** **Unpublished** in storage after cleanup apply.
- **Product 97 `variations`:** Still listed **`target_id=4173`** in this environment while the SKU is **unpublished** — acceptable for audit/orphans (published orphans cleared); TASK 17 **`repair --apply`** can rebuild references when mappings warrant it.
- **`mel:tickets:orphans --event=1592`:** **0** orphans after unpublish.
- **`mel:tickets:audit --event=1592`:** **`ok`** — no **`orphan_variation_not_repairable`**.
- **`mel:tickets:repair --event=1592` (dry-run):** **`no_repair_actions`** — repair apply was **not** required for this audit snapshot.

### Residual risks for TASK 19

- **Detach fallback** (zero order usage only) can drop an ID from **`commerce_product.variations`** while the entity stays published — use only when unpublish still fails; follow with audit/repair discipline.
- Operators pasting **`set('status', 0)`** Drush snippets will **still** see “published after save” until they switch to **`setUnpublished()`**.
