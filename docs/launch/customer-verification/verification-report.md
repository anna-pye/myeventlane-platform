# MyEventLane — Live Acceptance Verification Report (P0/P1)

**Type:** Live verification audit. Goal: convert repository-audit *assumptions* into *facts* against the running application.
**Date:** 2026-06-26
**Branch:** `fix/branding-hero-upload-ux`
**Environment:** DDEV `myeventlane` — Drupal 11 / PHP 8.3 / MariaDB 10.11, bootstrap **Successful**; Mailpit active.
**Code changes made this session:** **None.** Every candidate fix is outward-facing financial reporting or product copy (see §"Fix policy outcome"). `git status` shows only `docs/launch/`.

Source of truth (unchanged): `docs/launch/customer-acceptance/`. This report does **not** modify those documents.

> Evidence rule honoured: no conclusion is marked PASS without runtime and/or code evidence.
> Where neither could be produced, the item is marked **NOT TESTABLE** or **VERIFY-LIVE**.
> Validation commands were run; results are in §Validation. No "tests passed" claim is made beyond what was executed.

---

## Headline outcome

| Original P0 | Verified outcome | Net |
| --- | --- | --- |
| CB-01 paid-state binding | Commerce behaves correctly (tickets gated on `OrderEvents::ORDER_PAID`; pending-payment orders hold 0 tickets). Only the completion **wording** is not payment-state-aware. | **Downgraded P0 → P1 (wording)** per the audit's own classification rule |
| CB-02 refund guards | All protections present and verified (ownership, duplicate, anti-tamper, already-refunded exclusion, cap-to-paid, anon 403). | **PASS** |
| CB-03 payout + webhook | Webhook signature/replay/idempotency **PASS**. Actual payouts are Stripe-charge-driven (settled-only) — architectural PASS, VERIFY-LIVE. Vendor revenue **display** overstates (includes $535.30 unsettled + un-netted refunds). | **Webhook PASS; revenue display FAIL → P1** |

**Net P0 blockers remaining: 0.** Two P1 financial-accuracy/wording items remain. Several VERIFY-LIVE items (live Stripe transfer reconciliation, full WCAG AA, on-device booking) require a staging pass.

---

## P0 verification detail

### CB-01 — Checkout messaging vs real payment state

**Requirement:** Completion page must not assert payment is complete before Commerce considers it complete.

**Test steps / evidence:**
1. Traced `mel_confirm_paid` → set in `myeventlane_theme.theme:1218` as `$total_price instanceof Price && !$total_price->isZero()` — this is "order is non-free", **not** "payment settled". It is only `|default(false)` in the template and never gates hero copy (1 occurrence).
2. Traced hero copy → `MelCustomerContinuityPresenter::buildCheckoutCompletionPresentation()` receives **no payment/order-state argument**; heading resolves to `MelReadinessHelper::customerCheckoutCompletionHeadline()` = **"Booking confirmed"**, lead = "Your tickets and receipt are on their way to your inbox." Copy varies only by `has_tickets`/`donation`, never by payment.
3. Runtime order data:
   ```
   #551 state=completed placed=Y pay=[pending/49.00]
   #550 state=completed placed=Y pay=[pending/50.75]
   #552 state=completed placed=Y pay=[completed/49.00]
   ```
   → real completed orders exist with **pending** payment; those buyers saw "Booking confirmed".
4. Enabled gateways: `mel_stripe_cc` (**plugin=manual**, label "MEL - Manual"), `stripe`, `stripe_pe_recurring`. The manual gateway places orders without capturing payment.
5. **Commerce correctness check:** ticket issuance is via `myeventlane_tickets/EventSubscriber/OrderPaidSubscriber` subscribed to `OrderEvents::ORDER_PAID`. Pending orders #550/#551 hold **0** `myeventlane_ticket` rows.

**Expected:** wording reflects payment state. **Actual:** wording reflects *placement*, not payment state; but tickets/fulfilment are correctly gated on payment.

**Result:** **PASS (Commerce correctness)** + **FAIL (wording) → P1**, per the rule "if wording is inaccurate but Commerce behaves correctly, classify as P1 UX wording."

**Root cause:** Theme/presenter business logic (completion copy is not payment-state-aware) + **config**: a `manual` payment gateway is enabled in a production-like environment, allowing order completion with pending payment.

