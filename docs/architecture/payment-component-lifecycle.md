# MEL Payment Component Lifecycle

**Status:** READ-ONLY Phase 2 launch documentation  
**Date:** 20 July 2026  
**Source of truth:** Phase 1 [`payment-runtime-map.md`](./payment-runtime-map.md) + Phase 2 DDEV runtime verification  
**Critical findings:** [`payment-critical-findings.md`](./payment-critical-findings.md)

Status values: `ACTIVE` · `ACTIVE (Platform)` · `ACTIVE (Commerce)` · `LEGACY` · `UNUSED` · `FUTURE` · `UNKNOWN`  
Decision values: `Keep` · `Keep until post-launch` · `Remove after launch` · `Product decision` · `Needs investigation`

---

## Payment gateways & plugins

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| Commerce Stripe Card Element gateway entity `stripe` | Config entity; plugin `Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\Stripe` | Primary customer card checkout (tickets, boost, donations observed) | ACTIVE (Commerce) | Keep | Keep as platform collect gateway for launch (Option A); document deploy key injection | Runtime entity enabled; 129 completed payments; ticket payments majority |
| Commerce Stripe Payment Element gateway entity `stripe_pe_recurring` | Config entity; plugin `StripePaymentElement` | Intended off-session / recurring; also used for tickets | ACTIVE (Commerce) | Product decision | Narrow conditions so tickets cannot casually select it; keep for Pro/recurring | Runtime `payment_method_usage=off_session`; 11 completed ticket payments on this gateway |
| Manual gateway entity `mel_stripe_cc` | Config entity; plugin `manual` | Test / offline completion | ACTIVE | Remove after launch (prod) / Keep (local) | **Disable in production** before public checkout | Runtime enabled, no currency condition; 8 completed ticket payments |
| Plugin `stripe_connect` | `myeventlane_commerce/.../StripeConnect.php` | Destination-charge Payment Element extension | FUTURE / UNUSED wiring | Keep until post-launch | Do not wire for launch without ADR Option B | Plugin discoverable; entities=0 (CF-001) |
| Plugin `stripe` (contrib) | `commerce_stripe` | Card Element PaymentIntents | ACTIVE (Commerce) | Keep | Maintain Commerce Stripe upgrades | Module enabled |
| Plugin `stripe_payment_element` (contrib) | `commerce_stripe` | Payment Element PaymentIntents | ACTIVE (Commerce) | Keep | Keep for PE / fast checkout / Pro | Module enabled; FastCheckout requires `StripePaymentElementInterface` |
| Plugin `manual` (contrib) | `commerce_payment` | Manual payment recording | ACTIVE (Commerce) | Keep until post-launch | Hide/disable entity outside local | Entity `mel_stripe_cc` |

---

## Stripe platform services

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `StripeService` (`myeventlane_core.stripe`) | `myeventlane_core/src/Service/StripeService.php` | Platform StripeClient facade: Connect accounts, off-session PI, SetupIntent, keys | ACTIVE (Platform) | Keep | Keep; treat unused methods as dormant | Service exists at runtime; callers: Connect, payouts, refunds verify, Pro portal, auto-bill |
| `StripeService::createPaymentIntentForTicketSale()` | Same | Direct ticket PI (bypasses Commerce) | UNUSED | Keep until post-launch | Do not call; do not delete yet | No PHP callers (CF-004) |
| `StripeService::createPaymentIntentForBoost()` | Same | Direct boost PI | UNUSED | Keep until post-launch | Do not call; do not delete yet | No PHP callers (CF-004) |
| `StripeService::getOrCreateAccount()` | Same | Alternate Connect account helper | UNUSED | Keep until post-launch | Prefer `ensureConnectAccountIdForStore()` | No external callers proven |
| `StripeConnectPaymentService` (`myeventlane_commerce.stripe_connect_payment`) | `myeventlane_commerce/src/Service/StripeConnectPaymentService.php` | Fee / `transfer_data` / Connect validation helpers | ACTIVE (partial) | Product decision | Keep calc/reporting; PI merge unused until Option B | Service exists; PI merge only via unwired plugin |
| `StripeConnectValidationSubscriber` | `myeventlane_commerce/.../StripeConnectValidationSubscriber.php` | Log Connect readiness on checkout completion | UNUSED | Needs investigation | Do not rely on for launch gate | Not in services.yml; not in COMPLETION listeners |

