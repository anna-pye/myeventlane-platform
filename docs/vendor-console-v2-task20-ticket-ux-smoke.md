# MEL Vendor Console v2 — TASK 20 ticket UX and smoke verification

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** UX hardening and smoke verification for the working ticket flow (Event Studio, Advanced Ticket Manager, public matrix, checkout handoff, audit copy, inactive legacy tiers, accessibility). **No** ticket architecture rebuild, **no** new save services, **no** checkout enforcement weakening.

---

## 1. Current working baseline

- **Canonical save/sync:** `TicketTierLifecycleService::persistTicketManagerRows()` — used by `EventTicketManagerForm` (Advanced + embedded Studio) and `EventStudioForm` after main save when ticket POST values exist.
- **Checkout enforcement:** `TicketAvailabilityService` unchanged; purchasable variations filtered from mapped active tiers.
- **Audit/repair:** `EventTicketReconciliationService::auditEvent()` — published inverse gaps are errors; **unpublished** inverse-only tiers emit **`inactive_inverse_ticket_types_ignored`** (**info**, non-repairable) per TASK 19 hotfix.
- **Post-save vendor message:** `formatPostTicketPersistAuditSummary()` — success, inactive-only-paid-unpublished branch, or codes + CTA to Advanced manager / Drush.

---

## 2. Pages inspected

| Area | Path / context |
| ---- | ---------------- |
| Event Studio tickets | `/vendor/events/{node}/edit` — paid mode, `mel[tickets_section][tickets]` embed |
| Advanced Ticket Manager | `/vendor/events/{event}/tickets` — `EventTicketManagerForm` |
| Public booking | `/event/{nid}/book` (or project routing equivalent) — `TicketSelectionForm` inside `myeventlane-event-book.html.twig` |
| Workspace (nav) | `/vendor/events/{event}` — tabs link to tickets route |

---

## 3. UI polish checklist (vendor)

- [ ] Ticket section explains: add rows → Active when ready → **Save and sync tickets**.
- [ ] **Active** checkbox: visible label, helper text, keyboard focus, adequate tap target.
- [ ] **Save and sync tickets** primary CTA visible near rows (not only below collapsed tools).
- [ ] Post-save status: success / inactive-only / actionable warnings.
- [ ] Advanced page: same behaviours + **Back to Event Studio** / **Event workspace** links (route-based).
- [ ] “More ticket tools” wording de-duplicated vs row **More options** (clearer details title).

---

## 4. Public ticket matrix checklist

- [ ] Only **active** mapped paid tiers appear (published variation + tier on `field_ticket_types`).
- [ ] Inactive / unmapped tiers **not** shown.
- [ ] Quantity controls usable on **~390px** width; no horizontal overflow.
- [ ] Prices formatted in **AUD** (store formatter).
- [ ] Sold out / disabled quantity respected.
- [ ] Panel title + primary CTA copy consistent (**Choose your tickets** / **Continue to checkout**).
- [ ] No orphan variation rows in matrix (`filterPurchasableVariations`).
- [ ] No new Watchdog noise from ticket render path.

---

## 5. Checkout smoke checklist

1. Select quantity for an active paid tier → submit.
2. Cart line references expected **Commerce variation** ID.
3. Cannot select tiers not returned by availability filter (UI + validate).
4. Do **not** alter `TicketSelectionForm` validation / `TicketAvailabilityService` unless a verified regression.

---

## 6. Accessibility checklist

- Minimum **44×44px** touch targets: Active control row, quantity inputs, Save CTA.
- **Visible :focus-visible** on interactive controls.
- **Visible labels** (no label-only-by-placeholder for critical fields).
- **390px** stacking: single column ticket rows where applicable.
- **`!important`:** avoid unless documented (none added in TASK 20 without note).

---

## 7. Verification plan

| Step | Command / action |
| ---- | ---------------- |
| Git | `git status -sb` |
| PHP syntax | `php -l` on each changed `.php` |
| Composer | `composer validate` |
| Cache | `ddev drush cr` |
| Frontend | `npm run mel:lint`, `npm run mel:build` |
| Audit/repair | `ddev drush mel:tickets:audit --event=1094`, `… repair …`, repeat for **1592** |
| Data spot-check | `ddev drush php:eval` snippet for **1094** (`field_ticket_types`, inverse tiers) |
| Watchdog | `ddev drush ws --count=30` |
| Browser | Optional: Studio tickets, `/vendor/events/1094/tickets`, public book page, cart |

### Expected audit/repair (1094, 1592)

- **1094:** Audit OK or **info-only** for inactive inverse legacy IDs; repair **`no_repair_actions`** when mapping clean.
- **1592:** Clean audit; repair no-op.

### Residual risks (for TASK 21)

- **`both`** booking mode ordering and RSVP + paid UX edge cases.
- **Autosave** POST omitting ticket subtree vs full Studio save.
- **Stripe / cart** flows outside TASK 20 browser scope if not manually smoke-tested.

---

## 8. Implementation log (fill during TASK 20)

| Item | Result |
| ---- | ------ |
| Files changed | See **TASK 20 implementation notes** in [`vendor-console-v2-route-map.md`](vendor-console-v2-route-map.md). |
| Event Studio ticket UX | Paid guidance line added above embedded manager; same **Active** / **Save and sync tickets** as advanced form. |
| Advanced Ticket Manager UX | Workspace + Studio back links; sync intro before rows; primary save before collapsed “Groups, access codes…” details. |
| Public matrix | Twig already uses **Choose your tickets**; submit CTA set to **Continue to checkout**; `_event-book.scss` narrow-width quantity/row overflow tightened. |
| Checkout smoke | Not re-run end-to-end in this session after string/CSS changes; prior logs show cart load + ticket save for 1094. |
| Audit/repair 1094 / 1592 | **1094:** `mel:tickets:audit` — 0 errors, 0 warnings; info `inactive_inverse_ticket_types_ignored` (109, 110). `mel:tickets:repair` — `no_repair_actions` / nothing to apply. **1592:** this environment reports `variation_without_ticket_type` (102, 103) repairable — **not** the “known clean” baseline from TASK 19 docs; operator should reconcile or refresh data before treating 1592 as smoke-clean. |
| Browser smoke | Not run (no interactive browser in this session). |
| Watchdog | Last 30 entries: reconciliation + cart/commerce notices; **no** typed-property fatals or orphan-mapping spam tied to TASK 20 edits. |
