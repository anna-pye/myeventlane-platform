# Task 6 — Booking and checkout verification

**Branch:** `cursor/onboard-storage-fix-128b4`  
**Latest commit (at audit):** `d59106d2` — fix(event-studio): update event status logic to handle publish conditions  
**Working tree:** clean (`git status --short` empty)

**Scope:** Diagnostic verification of RSVP and paid booking/checkout paths. No application code changes; no Stripe onboarding or gateway config changes; no config export; no secrets.

---

## Commands run

| Phase | Command | Result |
|-------|---------|--------|
| 1 | `git branch --show-current` | `cursor/onboard-storage-fix-128b4` |
| 1 | `git status --short` | *(empty)* |
| 1 | `git log -8 --oneline` | See branch tip above |
| 1 | `composer validate` | `./composer.json is valid` |
| 1 | `ddev drush cr` | Cache rebuild complete |
| 2 | Drush php-eval: last 20 events by `changed` | See § Test events |
| 3 | php-eval: node 1567 fields + product/variations | See § Paid event 1567 |
| 4 | `grep` across `web/modules/custom` (patterns from brief) | Wired commerce/checkout paths |
| 5 | `ddev drush ws --count=100 \| grep …` (RSVP/book keywords) | Mostly `mel_debug` BOOST CANDIDATE notices |
| 6 | `ddev drush ws --count=120 \| grep …` (broader) | No matches (exit 1) |
| 6 | php-eval: last 5 `commerce_order` entities | See § Latest orders |
| — | php-eval: published RSVP events with non-empty `field_ticket_types` | See RSVP picks |
| — | php-eval: CTA + tier counts for 1375/1377 | Confirms tier gaps |

---

## Test events used

| Role | NID | Title / notes |
|------|-----|----------------|
| **Paid** | **1567** | "Experience Anna Live" — `field_event_type` paid, published, `field_product_target` → product **90**, ticket type refs 88/89 |
| **RSVP (recommended for manual browser tests)** | **1540** | "New RSVP Test" — published, `field_ticket_types` count **1**, tier kind **rsvp** |
| Alternates | 1381, 1544 | Same pattern (published + RSVP tier) |
| **Avoid for RSVP E2E until fixed** | 1375, 1377 | `field_event_type` / CTA resolver → RSVP, but **`field_ticket_types` count = 0** |

---

## Paid event 1567 (data snapshot)

- **Published:** yes  
- **field_event_type:** `paid`  
- **field_ticket_types:** target_ids `88`, `89`  
- **field_product_target:** target_id `90`  
- **Product 90:** published  
- **Variations:**  
  - `4121` — "Full price entry" — published — **49.88 AUD**  
  - `4122` — "Full Price" — published — **50.00 AUD**  

Two live variations with similar labels and different prices: risks confusing buyers and duplicate-looking rows (classification **P2** UX/data hygiene), not a proven checkout failure by itself.

---

## Phase 4 — Code path answers

1. **`/event/{node}/book` for paid:** `BookController::book()` → `buildPaidForm()` builds `Drupal\myeventlane_commerce\Form\TicketSelectionForm` when CTA is paid, product exists, product published, and event not sold out (`BookController.php`).
2. **Filters purchasable variations:** `Drupal\myeventlane_commerce\Service\TicketAvailabilityService::filterPurchasableVariations()` (access codes, tier rules, caching).
3. **Creates cart / order items:** `TicketSelectionForm::submitForm()` — `CartProviderInterface::getCart` / `createCart`, then `CartManagerInterface::addEntity()` per variation; sets `field_target_event` on order items (`TicketSelectionForm.php`).
4. **Checkout flow:** Config ID **`mel_event_checkout`**, plugin `mel_event_checkout` → `MelEventCheckoutFlow` (single step `'checkout'` with sidebar). File: `config/sync/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`, class `MelEventCheckoutFlow.php`.
5. **Panes enabled (sync config):**  
   - Step `checkout`: `mel_buyer_details` (Buyer details), `ticket_holder_paragraph` (Attendee details), `mel_donation`, `mel_legal_consent`, `payment_information` (Payment; `require_payment_method: false`).  
   - Sidebar: `order_summary` (view `commerce_checkout_order_summary`).  
   - Several panes on `_disabled` (grouped summary, fee transparency as configured).
6. **Ticket holder capture:** Commerce checkout pane `ticket_holder_paragraph` → `Drupal\myeventlane_checkout_paragraph\Plugin\Commerce\CheckoutPane\TicketHolderParagraphPane` (`TicketHolderParagraphPane.php`), persists `field_ticket_holder` on order items; downstream `OrderCompletedSubscriber` syncs attendees.
7. **Confirmation / completion:** Commerce checkout complete uses theme override `web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-completion.html.twig` (MEL confirmation layout: paid vs free copy, order reference, links to account).
8. **Account / tickets after purchase:** Routes `myeventlane_checkout_flow.my_tickets` and `myeventlane_checkout_flow.order_detail` (`MyTicketsController.php`, Twig `myeventlane-my-tickets.html.twig`, `myeventlane-order-detail.html.twig`). Confirmation template also links to `entity.commerce_order.user_view` and “View my tickets”.

**RSVP-only book path:** `BookController::buildRsvpOnlyForm()` → `Drupal\myeventlane_rsvp\Form\RsvpPublicForm` when `melEventHasRsvp()` is true (requires at least one `field_ticket_types` tier with `ticket_kind === 'rsvp'`). If tiers are missing, user sees “RSVP is not yet available for this event.”

