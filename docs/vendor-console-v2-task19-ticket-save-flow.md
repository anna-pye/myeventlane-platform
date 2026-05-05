# MEL Vendor Console v2 — TASK 19 ticket save flow

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Fix persistence from Event Studio and Advanced Ticket Manager into the canonical ticket model (`field_ticket_types`, `mel_ticket_type`, `commerce_variation`, product/variation sync). No checkout / `TicketAvailabilityService` changes.

---

## 1. Problem statement

Vendors add or edit tickets in Event Studio (embedded ticket manager) or `/vendor/events/{event}/tickets`, save successfully at the UI level, but tickets do not appear on the public event or in checkout because the **canonical MEL ticket layer** is never updated.

Checkout and display pricing trust **`event.field_ticket_types`** and **`mel_ticket_type`** rows with **`commerce_variation`** (TASK 13–14). Variations alone are insufficient.

---

## 2. Terminal / audit evidence (pre-fix)

| Event | Notes |
| ----- | ----- |
| **1592**, **1584** | Clean after orphan cleanup + repair (TASK 16–18). |
| **1094** (*The Newest Show*, paid) | `field_ticket_types` empty; inverse `mel_ticket_type` for event none; `field_product_target` = 54; variations 82/83 unpublished after orphan cleanup → audit **`paid_without_prices`** / “No published paid ticket types found”. |
| **1378** (*Copy*, RSVP) | `field_ticket_types` empty; still **`field_product_target` = 54** (stale paid product on RSVP-only event). |

Conclusion for **1094**: Not repairable mapping drift alone — there are **no** canonical tiers to reconcile onto `field_ticket_types`. The **save path** must create and attach tiers.

---

## 3. Canonical ticket save model (reference)

1. **Event → product:** `event.field_product_target` → `commerce_product` (ticket bundle).  
2. **Event → tiers:** `event.field_ticket_types` lists **`mel_ticket_type`** IDs (order matters for display).  
3. **Tier → event:** `mel_ticket_type.event` → event node (inverse; checkout still requires field list — TASK 14).  
4. **Tier → variation:** `mel_ticket_type.commerce_variation` → `ticket_variation`.  
5. **Projection:** `TicketTypeManager::syncTicketTypesToVariations()` syncs **tier → Commerce** (create/update variations, orphan handling per TASK 17/18).  
6. **Lifecycle orchestration:** `TicketTierLifecycleService` owns create/update/attach/reconcile + **`syncPaidTiers()`** → `syncTicketTypesToVariations()`.

---

## 4. Files inspected

| Area | File |
| ---- | ---- |
| Studio save | `EventStudioSaveService.php` — `applyTicketPayload()` only handles capacity/external/collect/`field_product_target`; **does not** touch `mel_ticket_type` or `field_ticket_types`. |
| Studio payload | `EventStudioMelPayloadService.php` — no ticket matrix / tier rows in payload. |
| Studio UI | `EventStudioForm.php` — embeds `EventTicketManagerForm` under `mel[tickets_section][tickets]`. |
| Wizard tickets step | `EventStudioTicketsForm.php` — booking mode + product autocomplete only (no tier rows). |
| Ticket manager | `EventTicketManagerForm.php` — saves **Commerce variations** only; **no** `TicketTierLifecycleService` / `TicketTypeManager` tier attachment. |
| Lifecycle | `TicketTierLifecycleService.php` — `appendTicketToEvent`, `syncPaidTiers`, `reconcileEventTicketReferences`, `updateTicketType`. |
| Projection | `TicketTypeManager.php` — `syncTicketTypesToVariations()`. |
| Audit/repair | `EventTicketReconciliationService.php`, `EventTicketReconciliationCommands.php`. |

---

## 5. Root cause

**Primary (B + F + G):**

1. **`EventStudioSaveService`** never creates or updates **`mel_ticket_type`** or **`field_ticket_types`** from Studio payload (ticket payload not modeled there).  
2. **`EventTicketManagerForm::submitForm()`** persists **variations only** and never calls **`TicketTierLifecycleService`** / **`syncPaidTiers()`**, so tiers stay missing even though Commerce rows exist.  
3. **Event Studio parent submit** (`EventStudioForm::submitForm`) runs **`EventStudioSaveService::save()`** only — **nested form submit handlers for `EventTicketManagerForm` do not run** when the user clicks the main **Save** button. Ticket variation persistence did not run at all on full Studio save.

