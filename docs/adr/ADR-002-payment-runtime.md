# ADR-002 — MEL Payment Runtime Architecture (Current)

**Status:** Accepted as documentation of **current** runtime (not aspirational)  
**Date:** 20 July 2026  
**Deciders:** Audit Phase 1–2 (Engineering documentation)  
**Evidence:** DDEV runtime inspection + repository map  
**Related:** [`payment-critical-findings.md`](../architecture/payment-critical-findings.md), [`payment-runtime-map.md`](../architecture/payment-runtime-map.md), [`ADR-003-stripe-connect-strategy.md`](./ADR-003-stripe-connect-strategy.md)

---

## Context

MEL is a Drupal 11 + Commerce 3 marketplace. Code contains both:

1. A custom Stripe Connect destination-charge gateway plugin, and  
2. A platform-collect + Transfer/ledger payout system.

Only one of these is wired for customer checkout.

---

## Decision (describe current architecture)

**MEL currently operates a platform-collect Commerce Stripe checkout with separate Connect Express onboarding and admin-driven Stripe Transfers for vendor payouts.**

### Customer charging

| Concern | Current runtime |
| --- | --- |
| Primary gateway | Entity `stripe`, plugin `stripe` (Card Element) |
| Secondary Stripe gateway | Entity `stripe_pe_recurring`, plugin `stripe_payment_element`, `off_session` |
| Test/manual gateway | Entity `mel_stripe_cc`, plugin `manual` (enabled; no currency condition) |
| Custom Connect PE gateway | Plugin `stripe_connect` **discoverable**, **0 entities** |
| Checkout flow (tickets/boost/Pro initial) | Order type `default` → `mel_event_checkout` |
| Donation checkouts | `rsvp_donation` / `platform_donation` → flow `default` |
| Gateway filtering | Currency AUD for `stripe`; Pro variation + AUD for `stripe_pe_recurring`; admin role for `mel_stripe_cc`; MEL `FilterPaymentGatewaysSubscriber` enforces launch matrix (remediation 20 Jul 2026) |

### Platform Stripe features (non-checkout)

| Concern | Current runtime |
| --- | --- |
| Facade | `myeventlane_core.stripe` (`StripeService`) |
| Vendor onboarding | Connect Express Account Links via `StripeConnectController` |
| Vendor contribution auto-bill | Off-session PaymentIntent via `VendorAutoBillingService` |
| Pro billing portal | `ProBillingPortalService` |
| Refund verification | `RefundProcessor` + platform client |
| Payouts | `transfers.create` to connected accounts |

### Vendor money movement

| Concern | Current runtime |
| --- | --- |
| Model | Platform balance → Transfer |
| Liability table | `myeventlane_payout_ledger` |
| Row creation | Lazy insert in `PlatformMetricsService::buildKpis()` (not `ORDER_PAID`) |
| Reconcile | `/stripe/webhook/payout` on `transfer.*` |

### Post-purchase

| Concern | Current runtime |
| --- | --- |
| Confirmation | Messaging queues (`OrderConfirmationQueueBuilder`) |
| Tickets | `ORDER_PAID` ticket subscribers |
| Wallet | Pass generation; **no** Stripe charge dependency |

---

## Consequences

### Positive

- Aligns with Commerce Stripe upgrade path for checkout.  
- Express onboarding and Transfers are implemented and used.  
- Wallet and messaging remain outside the charge path.

### Negative / risks

- Destination-charge code implies a different model than production wiring (CF-001).  
- Ledger timing and scope are unsafe for unsupervised payouts (CF-006, CF-007).  
- Multiple gateways apply to the same carts (CF-008).  
- Credential drift between sync and active config (CF-005).

---

## Compliance with this ADR

Any change that reintroduces destination charges at PaymentIntent time must go through **ADR-003** and create an explicit gateway entity — not silent reuse of Transfer assumptions.

---

## Validation references

- Runtime gateway entities and plugins (Phase 2 `ddev drush php:eval`).  
- Historical payments: tickets mostly `stripe`; also PE + manual.  
- `ORDER_PAID` listeners list (no ledger writer).  
- Ledger SQL by order type (donations/Boost/Pro present).
