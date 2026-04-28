# Task 5 — Event Studio ticket save and publish (diagnostic audit)

**Date:** 2026-04-28  
**Environment:** Local `ddev` + codebase review (staging browser flows **not** executed in this session).  
**Scope:** RSVP vs paid Event Studio paths, publish/save orchestration, booking entrypoints, Stripe readiness signals (no onboarding/gateway config edits).

---

## Plan (concise)

1. Confirm routes and controllers for Event Studio, autosave, booking (`/event/{node}/book`), RSVP public routes.  
2. Trace save chain: `EventStudioBaseForm` → `EventStudioMelPayloadService` → `EventStudioSaveService` → `MelTicketTypeManager`.  
3. Compare legacy wizard validation (`EventWizardPublishValidator`) vs Event Studio publish (`EventStudioPublishForm`).  
4. Check vendor entry (`VendorEventCreateController` vs direct Event Studio create).  
5. Classify risks against P0/P1/P2; recommend Task 6 vs Task 5B.  

---

## Commands run

| Command | Result |
|--------|--------|
| `git status --short` | Clean (no unstaged output) |
| `git branch --show-current` | `cursor/onboard-storage-fix-128b4` |
| `git log -1 --oneline` | `a15b2f5a Enhance StripeService to include additional gateway IDs for secret key retrieval` |
| `composer validate` | `./composer.json is valid` |
| `ddev drush cr` | Cache rebuild complete |
| `ddev drush route \| grep -Ei "event_studio\|vendor/events\|event/.*/book\|rsvp\|checkout\|ticket"` | See **Routes checked** |
| `grep -R "EventStudioTicketsForm\|…" web/modules/custom` | Broad match set (see **Files inspected**) |
| `ddev drush ws --count=80 \| grep -Ei "event_studio\|ticket\|…"` | Sparse local hits (see **Watchdog**) |
| `ddev drush php-eval` (latest 5 event nodes: type + published only) | Sample output only; no PII |

**Config export/import:** not run (per instructions).  
**Secrets:** not touched.

---

## Routes checked (`ddev drush route`)

**Event Studio (representative)**

| Route name | Path |
|------------|------|
| `myeventlane_event_studio.create` | `/vendor/events/create` |
| `myeventlane_event_studio.edit_tickets` | `/vendor/events/{node}/edit/tickets` |
| `myeventlane_event_studio.edit_publish` | `/vendor/events/{node}/edit/publish` |
| `myeventlane_event_studio.autosave` | `/vendor/events/autosave` |
| `myeventlane_vendor.console.studio.event_tickets_save` | `/vendor/studio/event/{event}/tickets` |

**Booking / RSVP / checkout (representative)**

| Route name | Path |
|------------|------|
| `myeventlane_commerce.event_book` | `/event/{node}/book` |
| `myeventlane_rsvp.public_rsvp_form` | `/event/{event}/rsvp` |
| `myeventlane_rsvp.form` | `/event/{node}/rsvp/form` |
| `commerce_checkout.form` | `/checkout/{commerce_order}/{step}` |

Legacy vendor build wizard routes (`/vendor/events/{event}/build/...`) remain registered but are outside Event Studio; redirects may apply via `VendorLegacyWizardRedirectSubscriber` (not expanded here).

---

## Files inspected

**Event Studio**

- `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml`
- `src/Controller/EventStudioController.php`
- `src/Controller/EventStudioAutosaveController.php` (partial)
- `src/Form/EventStudioBaseForm.php`
- `src/Form/EventStudioTicketsForm.php`
- `src/Form/EventStudioPublishForm.php`
- `src/Service/EventStudioSaveService.php`
- `src/Service/EventStudioMelPayloadService.php`
- `src/Service/MelTicketTypeManager.php` (partial + validation/sync sections)
- `src/EventStudioPreprocess.php`
- `templates/mel-event-studio.html.twig` (publish step)