**Recommendation (do NOT auto-apply — outward-facing copy + payment config):**
- Pass payment state into `buildCheckoutCompletionPresentation()` and branch copy: "Booking confirmed" only when `$order->isPaid()`; otherwise "Order received — payment pending".
- Confirm whether the `mel_stripe_cc` Manual gateway should be enabled in production; if it is test-only, disable it.

---

### CB-02 — Buyer refund validation

**Requirement:** Enforce ownership, max amount, duplicate, post-refund, partial, invalid IDs, direct-URL, tamper.

**Evidence (code + runtime):**
- Route `myeventlane_refunds.buyer_refund`: `_entity_access: commerce_order.view` **and** `_custom_access: BuyerRefundAccessCheck` **and** `commerce_order: \d+`.
- `BuyerRefundAccessCheck`: anonymous → forbidden; requires `?event=`; delegates to `BuyerRefundEligibilityService::isEligible()`.
- `isEligible()`: `buyerOwnsOrder` (customerId === account id), refundable state (completed/fulfilled/placed), order-contains-event-items, policy allows, within window.
- `RefundProcessor::requestBuyerRefund()`: re-checks eligibility (defence-in-depth); **duplicate guard** `hasActiveBuyerRequest()` blocks while status ∈ {requested, approved}; amount computed **server-side**, `amount > 0` enforced.
- `RefundOrderInspector::calculateSelectedAttendeeRefundCents()`: throws `InvalidArgumentException` if any selected attendee ID is not in this order/event's refundable breakdown → **anti-tamper**.
- Refundable-attendee loader **excludes cancelled/refunded** attendees → completed-refund tickets cannot be re-selected (no double refund).
- Remaining-refundable computed as `totalPaid − totalRefunded` (floored at 0) against real `commerce_payment` rows → cannot exceed paid.
- **Runtime:** anonymous `GET /my-tickets/order/552/refund?event=1` → **HTTP 403**. `myeventlane_refund_request` lifecycle: `completed=25, rejected=8, approved=4, requested=3`.

**Result:** **PASS.** **Root cause:** n/a.

---

### CB-03 — Vendor payout validation

**Requirement:** Only settled Stripe payments included; pending/refunded/cancelled excluded; webhook signature + replay + reconciliation.

**Webhook (`StripeWebhookController::handle`) — PASS:**
- Signature: `Webhook::constructEvent($payload, $sigHeader, $webhookSecret)` (official Stripe HMAC). Bad signature → **400 "Invalid signature"**. Missing secret → **500** (fail-closed). Invalid payload → 400.
- Idempotency/replay: `transfer.paid` on an already-`paid` ledger row with the **same** transfer → idempotent skip; **different** transfer → `critical` log, **no overwrite** (double-pay safe).
- `transfer.failed` → logs error, **does not** change ledger status.
- Scope: "Never modifies commerce_order or payment entities — only the payout ledger."

**Actual payout money — architectural PASS / VERIFY-LIVE:** `pending_payout` is hardcoded `$0.00` ("Would come from Stripe API"); real money moves via Stripe Connect destination charges + the webhook-reconciled `myeventlane_payout_ledger`. Pending/manual orders create no Stripe charge → no transfer. Not exercised with a live Stripe transfer here → **VERIFY-LIVE**.

**Vendor revenue DISPLAY — FAIL (P1):**
- `VendorPayoutsController::payouts()` → `TicketSalesService::getManagedVendorRevenue()` → `buildVendorRevenueFromPublishedEventIds()` sums ticket-item totals filtered on **`$order->getState() === 'completed'`** only — never `isPaid()`, and with **no refund deduction**.
- Runtime impact: of **139** completed orders, **12 are NOT fully paid = $535.30** counted as vendor gross/net. Order states are only `completed`/`draft` (no `refunded` state), so the **25 completed refunds are not netted** from this figure. A *different* method in the same service (lines 160–161) *is* refund-aware — the payouts page just doesn't use it.

**Result:** **Webhook PASS; payout money architectural PASS (VERIFY-LIVE); revenue display FAIL → P1.**
**Root cause:** Custom module business logic — `TicketSalesService::buildVendorRevenueFromPublishedEventIds()` keys on order *state* instead of `OrderInterface::isPaid()` and omits the refund attribution available elsewhere in the same service.