---

## Checkout & fees

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| Checkout flow `mel_event_checkout` | `commerce_checkout.commerce_checkout_flow.mel_event_checkout` | Ticket/boost/Pro initial checkout UX | ACTIVE | Keep | Keep | Order type `default` → this flow (runtime) |
| Checkout flow `default` | Commerce multistep | Donation checkouts | ACTIVE | Keep | Keep | `rsvp_donation` / `platform_donation` → `default` |
| `PlatformFeeOrderProcessor` | `myeventlane_commerce/.../PlatformFeeOrderProcessor.php` | Platform fee adjustments on refresh | ACTIVE | Keep | Keep | Service `myeventlane_commerce.platform_fee_order_processor` |
| `PlatformFeeOrderPresaveSubscriber` | `myeventlane_commerce/.../PlatformFeeOrderPresaveSubscriber.php` | Ensures fee refresh on save paths | ACTIVE | Keep | Keep | Tagged subscriber |
| `FastCheckoutEligibility` | `myeventlane_checkout_flow/.../FastCheckoutEligibility.php` | Express/fast checkout only with PE gateway | ACTIVE | Keep | Document PE dependency | Requires `StripePaymentElementInterface` |

---

## Vendor billing & donations

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `VendorAutoBillingService` | `myeventlane_donations/.../VendorAutoBillingService.php` | Off-session charge for MEL contribution invoices | ACTIVE (Platform) | Keep | Keep opt-in path; no default cron proven | Service exists; Drush/admin form callers |
| `VendorContributionInvoiceService` | `myeventlane_donations/.../VendorContributionInvoiceService.php` | Invoice generation for MEL % | ACTIVE | Keep | Keep | Service `myeventlane_donations.vendor_contribution_invoice` |
| `VendorMelPctContributionService` | `myeventlane_donations/.../VendorMelPctContributionService.php` | Accrue MEL % on paid ticket orders | ACTIVE | Keep | Keep | `ORDER_PAID` subscriber wired |
| `VendorBillingPreferencesService` | `myeventlane_donations/.../VendorBillingPreferencesService.php` | Customer + SetupIntent for card-on-file | ACTIVE | Keep | Keep | Service exists |
| `RsvpDonationService` (`myeventlane_donations.rsvp`) | donations module | RSVP donation order creation | ACTIVE | Keep | Keep | Order type `rsvp_donation` exists; payments on `stripe` |
| Platform donation service (`myeventlane_donations.platform`) | donations module | Platform donation orders | ACTIVE | Keep | Keep | Order type `platform_donation`; payments on `stripe` |
| `DonationPane` / `checkout_donation` | commerce/donations legacy UI | Legacy checkout donation pane | LEGACY | Keep until post-launch | Leave hidden (`isVisible()` FALSE per Phase 1) | Phase 1 map |
| Organiser donation via `field_mel_donation` | Ticket booking path | MEL donation adjustment on ticket order | ACTIVE | Keep | Keep | Phase 1: `TicketSelectionForm::applyMelDonationToOrder()` |

---

## MEL Pro & billing portal

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `ProSubscribeForm` | `myeventlane_pro/.../ProSubscribeForm.php` | Adds Pro variation to `default` cart | ACTIVE | Keep | Keep; expect PE gateway for off_session | Cart type `default` |
| `ProSubscriptionSubscriber` | `myeventlane_pro/.../ProSubscriptionSubscriber.php` | Subscription entity lifecycle → entitlements | ACTIVE | Keep | Keep | Kernel tests + module enabled |
| `ProBillingPortalService` (`myeventlane_pro.billing_portal`) | `myeventlane_pro/.../ProBillingPortalService.php` | Stripe Billing Portal via platform client | ACTIVE (Platform) | Keep | Keep | Service exists; uses `StripeService` |
| `ProSubscriptionWebhookController` | `myeventlane_pro` route `/stripe/webhook/subscription` | Audit log Stripe subscription/invoice events | ACTIVE (optional) | Keep | Document as non-authoritative; set secret in prod | Phase 1: no Commerce state mutation |

---