---

## RSVP flow result (automated + code review)

**Browser/manual:** Not executed in this session (no automated browser).  

**Findings:**

- Valid RSVP test events with tiers: **1540**, **1381**, **1544**.  
- Events **1375** / **1377** are **`field_event_type` = rsvp** (per listing query) and **`EventCtaResolver::getCtaType` = `rsvp`**, but **`field_ticket_types` is empty**.  
  - **Effect:** Event page can still advertise RSVP (`EventCtaResolver` uses `EventModeManager::MODE_RSVP`), while `/book` runs `melEventHasRsvp()` → **FALSE** → **“RSVP is not yet available”** (`BookController::buildRsvpOnlyForm`).  
  - **Classification:** **P1** — CTA/book-page mismatch and confusing UX until content is fixed or CTA is gated on tier presence.

**Watchdog (RSVP-focused grep):** Recent noise was predominantly `mel_debug` BOOST CANDIDATE notices; no RSVP/checkout errors surfaced in the filtered sample.

---

## Paid flow result (automated + code review)

**Browser/manual:** Not executed in this session.

**Findings:**

- **1567** is suitable as a paid test event (published product, two variations).  
- **Existing orders** show completed paid flows have occurred (see below). Draft order **423** contains **two** line items (`626`, `627`) for variations **4121** and **4122** — consistent with selecting both tiers, not deduping rows across tiers (expected if both are separate variations).  
- **Stripe charge-ready vs customer checkout:** `PaidPublishStripeGate` blocks **publishing** paid events when the vendor store is not charge-ready; no separate subscriber was identified in this pass that blocks **cart add** by the same gate—operational expectation is **paid live events should not exist** for non-ready vendors if publish rules are enforced.  
- **Watchdog (broader grep):** Empty result set in this environment (no matching lines in last 120 entries).

---

## Checkout panes observed (from config + code)

Documented in § Phase 4 — single-page checkout step `checkout` with buyer → attendee → donation → legal → payment; sidebar order summary.

---

## Latest orders summary (sanitized)

From `commerce_order` query (last 5 by `changed_desc`), **no personal emails printed**:

| Order ID | State | Total | Customer uid | Items (summary) |
|----------|-------|-------|--------------|-----------------|
| 424 | draft | *(empty)* | 1 | — |
| 423 | draft | 101.38 AUD | 1 | item 626: variation **4121** qty 1; item 627: variation **4122** qty 1 |
| 428 | completed | 50.63 AUD | 72 | item 628: variation **4121** qty 1 |
| 427 | draft | *(empty)* | 72 | — |
| 426 | completed | 50.75 AUD | 1 | item 624: variation **2111** qty 1 |

---

## Edge-case checklist (inspection / partial)

| # | Check | Result |
|---|--------|--------|
| 1 | Sold out | `BookController` / `TicketSelectionForm` use capacity service for sold-out messaging |
| 2 | Quantity vs capacity | `validateForm` / `submitForm` + `TicketAvailabilityService` assertions |
| 3 | Private / access code | `TicketSelectionForm` access code field + `TicketAvailabilityService` grant resolution |
| 4 | RSVP-only vs paid | `BookController` switch on `EventCtaResolver`; mutual exclusivity documented in `EventCtaResolver` |
| 5 | Stripe not charge-ready | **Publish:** `PaidPublishStripeGate`; **customer:** relies on publish + sale rules — confirm in staging if any draft/contradictory state exists |
| 6 | Duplicate variation rows | Product **90** has **two** distinct variations — UI shows two rows; **not** the same variation duplicated unless data issue |
| 7 | Vendor/store association | Ticket submit uses product’s stores for cart; verify per-environment vendor store linkage outside this doc |

---

## P0 / P1 / P2

### P0 (none confirmed)

No customer-facing **fatal** failure or blocked cart/checkout was **reproduced** in this diagnostic (browser flows not run). Re-run P0 classification after manual RSVP + paid browser runs if regressions appear.

### P1

1. **RSVP CTA vs empty tiers:** Events in RSVP mode with **no** `field_ticket_types` tiers can still get RSVP CTAs while `/book` shows **RSVP not yet available** — confusing and blocks completion until content or resolver alignment.

### P2

1. **Duplicate-looking paid tiers on 1567:** Two variations with near-duplicate labels and different prices (49.88 vs 50.00 AUD).  
2. **Watchdog noise:** `mel_debug` BOOST lines dominate filtered logs; consider log level / volume for production signal.

---

## Recommended next task

- **If manual browser passes with no new P0/P1:** Proceed to **Task 7 — Vendor dashboard and attendee/order visibility verification** (per brief).  
- **If P0/P1 reproduced in browser:** Narrow **Task 6B** — e.g. align RSVP **CTA/disabled state** with `melEventHasRsvp()` / tier presence, or document editorial rule “RSVP mode requires ≥1 RSVP tier”; optionally clean duplicate/conflicting variations on test events.

---

## Files changed

- `docs/audits/mel-booking-checkout-verification.md` — **created** (this audit).

---

## Assumptions

- `ddev` environment reflects the same Drupal database the branch expects.  
- Manual browser verification on **1540** (RSVP) and **1567** (paid) is still required to claim full Task 6 acceptance.