**Recommendation (do NOT auto-apply — outward-facing vendor earnings + "what counts as sales" product decision):**
- Gate the sum on `$order->isPaid()` (or `getTotalPaid >= getTotalPrice`).
- Subtract refund attribution (reuse `getRefundAttributionCents()`).
- Decide product-side whether manual/invoice (pending) orders should count as "sales".

---

## P1 verification detail

| Item | Result | Evidence | Root cause / note |
| --- | --- | --- | --- |
| **CB-04** canonical authoring | **PASS** | `/create-event` (`CreateEventGatewayController`) funnels authenticated organisers to `myeventlane_event_studio.edit`; onboarding redirect if no vendor. | Legacy `build/*` wizard + console routes still exist — schedule deprecation audit. |
| **CB-05** Saved Events | **PASS — repo-audit false negative corrected** | Live route `view.mel_saved_events.page_1` at `/my-saved-events`; `EventSaveCountService` present. | Repo audit said "not found"; the View-based feature exists. Confirm the save-toggle UI on cards (VERIFY-LIVE). |
| **CB-06** calendar | **PASS (renders) / VERIFY-LIVE (feed)** | `GET /calendar` → HTTP 200, 81 KB, FullCalendar widget + `mel-calendar`. | Confirm the AJAX event feed populates and nav links to it. |
| **CB-07** WCAG critical path | **PARTIAL: primitives PASS / full AA NOT TESTABLE** | Login page: `lang="en"`, "Skip to main", `role=`, `<label>×2`. Theme: 124/236 templates carry a11y primitives. | Contrast, keyboard traps, screen-reader flow need axe-core + manual pass. |
| **CB-08** transactional emails | **PASS** | Registered keys incl. `order_confirmation, order_invoice, refund_requested_buyer/vendor, refund_approved_buyer, rsvp_confirmation, event_reminder, event_cancelled, cart_abandoned, boost_*`. Mailpit: 19 messages incl. "Your order is confirmed", "Tax invoice – Order #". | Password reset template exists. Confirm reminder/refund sends on a live order (VERIFY-LIVE). |
| **CB-09** Pro entitlements | **PASS** | Pro manage/settings routes gated by `_custom_access: ProOverviewController::accessProVendor` → `AccessResult::allowedIf($hasVendor && $hasPro)`; `ProEntitlementManager` + `ProSubscriptionStatusService`. Overview stays open for upsell (correct). | — |
| **CB-10** waitlist | **PASS (position) / VERIFY-LIVE (signup copy)** | `WaitlistController` returns JSON position; `waitlist_signup` route present. | Confirm join confirmation + promotion notification copy live. |
| **CB-11** mobile booking | **PASS (implemented) / VERIFY-LIVE (device)** | `_event-book.scss:977 position: sticky`; `mel-event-sticky-cta--sidebar` component in `_event-full.scss`. | Tap-target size, safe-area insets, scroll behaviour need on-device check. |
| **CB-12** publishing workflow | **PASS / VERIFY-LIVE (cross-surface)** | Single `editorial` content-moderation workflow (no conflicting models). Studio `submit-review` + wizard `publish`. | Confirm both authoring surfaces drive the same editorial states. |
| **CB-13** skip link | **PASS** | Rendered `/home` contains "Skip to main". | — |

---

## Fix policy outcome

No code was changed. Each candidate fix is **outward-facing money/copy or a product decision**, where this project's operating rules require confirmation before acting:

- **CB-03 revenue display** — changes vendor-visible earnings; "do pending/manual orders count as sales?" is a product call. Documented with an exact, minimal patch path.
- **CB-01 wording** — customer-facing payment copy + payment-gateway config; requires copy approval and a config decision on the Manual gateway.

Both are small, well-scoped, and ready to hand to Cursor as targeted tasks (see `verification-results.md`).

---

## Validation

| Command | Result |
| --- | --- |
| `ddev drush status` (bootstrap) | **Successful** |
| `ddev composer validate` | `./composer.json is valid` |
| `ddev drush config:status` | `No differences between DB and sync directory` |
| `git status` | only `docs/launch/` untracked (no code changes) |

`npm run lint` / `npm run build` not run — no theme/SCSS changes were made.