**Secondary:**

- **RSVP** events can retain **`field_product_target`** from a prior paid state; canonical save should clear product target when mode is not paid/`both` (handled in `EventStudioSaveService` for non-paid; verify copy edge cases).

**Mapping:** Task list → **B** (no mel_ticket_type from Studio path), **F** (`syncTicketTypesToVariations` not invoked after manager save), **G** (Studio vs advanced manager submit divergence).

---

## 6. Fix plan

1. **`TicketTierLifecycleService`**  
   - Add **`persistTicketManagerRows()`** (single canonical path):  
     - Reuse the same Commerce variation create/update/delete behaviour as the ticket manager (moved or delegated from the form).  
     - For each surviving variation: **find or create** `mel_ticket_type` (`paid`), set **price/title/status**, set **`commerce_variation`**, **`event`**, attach via **`appendTicketToEvent(..., save: FALSE)`** batched.  
     - Rebuild **`field_ticket_types`** order from submitted rows; for **`both`**, preserve non–commerce-managed tiers, replace paid tiers tied to this event’s ticket product.  
     - On variation delete: **`archiveTicketOnEvent`** (or detach) for the tier mapped to that variation **before** deleting the variation.  
     - Final **`event` save** + **`syncPaidTiers()`**.  
   - Log **info** on successful batch submit (channel `myeventlane_event`), not per request spam.

2. **`EventTicketManagerForm`**  
   - Inject **`TicketTierLifecycleService`**; **`submitForm()`** delegates to **`persistTicketManagerRows()`**.

3. **`EventStudioForm`**  
   - **`validateForm()`**: for **`paid`**, require at least one active ticket row **or** existing paid tiers on `field_ticket_types` (same intent as lifecycle row validation).  
   - **`submitForm()`**: after successful **`saveService->save()`**, if booking mode is paid-capable and embedded ticket values exist, **reload node** and call **`persistTicketManagerRows()`** with extracted `mel[tickets_section][tickets][tickets]` values; surface errors via messenger.

4. **`EventTicketReconciliationService`**  
   - When **`loadPublishedPaidTicketPrices`** is empty, emit **granular issue codes** (`paid_without_ticket_types`, etc.) **in addition to** **`paid_without_prices`** for backwards compatibility with existing grep/automation.

5. **Repair** (`mel:tickets:repair`): extend **only** where safe — e.g. **`reconcileEventTicketReferences`** when inverse tiers exist; **no** inventing tiers when none exist (1094 remains non-repairable).

---

## 7. Verification plan

1. `php -l` on changed PHP files.  
2. `composer validate`, `ddev drush cr`, `npm run mel:lint`, `npm run mel:build`.  
3. `ddev drush mel:tickets:audit --event=1094` and `--event=1378` — expect clearer codes; 1094 still not repairable without editorial ticket creation.  
4. **Manual:** Paid event in Event Studio — add ticket (title + price), click **Save** (not only inline ticket AJAX); re-audit — `field_ticket_types` non-empty, **`paid_without_prices`** cleared when tiers published/priced/mapped.  
5. **Advanced** `/vendor/events/{event}/tickets` — save tickets; same audit expectations.  
6. Confirm checkout still blocks unmapped variations (no changes to `TicketAvailabilityService`).

---

## 8. Residual risks (TASK 20)

- **`both`** events with complex mixes of RSVP + paid: ordering and preservation of non-commerce tiers must stay correct when replacing paid tier refs.  
- **$0** “free” rows on paid events: lifecycle historically treated paid builder input as price &gt; 0; variation-backed path must accept Commerce **0.00** without inventing prices.  
- **Autosave** (`EventStudioAutosaveController`): if it calls `save()` with partial payload, ensure it does not clear tickets (out of scope unless autosave sends empty ticket panel).
