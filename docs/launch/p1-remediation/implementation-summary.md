# P1 Remediation — Implementation Summary

**Branch:** `fix/mel-p1-financial-accuracy-checkout-copy`
**Date:** 2026-06-26
**Scope:** The two verified P1 issues from `docs/launch/customer-verification/`. No audit, no
broadening, no unrelated refactoring.

## Files changed (4 files, +54 / −6)

| File | Task | What |
| --- | --- | --- |
| `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php` | A | Net completed refunds into payouts revenue (reuse `getRefundAttributionCents()`); add `refunded`/`refunded_raw`. |
| `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` | B | Spec paid lead + new `customerCheckoutPendingPaymentHero()`. |
| `web/modules/custom/myeventlane_surface/src/MelCustomerContinuityPresenter.php` | B | Thread `bool $is_paid` into completion-hero resolution. |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | B | Pass `(bool) $order->isPaid()` to the presenter. |

Details: `task-a-financial-reporting.md`, `task-b-payment-copy.md`.

## Success-criteria check

| Criterion | Met |
| --- | --- |
| Vendor revenue reflects refund-adjusted earnings | ✅ (completed refunds netted; verified $179.88 deducted for uid=1) |
| Checkout messaging reflects Commerce payment state | ✅ (paid → "Booking confirmed"; pending → "Order received") |
| No Commerce payment behaviour change | ✅ |
| No Stripe logic change | ✅ |
| No payout/ledger logic change | ✅ |
| No duplicated business logic | ✅ (reused `getRefundAttributionCents()`; presenter receives a boolean) |
| Focused, reviewable diff | ✅ (4 files, +54/−6) |
| Scope discipline (STOP where business-logic) | ✅ (TASK A `isPaid()` gate deferred to product decision; documented) |

## Deliberate STOP (TASK A product rule)

The `completed` → `isPaid()` revenue-inclusion change was **not** applied. Both revenue methods in
the service consistently use `completed` state; switching only the payouts method would diverge it
from event analytics and invent an unstated business rule. The current production assumption is
preserved; a paired follow-up (both methods + Manual-gateway decision) is documented in
`task-a-financial-reporting.md`.

## Validation results

| Command | Result |
| --- | --- |
| `ddev composer validate` | `./composer.json is valid` |
| `ddev drush config:status` | `No differences between DB and sync directory` |
| `ddev drush cr` | clean |
| `phpunit MelReadinessHelperCustomerTest` (Unit) | **OK** — 3 tests, 22 assertions |
| `phpunit EventOverviewSalesSummaryTest` (Kernel) | **Could not execute** — pre-existing test-env error (config install: `commerce_store` table missing in setUp), unrelated to this change. Behaviour validated via runtime `drush ev` on production-like data instead. |
| `npm run build` / `npm run lint` | **Not required** — no SCSS/JS/Twig changed (verified via `git diff --name-only`). |
| `phpcs` (changed files) | Pre-existing debt only. Net new vs HEAD: TicketSalesService +1 warning; MelReadinessHelper +1 error; Presenter +2 errors — all doc-comment/line-length items consistent with the files' existing (heavily non-compliant: 189 errors in MelReadinessHelper at HEAD) style. The introduced >80-char comment lines were reflowed; remaining deltas are param-doc entries on already-partially-documented methods. Pre-existing debt left untouched (out of scope: "no unrelated refactoring"). |

## Runtime evidence (key proofs)

```
# TASK A — refund netting (real vendor)
uid=1  gross=$5,753.04  refunded=$179.88  fees=$86.30  net=$5,486.86   (pre-fix net would be $5,666.74)
uid=92 gross=$100.00    refunded=$0.00    net=$98.50                    (no-refund path unchanged)

# TASK B — payment-state-aware hero (real orders)
#552 isPaid=Y → "Booking confirmed" | "Confirmation and tickets have been sent."
#551 isPaid=N → "Order received"     | "We’re waiting for payment confirmation. …"
#550 isPaid=N → "Order received"     | "We’re waiting for payment confirmation. …"
free/paid     → "Booking confirmed"  (existing behaviour preserved)
```

## Not done (by design)

- No code committed/pushed (left on the feature branch for review).
- No Manual-gateway config change (payment config — requires product/ops decision).
- No `isPaid()` revenue gate (deferred to product decision; would need both methods together).
- No pre-existing phpcs debt cleanup (unrelated refactoring).