## Refunds

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `RefundProcessor` (`myeventlane_refunds.processor`) | `myeventlane_refunds/.../RefundProcessor.php` | Execute Commerce `refundPayment` + Stripe verify | ACTIVE | Keep | Keep | Service exists; buyer/vendor routes |
| Boost refund subscriber | `myeventlane_boost.refund_subscriber` | Boost entitlement on refund | ACTIVE | Keep | Keep | Runtime service id present |

---

## Vendor Connect onboarding & payouts

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `StripeConnectController` | `myeventlane_vendor/.../StripeConnectController.php` | Express Account Links onboarding | ACTIVE (Platform) | Keep | Keep | Routes `/stripe/connect`, `/stripe/callback` |
| `PayoutBatchWorkflowService` | `myeventlane_admin_dashboard/.../PayoutBatchWorkflowService.php` | Draft→approve→execute Transfers | ACTIVE (Platform) | Keep | Keep only after ledger scope fix (CF-007) | Transfer create code |
| `StripeWebhookController` (payout) | `/stripe/webhook/payout` | Reconcile `transfer.*` → ledger | ACTIVE (Platform) | Keep | Require webhook secret in staging/prod | Phase 1 webhook audit |
| `VendorStripePayoutService` | `myeventlane_stripe` | Vendor-facing payout/balance display | ACTIVE | Keep | Keep (display) | Service `myeventlane_stripe.vendor_payout` |
| `PlatformMetricsService` (`myeventlane_admin_dashboard.metrics`) | `.../PlatformMetricsService.php` | KPIs + **lazy ledger insert** | ACTIVE | Product decision | See ledger review — harden before payout launch | CF-006, CF-007 |

---

## Wallet

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `myeventlane_wallet` module | `web/modules/custom/myeventlane_wallet` | Apple/Google pass generation | ACTIVE | Keep | Keep; independent of Stripe charge path | Module enabled; no Stripe charge APIs in module |
| `PkPassBuilder` / `GoogleWalletBuilder` | wallet services | Build passes | ACTIVE | Keep | Keep | Services exist |
| `WalletDownloadAccessChecker` | wallet | Deny void/refunded/cancelled | ACTIVE | Keep | Keep | `isWalletBlockedStatus()` |
| Wallet links in confirmation email | `OrderConfirmationQueueBuilder` | Post-purchase CTAs | ACTIVE | Keep | Keep | Messaging service |

---

## Queues, workers, messaging

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| `OrderConfirmationQueueBuilder` | `myeventlane_messaging` | Build confirmation queue payloads (incl. wallet URLs) | ACTIVE | Keep | Keep | Used by place/paid subscribers |
| `MessagingQueueWorker` | `myeventlane_messaging/.../MessagingQueueWorker.php` | Send queued messages | ACTIVE | Keep | Keep | Queue worker plugin |
| `ReliableEmailQueueWorker` | `myeventlane_launch` | Launch reliable email | ACTIVE | Keep | Keep | Queue worker plugin |
| Domain event payment/refund subscribers | `myeventlane_domain_events` | Project payment domain events | ACTIVE | Keep | Keep | Runtime services present |
| Vendor auto-bill cron | — | Automatic invoice charging | UNKNOWN / not default | Needs investigation | Do not assume cron charges; use Drush/admin | No cron registration proven for auto-bill |

---

## Webhook controllers

| Component | Location | Purpose | Status | Decision | Launch Action | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| Payout Transfer webhook | `myeventlane_admin_dashboard` `/stripe/webhook/payout` | Ledger reconcile | ACTIVE | Keep | Configure secret | Phase 1 Stage 8 |
| Pro subscription webhook | `myeventlane_pro` `/stripe/webhook/subscription` | Audit only | ACTIVE (optional) | Keep | Document non-SoT | Phase 1 |
| Commerce Stripe payment webhook | Contrib / gateway config | Async payment events | UNKNOWN for MEL checkout | Needs investigation | Do not rely on for ticket capture; checkout is synchronous | Gateway webhook secrets empty in DDEV; no MEL public payment webhook route proven |

---

## Legend notes

- **ACTIVE (Platform)** = MEL custom Stripe API usage outside Commerce checkout charge path.  
- **ACTIVE (Commerce)** = Drupal Commerce / Commerce Stripe checkout path.  
- **UNUSED** = code present, no runtime wiring/callers proven.  
- **FUTURE** = intended architecture not wired.  
- Deletion requires the full dead-code checklist in [`payment-technical-debt.md`](./payment-technical-debt.md).