**Vendor / commerce / event**

- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventCreateController.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorConsoleBaseController.php` (`assertStripeConnected`)
- `web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php` (partial)
- `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php`
- `web/modules/custom/myeventlane_event/src/Service/EventCtaResolver.php` (partial)
- `web/modules/custom/myeventlane_event/src/Form/EventWizardPublishForm.php` (legacy wizard publish)
- `web/modules/custom/myeventlane_event/src/Service/EventWizardPublishValidator.php`
- `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php` (partial: `syncProducts`, RSVP product helper)

---

## Code-backed behaviour (save / publish)

### Wizard save pipeline

- Step forms extend `EventStudioBaseForm`; **Continue** calls `persistWizardMel()` → `EventStudioMelPayloadService::buildFromFormState()` → `EventStudioSaveService::save($payload, …, $draft)`.
- **Tickets step** (`EventStudioTicketsForm`): `field_event_type` radios (`rsvp` | `paid` | `external`), embedded `EventTicketsBuilder`, hidden JSON `studio_ticket_tiers`, RSVP capacity maps to payload `capacity` via `rsvp_capacity` in the mel payload service (not `field_capacity` name in mel — normalized to `capacity` in payload).

### Paid validation (non-draft)

- `MelTicketTypeManager::validateStudioTicketDefinitions()` (non-draft): paid events require at least one tier **or** existing `field_ticket_types` refs, else: *“You must create at least one ticket type before saving a paid event.”*
- `EventStudioSaveService::applyTicketPayload()` (non-draft): paid requires `field_product_target` **or** messaging references linking a product — error: *“Paid events need a ticket product…”* when empty.

After node save, `MelTicketTypeManager::onEventStudioSaveComplete()` applies `studio_ticket_tiers` rows, merges `field_ticket_types`, then `syncCommerceAndPublishCatalogSignal()` runs paid tier sync via `TicketTierLifecycleService::syncPaidTiers()` for `paid`/`both`.

### RSVP booking readiness (public `/event/{node}/book`)

- `BookController` delegates RSVP branch to `RsvpPublicForm` only when `EventCtaResolver` yields RSVP **and** `melEventHasRsvp()` is true.
- `melEventHasRsvp()` requires `field_ticket_types` referenced `mel_ticket_type` entities with `ticket_kind` **rsvp**. If there are no tiers / wrong kind, users see *“RSVP is not yet available for this event.”*

RSVP **event-level** capacity uses `field_capacity` from payload `capacity` (from RSVP capacity field) in `applyTicketPayload`, separate from per-tier capacity in the ticket builder.

### Publish step (Event Studio)

- `EventStudioPublishForm` sets **draft save = false** so `status` / published flag applies; success redirects to canonical node view.
- **No** call to `EventWizardPublishValidator` (that validator is for the legacy `/vendor/events/{event}/build/publish` wizard).
- **No** Stripe / `charges_enabled` check inside `EventStudioSaveService` for paid publishes.

### Stripe UX vs enforcement

- `EventStudioPreprocess` sets `mel_publish_blocked` / `mel_publish_stripe_gate` when onboarding flags lack `stripe_connected` for **paid**/**both** events — used in `mel-event-studio.html.twig` as **warnings** beside `element.mel.status`; the publish checkbox remains in the form tree unless separately restricted (no matching server-side guard found in Event Studio save).
- **Vendor entry:** `VendorEventCreateController::buildForm()` calls `assertStripeConnected()` before redirecting to Event Studio create — **blocks** `/vendor/events/add` when store not Stripe-connected (admin/user 1 bypass). Route `myeventlane_event_studio.create` itself does **not** invoke `assertStripeConnected()` in `EventStudioController::buildCreate()` (different entry path).

### Legacy wizard-only RSVP rule

- `EventWizardPublishValidator` may require `field_rsvp_target` when that field exists and join type is RSVP. Event Studio publish **does not** use this validator; public RSVP in `BookController` is driven by **mel_ticket_type** RSVP tiers, not `field_rsvp_target`.

---

## Manual / browser results (A–C)

**Not performed** in this audit session (no authenticated vendor session on staging). Staging verification should confirm:

- RSVP: ticket builder adds at least one **free RSVP** tier; publish; public CTA → `/event/{nid}/book` shows RSVP form; no card step for free RSVP.
- Paid (Stripe ready): tiers + product sync; CTA → ticket selection → checkout → payment.
- Paid (Stripe not ready): whether vendor can still submit publish via Event Studio footer (code suggests possible server-side gap); checkout behaviour.

---

## Database / read-only samples (`drush php-eval`)

Safe aggregate only (latest five event nodes by changed date):

```
1577 type=paid published=no
1563 type=paid published=no
1380 type=paid published=yes
1226 type=paid published=yes
1093 type=paid published=yes
```

No attendee/order payloads printed.

---

## Watchdog (`ddev drush ws --count=80 | grep …`)

Local sample filtered lines included `mel_debug` notices and unrelated errors (`cron` session, `myeventlane_pro` abandoned cart). **No** Event Studio save failures surfaced in this narrow window — not sufficient to prove staging health.

---

## Findings classification

### P0 (blocking / safety — needs runtime proof)

- **None proven** from code review alone. Critical scenarios (RSVP cannot save, paid sells without payment readiness, wrong vendor at checkout) must be confirmed on staging with manual flows + targeted logs.

### P1

1. **Paid publish vs charge-ready Stripe — UI-heavy gate:** Event Studio preprocess/template can warn when Stripe is not connected, but **`EventStudioSaveService` does not enforce `charges_enabled` / vendor Stripe readiness on publish** for paid events. If product requires “cannot publish paid listing until charge-ready,” this is a **server-side gap** relative to project **hard access control** expectations — **confirm with product** whether checkout/order pipelines already enforce enough.
2. **Dual entry to Event Studio:** `/vendor/events/add` enforces Stripe redirect; **`/vendor/events/create` does not** call `assertStripeConnected()` — vendors might reach create via bookmark/direct URL depending on menu exposure (still subject to vendor console access).
3. **RSVP requires MEL tiers:** Public book flow expects **at least one** `mel_ticket_type` with `ticket_kind = rsvp`. Saving RSVP mode **without** adding a tier in the builder can yield a published event whose book page shows RSVP unavailable — **validation gap or documentation/UX** (not necessarily P0 if intentional).

### P2

- `BookController::melEventHasRsvp()` uses `\Drupal::logger('mel_debug')` — service locator pattern and noisy tier-check logging (quality/maintainability).

---

## Smallest recommended fix (only if P1 #1 is confirmed)

Add a **single server-side guard** in the paid publish path (e.g. validate store `field_stripe_charges_enabled` / platform rules inside `EventStudioSaveService` or a dedicated policy service injected into save) **after** product confirms the rule — **not implemented** in this diagnostic task.

---

## Recommended next task

- If **staging manual checks** for A–C pass with **no** reproducible P0/P1 behaviour: **TASK 6 — Booking and checkout verification** (deeper Commerce checkout, order ownership, stock).
- If **P1 #1** is confirmed (paid events publish while Stripe not charge-ready): **TASK 5B — Server-side Stripe charge-ready gate for Event Studio paid publish** (narrow scope; no unrelated refactors).

---

## Task 6 proceed?

**Yes, recommend proceeding to TASK 6** once manual RSVP/paid/stripe-not-ready scenarios are executed on staging and this document is updated with results — **unless** manual testing confirms P1 #1 as a production blocker, in which case schedule **TASK 5B** first.

---

## Files changed in repo for this audit

- **Added:** `docs/audits/mel-event-studio-ticket-save-publish-check.md`
- **No** application code or config changed.
