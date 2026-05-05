# MEL Vendor Console v2 — TASK 15 ticket product gap + debug gating

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Close the **missing Commerce ticket product** gap (`field_product_target`), quiet **expected-state** watchdog noise from the embedded ticket manager and dashboard boost diagnostics, and extend TASK 14 audit/repair **without** weakening checkout or TASK 14 mapping rules.

---

## 1. Problem statement

1. Events with **`field_event_type`** `paid` or `both` sometimes have **no usable** `field_product_target` (empty, broken reference, or non-ticket entity). Event Studio embeds **`EventTicketManagerForm`**, whose **validation** logged **`Ticket manager validation failed … no linked ticket product`** at **error** severity even when the organiser is simply finishing setup — flooding Watchdog.

2. TASK 14 audit used issue code **`missing_product`** for paid-capable events without a product; TASK 15 standardises on **`missing_ticket_product`** and adds **safe product repair** where a **canonical** code path already exists.

3. **`VendorDashboardController::getTopBoostOpportunity()`** logged **`BOOST CANDIDATE`** at **debug** whenever **`mel.dev_mode`** forced UI fallback (TASK 13). Drupal still records **`mel_debug`** debug lines during normal dashboard renders when dev mode is on — TASK 15 gates those logs behind an **explicit** state flag only.

---

## 2. Watchdog examples (symptoms)

| Symptom | Example / channel |
| ------- | ----------------- |
| Ticket manager | `myeventlane_vendor` — `Ticket manager validation failed for event 1562: no linked ticket product.` |
| Boost diagnostics | `mel_debug` — `BOOST CANDIDATE → … \| show=YES` (repeated per candidate row) |

---

## 3. Product creation / sync services found (canonical)

| Responsibility | Service / method | Notes |
| -------------- | ----------------- | ----- |
| Ensure ticket **Commerce product** + link on event | **`TicketTypeManager::syncTicketTypesToVariations($event)`** | Calls private **`getOrCreateTicketProduct()`** — creates `ticket` product with `field_event`, sets **`field_product_target`** on the event, then syncs **published paid** tiers to variations. **Does not invent tier prices**; unpaid / unpublished tiers are skipped. Ends with **`removeOrphanedVariations()`** (may unpublish variations not in the active sync set — same family as TASK 14 reconcile tail). |
| Orchestrated publish/sync intent | **`EventProductManager::syncProducts($event, $intent)`** | Uses **`syncTicketTypesToVariations`** for paid/both; **must not** be called during passive page render (documented guards). TASK 15 Drush repair calls **`TicketTypeManager`** directly for the missing-product case to avoid duplicating intent guards while staying CLI-explicit. |
| RSVP product creation | **`EventProductManager::syncRsvpProduct()`** | Not used for paid/both missing-product repair in TASK 15. |

**Assumption (verified in code):** There is **no** separate public `ensureTicketProductOnly()`; the narrowest canonical write path for paid/both is **`syncTicketTypesToVariations()`**, which may create an **empty** product when there are no published paid tiers yet.

---

## 4. Missing product cases

| Case | Detection |
| ---- | --------- |
| Empty `field_product_target` | Field missing or empty on paid/both event. |
| Broken reference | Target ID present but **`entity`** not loadable or not a **`ticket`** Commerce product. |
| Ambiguous relink | More than one **`commerce_product`** of bundle `ticket` with **`field_event`** = this event — **human choice required**, no auto-link. |

---

## 5. Safe repair criteria (TASK 15 Drush `--apply`)

Repair **may** create/link a product when:

1. **`--event={nid}`** and **`--apply`** after reviewing dry-run.
2. Event **`field_event_type`** is **`paid`** or **`both`**.
3. **`field_product_target`** is empty, broken, or invalid type — **no** currently valid linked ticket product.
4. **Exactly one** candidate product exists with **`field_event`** = event → **link** `field_product_target` only (no variation sync).
5. **Zero** candidates → **`TicketTypeManager::syncTicketTypesToVariations($event)`** (creates/links product; syncs published paid tiers per existing rules).

Repair **must not** run when:

- **`ambiguous_ticket_product_relink`** (multiple ticket products for same event via `field_event`).
- TASK 14 abort conditions still apply after product step: **`ambiguous_variation_mapping`**, **`orphan_variation_not_repairable`** (reconcile path unchanged).

Dry-run **never** writes; it lists planned actions and repairability.

---

## 6. Unsafe cases (report only)

- Multiple ticket products pointing at the same event via **`field_event`**.
- Paid tier / price / mapping gaps that **`syncTicketTypesToVariations`** does not resolve (TASK 14 codes remain).
- Guessing a product by title without **`field_event`** proof.

---

## 7. Debug log source (BOOST CANDIDATE)

| Item | Detail |
| ---- | ------ |
| **Source** | `VendorDashboardController::getTopBoostOpportunity()` → `logger.channel.mel_debug` |
| **TASK 15 change** | Log **only** when **`state.get('mel.debug_boost_candidates')`** is truthy. **`mel.dev_mode`** may still affect **UI** visibility; it **must not** imply logging. |

**Enable (example):**  
`ddev drush state:set mel.debug_boost_candidates 1 --input-format=integer`

**Disable:**  
`ddev drush state:delete mel.debug_boost_candidates`

---

## 8. Planned changes (implementation)

1. **`EventTicketReconciliationService`**: issue **`missing_ticket_product`**; **`ambiguous_ticket_product_relink`**; extend **`repairEvent()`** with optional product link/sync step before reconcile; extend **`repairAbortReason()`**.
2. **`EventTicketManagerForm`**: remove **error**-level logging for **expected** missing product on validate/submit; keep form errors/messages for the user.
3. **`VendorEventPresentationAlertsBuilder`**: **`missing_ticket_product`** workspace + chip alerts using **`field_event_type`** `paid`/`both` and a **valid** product reference check (handles broken references).
4. **`VendorDashboardController`**: gate **`BOOST CANDIDATE`** debug logs behind **`mel.debug_boost_candidates`**.
5. **Docs:** this file + TASK 15 notes appended to **`docs/vendor-console-v2-route-map.md`**.

---

## 9. Verification plan

- `php -l` on changed PHP; `composer validate`; `ddev drush cr`.
- `ddev drush php:eval` load `myeventlane_vendor.event_ticket_reconciliation`.
- `ddev drush mel:tickets:audit --event=1562` — expect **`missing_ticket_product`** or ambiguous code as appropriate; no fatal.
- `ddev drush mel:tickets:repair --event=1562` — dry-run shows product + reconcile steps; no writes without `--apply`.
- **Do not** run `--apply` without explicit operator approval when data is ambiguous.
- `ddev drush ws --count=50` after dashboard/events browse — no **`BOOST CANDIDATE`** when `mel.debug_boost_candidates` unset (even if `mel.dev_mode` is on).
- `npm run mel:lint` && `npm run mel:build`.

---

## 10. Residual risks (TASK 16)

- **`syncTicketTypesToVariations()`** may unpublish orphan variations on the event product when tiers exist — operators must review Commerce state before **`--apply`**.
- Events that are **paid/both** in UI terms but whose domain resolver shows **`unknown`** still need **`field_event_type`** on the node for the new alert; hybrid edge cases may need a follow-up if product is required for **`both`** when RSVP is also configured.
