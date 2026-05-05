# MEL Vendor Console v2 — TASK 17 product variation reference sync

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Fix **Commerce product `variations` field drift** after orphan cleanup / ticket repair so checkout only sees variations aligned with **`field_ticket_types`** mappings. **No** deletes, **no** order mutations, **no** `TicketAvailabilityService` changes.

---

## 1. Problem statement

Checkout resolves paid tiers from **`field_ticket_types`** and **`mel_ticket_type.commerce_variation`**. Commerce still exposes purchasable SKUs via the event’s **`field_product_target`** ticket product and its **`variations`** entity reference field.

If ticket types point at **new or repaired** variation IDs but the product still lists **only** an old orphan variation ID, the buyer flow can surface a variation that **does not map** to any tier (`TicketAvailabilityService` blocks purchase). Operators see contradictory state: tiers map to 4174/4175 while the product still references 4173.

---

## 2. Reproduced event 1592 data (illustrative)

| Artifact | Value |
| -------- | ----- |
| Event | **1592** → `field_product_target` = Commerce product **97** |
| Product **97** `variations` | Only **4173** (General Admission, published) |
| `mel_ticket_type` | **102** “VIP” → variation **4174**; **103** “General Admission” → variation **4175** |

**Symptom:** Repair/lifecycle correctly attached **`commerce_variation`** on ticket types but **did not** rebuild the product’s **`variations`** list, so checkout still offered **4173**, which has **no** tier mapping on **`field_ticket_types**.

---

## 3. Root hypothesis (confirmed in code)

In **`TicketTypeManager::syncTicketTypeToVariation()`**:

- When a **new** variation is created, the code appends its ID to **`commerce_product.variations`** and saves the product.
- When an **existing** variation is updated (branch where `commerce_variation` already points at a variation owned by the same product), the variation entity is saved but **the product `variations` field is not updated**.

Therefore mappings can be repaired on **`mel_ticket_type`** without the product reference set reflecting those IDs (**hypothesis E** from the task brief).

Follow-up gap: even when **`syncTicketTypesToVariations()`** completes, **`removeOrphanedVariations()`** unpublishes orphans but **does not remove** stale IDs from the product `variations` field; a dedicated rebuild is required for consistency.

---

## 4. Safe sync rules

1. **Source of truth for “required” variation IDs:** published, non-archived ticket types on **`field_ticket_types`** with a non-empty **`commerce_variation`** whose target variation belongs to the event ticket product (`bundle` = `ticket_variation`, `product_id` matches **`field_product_target`**).

2. **Preserve references with order usage:** Any variation ID currently on the product that has **one or more** `commerce_order_item` rows referencing `purchased_entity` = that variation **must remain** in the product `variations` list (avoids stranding carts and historical rows). Alignment with TASK 16: at minimum **never drop** variations with **protected / completed** order states; implementation conservatively preserves **any** order-item reference unless product rules later narrow that.

3. **Removal from the product field only:** Removing an ID from **`commerce_product.variations`** does **not** delete the variation entity. Unpublishing orphans remains **`mel:tickets:cleanup-orphans`** / lifecycle unpublish rules.

4. **Wrong-product mappings:** If a tier’s **`commerce_variation`** points at a variation whose **`product_id`** ≠ event ticket product, **do not** “move” variations via sync — emit audit **`product_missing_mapped_variation`** with **`repairable: false`** (manual review).

5. **Repair gating:** **`sync_product_variation_references`** runs from **`mel:tickets:repair --apply`** only when **`repairAbortReason()`** is **null** (no **`orphan_variation_not_repairable`**, **ambiguous** mapping, etc.), matching TASK 14/16 abort semantics.

6. **Dry-run default:** CLI repair continues to default to dry-run; **`--apply`** required for writes.

---

## 5. Commands / verification plan

| Step | Command / action |
| ---- | ------------------ |
| Lint | `php -l web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php` |
| Lint | `php -l web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php` |
| Lint | `php -l web/modules/custom/myeventlane_vendor/src/Commands/EventTicketReconciliationCommands.php` |
| Composer | `composer validate` |
| Cache | `ddev drush cr` |
| Audit (before) | `ddev drush mel:tickets:audit --event=1592` — expect **`product_missing_mapped_variation`** for missing refs when product omits 4174/4175 |
| Repair dry-run | `ddev drush mel:tickets:repair --event=1592` — includes **Would sync product variation references to include mapped ticket variations.** when applicable |
| Repair apply | `ddev drush mel:tickets:repair --event=1592 --apply` — only after orphan blockers cleared and dry-run reviewed |
| Product introspection | `ddev drush php:eval '…'` — print `field_product_target`, `variations` target IDs, loaded variation publish state |
| Follow-up | `ddev drush mel:tickets:audit --event=1592`, `ddev drush mel:tickets:orphans --event=1592`, `ddev drush ws --count=30` |

**Healthy outcome for 1592**

- Product **97** references **4174** and **4175**.
- **4173** not published/selectable (or remains published only if protected usage / manual policy — **no forced unpublish** in TASK 17).
- Audit: no **`orphan_variation_not_repairable`**; no **`product_missing_mapped_variation`** for mapped tiers.

---

## 6. Residual risks (TASK 18+)

- Variations intentionally kept on the product for **non–field_ticket_types** flows (edge hybrids) could be dropped from the field if they have **no** order items and **no** tier on **`field_ticket_types`** — validate against real hybrid inventory before broad apply.
- **`syncTicketTypesToVariations()`** returning **FALSE** still logs orphan unpublish warnings; operators must distinguish **mapping** health vs **orphan** cleanup outcomes.
