# ADR-003 — Stripe Connect Strategy (Option A vs Option B)

**Status:** Historical recommendation; superseded by [ADR-004](./ADR-004-organiser-direct-charges.md)
**Date:** 20 July 2026  
**Related:** [`ADR-002-payment-runtime.md`](./ADR-002-payment-runtime.md), [`payment-critical-findings.md`](../architecture/payment-critical-findings.md)

> Product selected organiser direct charges on 20 August 2026. The Option A
> recommendation below is retained as historical audit evidence, not current
> implementation guidance.

---

## Context

MEL needs a clear marketplace money model before launch.

**Option A — Current wiring**  
Commerce Stripe Payment Element / Card Element charges the **platform** account.  
`StripeService` handles platform features (Connect Express onboarding, off-session vendor billing, Transfer payouts, portals, refund verify).  
Vendor net is paid later via Stripe **Transfers** + `myeventlane_payout_ledger`.

**Option B — True Connect destination charges**  
Commerce gateway entity with plugin `stripe_connect` merges `transfer_data` / `application_fee_amount` onto PaymentIntents at checkout (`StripeConnect` + `StripeConnectPaymentService`).  
Funds route to the connected account at charge time (minus application fee).

Runtime fact: **Option B code exists; Option B entity does not.** Checkout today is Option A.

---

## Comparison

| Dimension | Option A — Commerce Stripe + StripeService platform features | Option B — Destination-charge `stripe_connect` gateway |
| --- | --- | --- |
| **Complexity** | Checkout stays stock Commerce Stripe. Complexity lives in ledger, batch Transfers, fee rules, exclusions. | Checkout plugin must handle mixed carts, boost-only, donations, Connect readiness, refunds with Connect. |
| **Maintenance** | Tracks `commerce_stripe` upgrades with less custom PI code. Custom finance code remains MEL-owned. | Custom plugin must track Payment Element base class changes; higher upgrade cost. |
| **Drupal / Commerce compatibility** | Highest — entities `stripe` / `stripe_pe_recurring` already used. | Medium — extends contrib PE; must be registered as gateway entity and selected by conditions. |
| **Testing** | Test platform charge + Transfer separately; ledger correctness is the hard suite. | Need end-to-end Connect test mode accounts, fee math, mixed carts, refunds, failed transfers. |
| **Upgrade risk** | Commerce Stripe major upgrades mainly affect checkout panes/elements. | Base class / PI API shifts break MEL merge logic. |
| **Marketplace capabilities** | Delayed vendor payout; platform float; flexible hold/clawback; admin batch control. | Instant destination routing; Stripe-native split; less platform float; different refund/dispute posture. |
| **Long-term roadmap** | Good fit for controlled AU marketplace launch; can add destination charges later as a migration. | Better Stripe-native marketplace semantics; requires product commitment and retiring Transfer double-pay risk. |
| **Matches current DDEV/prod wiring** | **Yes** | **No** (0 entities) |
| **Launch readiness** | Closest — if ledger hardened and manual gateway disabled | **Not ready** — entity missing; validation unwired; refund/mixed-cart unproven |

---

## Evidence

| Fact | Source |
| --- | --- |
| `stripe_connect` plugin exists and merges Connect PI params | `StripeConnect.php` |
| Zero gateway entities use it | Phase 2 plugin manager: entities=0 |
| Ticket/boost/donation payments use `stripe` (and sometimes PE/manual) | `commerce_payment` history |
| Transfers implemented | `PayoutBatchWorkflowService` |
| Ledger lazy + unfiltered | `PlatformMetricsService::buildKpis`; CF-006/007 |
| Connect validation subscriber unwired | CF-003 |

---

## Recommendation

**Recommend Option A for launch**, with mandatory product/ops constraints:

1. Formally document platform-collect + Transfer as the marketplace model (this ADR + ADR-002).  
2. Treat plugin `stripe_connect` and PI merge as **FUTURE / dormant** — do not create the entity for launch.  
3. Before vendor payout go-live: fix ledger **timing** and **allowlist** (implementation post-audit).  
4. Disable `mel_stripe_cc` in production; narrow gateway conditions (ticket vs Pro).  
5. Keep Express onboarding via `StripeService`.  
6. Keep `StripeConnectPaymentService` for reporting/validation helpers until fee SoT is unified.  
7. Revisit Option B only after: gateway entity design, mixed-cart rules, refund policy, and an explicit plan to avoid double payout (destination + Transfer).

### Why not Option B for launch

- Not wired.  
- Validation would not fail closed even if the existing subscriber were registered.  
- Transfer tooling would become redundant or dangerous if left active.  
- No runtime proof of destination-charge checkout in this environment.

---

## Consequences if Product chooses Option B instead

Must complete before enabling:

1. Create Commerce payment gateway entity `plugin: stripe_connect` with AUD + product conditions.  
2. Retire or hard-block Card Element for vendor-revenue tickets.  
3. Disable Transfer batches for orders already destination-charged (or disable Transfers entirely).  
4. Wire fail-closed Connect readiness before payment.  
5. Full Connect refund/dispute test plan.  
6. New ADR superseding this recommendation.

---

## Decision log

| Date | Note |
| --- | --- |
| 2026-07-20 | Audit recommends Option A; awaiting Product ratification |
| 2026-07-20 | Launch remediation implements Option A constraints (gateway matrix, ledger insert allowlist, keep `stripe_connect` unwired). See `docs/release/payment-launch-remediation-report.md`. |
