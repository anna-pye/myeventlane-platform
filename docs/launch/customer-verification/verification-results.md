# Verification Results — PASS / FAIL / NOT TESTABLE

Status legend: **PASS** (verified true) · **FAIL** (verified defect) · **PARTIAL** (mixed) ·
**NOT TESTABLE** (no automated evidence possible here) · **VERIFY-LIVE** (needs staging/device pass).

## Results table

| ID | Item | Result | Severity (post-verify) | Evidence type |
| --- | --- | --- | --- | --- |
| CB-01 | Checkout paid-state messaging | **PASS (Commerce) + FAIL (wording)** | **P1** (down from P0) | runtime orders + code trace |
| CB-02 | Buyer refund guards | **PASS** | resolved | code + runtime 403 + DB |
| CB-03a | Stripe payout webhook (sig/replay/idempotency) | **PASS** | resolved | code trace |
| CB-03b | Actual payout money = settled-only | **PASS (architectural)** | VERIFY-LIVE | code + architecture |
| CB-03c | Vendor revenue **display** accuracy | **FAIL** | **P1** | runtime ($535.30 unsettled) + code |
| CB-04 | Canonical authoring path | **PASS** | resolved | code (gateway → Studio) |
| CB-05 | Saved Events | **PASS (repo false-negative corrected)** | resolved | live route /my-saved-events |
| CB-06 | Calendar | **PASS (render)** | VERIFY-LIVE (feed) | HTTP 200 + FullCalendar |
| CB-07 | WCAG critical path | **PARTIAL** | P1 | primitives PASS; full AA NOT TESTABLE |
| CB-08 | Transactional emails | **PASS** | resolved | mail keys + Mailpit sends |
| CB-09 | Pro entitlements | **PASS** | resolved | access `allowedIf(hasVendor && hasPro)` |
| CB-10 | Waitlist | **PASS (position)** | VERIFY-LIVE (signup copy) | JSON position API |
| CB-11 | Mobile booking CTA | **PASS (implemented)** | VERIFY-LIVE (device) | SCSS `position: sticky` + sticky-cta |
| CB-12 | Publishing workflow | **PASS** | VERIFY-LIVE (cross-surface) | single `editorial` workflow |
| CB-13 | Home skip link | **PASS** | resolved | rendered HTML "Skip to main" |

## Counts

- **PASS (fully resolved):** CB-02, CB-03a, CB-04, CB-05, CB-08, CB-09, CB-13 = **7**
- **PASS with VERIFY-LIVE remainder:** CB-03b, CB-06, CB-10, CB-11, CB-12 = **5**
- **FAIL (verified defect):** CB-03c (revenue display, P1); CB-01 wording (P1) = **2**
- **PARTIAL / NOT TESTABLE (automation):** CB-07 full WCAG AA = **1** (primitives pass)
- **P0 blockers remaining:** **0**

## Verified defects → targeted Cursor tasks

> These are the only two code changes the audit recommends. Both are small and ready to hand off.
> They were **not** auto-applied because they are outward-facing financial/copy + product decisions.

### TASK-A (P1) — Vendor revenue must reflect settled, refund-netted earnings
- **File:** `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php`
- **Method:** `buildVendorRevenueFromPublishedEventIds()`
- **Change:** replace the `$order->getState()->getId() === 'completed'` gate with a paid check (`$order->isPaid()`), and subtract refund attribution (reuse the refund-aware path already in this service, lines ~160–161 `getRefundAttributionCents()`).
- **Why:** 12/139 completed orders ($535.30) are unsettled and currently counted; refunds are not netted (no `refunded` order state in use).
- **Pre-req product decision:** do pending manual/invoice orders count as "sales"? (Confirm before changing vendor-visible figures.)
- **Validate:** recompute `getManagedVendorRevenue()` for a vendor; gross should drop by the unsettled + refunded portion.

### TASK-B (P1) — Completion copy must be payment-state-aware
- **Files:** `web/modules/custom/myeventlane_surface/src/MelCustomerContinuityPresenter.php` (`buildCheckoutCompletionPresentation` — add a paid flag param), `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` (add "order received — payment pending" variant), caller `web/themes/custom/myeventlane_theme/myeventlane_theme.theme:~1267`.
- **Change:** pass `$order->isPaid()` into the presenter; show "Booking confirmed" only when paid, else a pending-payment heading.
- **Config follow-up:** decide whether `mel_stripe_cc` (Manual gateway) should be enabled in production.
- **Validate:** complete checkout via the Manual gateway → completion page must not say "Booking confirmed".

## VERIFY-LIVE register (staging pass before sign-off)
- CB-03b — drive a real Stripe (test-mode) destination charge + `transfer.paid` webhook; reconcile Stripe ↔ ledger ↔ dashboard.
- CB-07 — axe-core + manual keyboard/screen-reader/contrast on event → book → checkout → login.
- CB-06 — confirm calendar event feed populates + nav linkage.
- CB-10 — waitlist join confirmation + promotion notification copy.
- CB-11 — on-device booking CTA (tap target, safe-area, scroll).
- CB-12 — confirm wizard + studio drive the same editorial states.
- CB-05 — confirm the save-event toggle UI on cards.
