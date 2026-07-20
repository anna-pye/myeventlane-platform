# MEL Payment Runtime Matrix

**Status:** Phase 2 audit + launch remediation update  
**Date:** 20 July 2026  
**Evidence:** DDEV entity/payment history + Phase 1 runtime map + remediation runtime probes  
**Critical:** [`payment-critical-findings.md`](./payment-critical-findings.md)  
**Remediation:** [`../release/payment-launch-remediation-report.md`](../release/payment-launch-remediation-report.md)

---

## Matrix

| Payment Type | Order Type | Checkout Flow | Gateway (observed / applicable) | Stripe Object | Payment Method | Webhook | Confirmation | Wallet | Queue Worker | Cron | Ledger | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ticket | `default` | `mel_event_checkout` | **Typical:** `stripe` (Card Element). **Also observed:** `stripe_pe_recurring`, `mel_stripe_cc`. **Applicable on AUD draft:** all three | PaymentIntent (Commerce Stripe) | `credit_card` or `stripe_card` | No MEL ticket webhook proven; capture synchronous in checkout | `OrderPlacedSubscriber` → `OrderConfirmationQueueBuilder` | Post-pay Apple/Google CTA/download | Messaging queue | Not required for charge | Lazy KPI insert (includes tickets) | ACTIVE — gateway ambiguity (CF-008) |
| Boost | `default` (boost order item) | `mel_event_checkout` | Observed: `stripe`. Applicable: all AUD gateways | PaymentIntent (Commerce) | `credit_card` typical | Same as ticket | Boost-specific template via place subscriber | N/A (not a ticket pass) | Messaging | Not required | **Incorrectly eligible** for ledger rows (16 orders) | ACTIVE charge; ledger scope risk |
| MEL Pro | `default` initial; renewals `recurring` | Initial: `mel_event_checkout`; renewals: none | Observed initial: `stripe_pe_recurring` (and once `mel_stripe_cc`). Applicable: all AUD | PaymentIntent / Setup for off_session; Commerce Recurring owns renewals | `stripe_card` intended | `/stripe/webhook/subscription` audit-only | Commerce/messaging; Pro subscriber entitlements | N/A | Messaging / Recurring internals | Commerce Recurring schedule (module enabled) | **Incorrectly eligible** (3 Pro variation orders in ledger) | ACTIVE; gateway not forced |
| Platform Donation | `platform_donation` | `default` | Observed: `stripe`. Applicable: all AUD + manual | PaymentIntent (Commerce) | `credit_card` | None MEL-specific proven | `VendorWizardPlatformDonationSubscriber` on `ORDER_PAID` | N/A | Messaging as applicable | No | **In ledger as unpaid liability** (CF-007) | ACTIVE charge; ledger wrong |
| RSVP Donation | `rsvp_donation` | `default` | Observed: `stripe` | PaymentIntent (Commerce) | `credit_card` | None MEL-specific | `RsvpDonationConfirmationSubscriber` on `ORDER_PAID` | N/A | Messaging | No | **In ledger as unpaid liability** (CF-007) | ACTIVE charge; ledger wrong |
| Vendor Contribution Invoice | Custom invoice entity/table (MEL %) — not standard ticket order | N/A (admin/Drush + vendor billing UX) | Not Commerce checkout; off-session via `StripeService` | PaymentIntent off-session; SetupIntent for PM save | Saved Stripe PM | None for charge success path proven | Invoice status update in donations services | N/A | None proven for charge | **Not proven** on cron; Drush/admin | Separate contribution tables — not `myeventlane_payout_ledger` | ACTIVE when opted in |
| Vendor Auto Billing | Same invoice path | N/A | `VendorAutoBillingService` → `createPaymentIntentOffSession` | PaymentIntent `off_session` | Saved PM | None proven | Invoice apply on success | N/A | None proven | Not proven default cron | N/A (invoice domain) | ACTIVE (manual/Drush trigger) |
| Refund | Original order type | N/A (post-purchase) | Gateway of original payment (`refundPayment`) | Refund (Commerce Stripe + verify via platform client) | N/A | None required for sync refund | Messaging / refund notifications | Download denied if ticket `refunded`/`void`/`cancelled` | Refund retry queue path exists | No | Ledger payout interaction **Not proven** on refund | ACTIVE |
| Vendor Onboarding | N/A (store fields) | N/A | N/A (not a Commerce payment) | Connect Express Account + AccountLink | N/A | None for AccountLink return (callback route) | Store fields updated on callback | N/A | No | No | N/A | ACTIVE |
| Vendor Payout | N/A (ledger rows) | N/A | N/A | Transfer to connected account | N/A | `/stripe/webhook/payout` (`transfer.paid/failed/created`) | Admin UI / ledger status | N/A | No | No (admin batch) | `myeventlane_payout_ledger` | ACTIVE path; **blocked for safe launch** until CF-006/007 resolved |

---

## Gateway selection rules (summary)

1. `PaymentGatewayStorage::loadMultipleForOrder($order)` evaluates gateway **conditions**.
2. MEL `FilterPaymentGatewaysSubscriber` then applies the launch matrix (customers: tickets/boost/donations → `stripe`; Pro/recurring → `stripe_pe_recurring`; manual → administrators only).
3. `stripe` requires currency **AUD**.
4. `stripe_pe_recurring` requires AUD **and** Pro variation type (config); filter also scopes PE / removes Card Element for Pro.
5. `mel_stripe_cc` requires administrator role (config + filter).
6. UI further splits by payment method type (`credit_card` vs `stripe_card`).
7. Fast checkout requires a Payment Element plugin instance — **not available on ticket carts after remediation** (expected).

---

## Status key

| Status | Meaning |
| --- | --- |
| ACTIVE | Proven runtime path |
| ACTIVE — caveats | Works but has architecture risks called out |
| ACTIVE path; blocked… | Code path exists; launch ops unsafe until findings resolved |

---

## Evidence anchors

- Order types / checkout flows: runtime `commerce_order_type` load (Phase 2).
- Historical gateways: `commerce_payment` aggregates (Phase 2).
- Draft applicability: `loadMultipleForOrder` on draft carts 580/569/554 (Phase 2).
- Ledger composition: SQL join ledger ↔ order type (Phase 2) — CF-007.
- Wallet decoupling: no Stripe charge APIs under `myeventlane_wallet` (Phase 1/2).
