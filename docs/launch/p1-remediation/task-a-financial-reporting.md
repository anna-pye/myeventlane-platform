# TASK A — Vendor Revenue Accuracy

**Status:** Implemented (reporting-only change). **Scope guard honoured:** the ambiguous
`completed` → `isPaid()` question was **NOT** changed — see "Product rule outcome".

## Verified finding (source: customer-verification)

`TicketSalesService::buildVendorRevenueFromPublishedEventIds()` — the method behind the vendor
**Payouts** page totals — summed ticket-item gross for orders in `state === 'completed'` and
**did not subtract completed refunds**. Runtime impact measured during verification: of 139
completed orders, 12 were not fully paid ($535.30) and 25 completed refunds were not netted.

## What changed

| File | Change |
| --- | --- |
| `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php` | `buildVendorRevenueFromPublishedEventIds()` now nets **completed refunds** by reusing the existing `getRefundAttributionCents()` (the same attribution used by the per-event `getSalesSummaryForEventId()`). `net = max(0, gross − fees − refunds)`. Adds `refunded` / `refunded_raw` to the returned summary and to `emptyVendorRevenueSummary()` for shape parity. |

- **No new refund logic** — refund cents come from the existing `getRefundAttributionCents()`
  (completed refunds, completed orders, per-event, currency-scoped). DRY preserved.
- Currency is captured from order items during the gross loop (default `AUD`) and passed to
  the attribution call, mirroring `getSalesSummaryForEventId()`.

## Product rule outcome (STOP recorded)

> "Should pending manual or invoice orders contribute to vendor revenue? … If the repository
> cannot answer this, STOP. Document the ambiguity. Use the current production assumption. Do
> not invent business rules."

**Repository evidence:** *both* revenue methods in `TicketSalesService` —
`buildVendorRevenueFromPublishedEventIds()` (payouts) and `getSalesSummaryForEventId()`
(event overview/analytics) — gate gross on `$order->getState()->getId() === 'completed'`.
The repository therefore has a **consistent current production assumption**: completed-state
orders define revenue inclusion. It does **not** establish that pending manual/invoice orders
should be excluded.

**Decision:** The `completed` → `isPaid()` change was **NOT** applied, because:
1. It would make the payouts figure diverge from event-level analytics (a regression the task
   explicitly forbids: "Vendor dashboard totals … No regression").
2. It would invent a business rule the repository does not state.
3. It depends on an unresolved product question — is the enabled `mel_stripe_cc` **Manual**
   gateway a legitimate production revenue channel (invoiced/box-office) or test-only?

**Recommended follow-up (product decision required, not implemented here):** if pending/manual
orders must be excluded from revenue, change the gate to `$order->isPaid()` in **both**
`buildVendorRevenueFromPublishedEventIds()` **and** `getSalesSummaryForEventId()` together, so the
payouts page and event analytics stay consistent. Pair with a decision on the Manual gateway.

## Risk assessment

- **Reporting only.** No change to payout ledger, Stripe transfers, payment entities, or order
  lifecycle. The Payouts page already hardcodes `pending_payout = $0.00` and links out to Stripe;
  actual money movement is unaffected.
- Net is floored at 0 (matches the per-event method's `max(0, …)`), so heavy refunds cannot show
  negative earnings.
- Return-array additions are additive; existing keys (`gross`, `net`, `fees`, `gross_raw`,
  `tickets`) are unchanged, so the `myeventlane_vendor_payouts` theme consuming them is unaffected.

## Validation performed

| Check | Result |
| --- | --- |
| Runtime `getManagedVendorRevenue()` (real data) | uid=1: gross **$5,753.04**, refunded **$179.88**, fees $86.30, net **$5,486.86** (= 5753.04 − 86.30 − 179.88 ✓). Pre-change net would have been $5,666.74. |
| uid=92 (no refunds) | gross $100.00, refunded $0.00, net $98.50 — unchanged behaviour where no refunds exist ✓ |
| `composer validate` | valid |
| `drush config:status` | no drift |
| Unit test `MelReadinessHelperCustomerTest` | 3 tests, 22 assertions — **OK** |
| Kernel test `EventOverviewSalesSummaryTest` | could not execute — **pre-existing** test-env error (config install: `commerce_store` table missing during setUp), unrelated to this change. Path validated via runtime eval above. |

## Before / after behaviour

- **Before:** Payouts net = gross − fees. Completed refunds ignored → overstated earnings.
- **After:** Payouts net = gross − fees − completed refunds; refunded total surfaced separately.
- **Unchanged:** gross still counts completed-state orders (production assumption preserved).
