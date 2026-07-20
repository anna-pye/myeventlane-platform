# MEL Payment Critical Findings

**Status:** Audit finding + launch remediation applied (20 July 2026)  
**Date:** 20 July 2026  
**Environment:** Local DDEV (`ddev drush` runtime inspection)  
**Scope:** Original audit was read-only; launch remediation on `feature/payment-launch-remediation` addresses CF-007/CF-008 insert/gateway scope and Phase 5 PaymentMethod auth mismatch  
**Remediation report:** [`../release/payment-launch-remediation-report.md`](../release/payment-launch-remediation-report.md)

**Related docs:**

- [`payment-runtime-map.md`](./payment-runtime-map.md) (Phase 1)
- [`payment-gateway-runtime.md`](./payment-gateway-runtime.md) (Phase 2)
- [`payment-ledger-review.md`](./payment-ledger-review.md) (Phase 2)
- [`payment-launch-risk-register.md`](../launch/payment-launch-risk-register.md)

---

## Summary

Ticket (and related) checkout does **not** use the custom Stripe Connect destination-charge gateway. Customer payments are collected on the platform Stripe account via Commerce Stripe gateways. Vendor payouts are a **separate** Transfer/ledger workflow.

Phase 2 runtime verification **strengthens** Phase 1 findings and adds a new financial-operations blocker: the payout ledger lazily inserts rows for **all** completed orders in a date window — including platform donations, RSVP donations, Boost, and MEL Pro — treating them as vendor payout liabilities with a flat commission cut.

This is a **financial architecture / launch-risk** finding set. Whether production currently relies on the ledger/transfer model by design, or expected destination charges at PaymentIntent time, remains a stakeholder decision.

---

## Finding CF-001 — Destination-charge Connect gateway is unused

### Severity

**Critical (financial architecture)** — marketplace fund-routing path described in code is not the active Commerce checkout path.

### Evidence

| Claim | Evidence |
| --- | --- |
| Custom gateway plugin exists | `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/PaymentGateway/StripeConnect.php` — `@CommercePaymentGateway(id = "stripe_connect")` |
| Plugin injects `application_fee_amount` + `transfer_data` | `StripeConnect::createPaymentIntent()` → `StripeConnectPaymentService::getConnectPaymentIntentParams()` |
| **No Commerce payment gateway entity uses the plugin** | Runtime (Phase 2): discovered plugins show `stripe_connect` entities=0 |
| Config sync has no `stripe_connect` gateway entity | Only gateways in `config/sync/`: `stripe`, `mel_stripe_cc`, `stripe_pe_recurring` |
| Plugin is discoverable | Runtime plugin manager lists `stripe_connect` |

### Runtime gateway entities (DDEV, 20 July 2026)

| Entity ID | Plugin ID | Status |
| --- | --- | --- |
| `stripe` | `stripe` (Commerce Stripe Card Element) | enabled |
| `stripe_pe_recurring` | `stripe_payment_element` | enabled |
| `mel_stripe_cc` | `manual` | enabled |
| *(none)* | `stripe_connect` | **no entity** |

### Classification

Destination-charge implementation via custom Commerce gateway: **UNUSED** (plugin ACTIVE in discovery, entity wiring ABSENT).

---

## Finding CF-002 — Alternate payout path exists (Transfers + ledger)

### Severity

**High** — vendor money movement is a Transfer/ledger model, not destination charges at checkout.

### Evidence

| Claim | Evidence |
| --- | --- |
| Admin batch executes Stripe Transfers | `PayoutBatchWorkflowService` — `$client->transfers->create([... 'destination' => $connectedAccountId ...])` |
| Ledger table written | `PlatformMetricsService` inserts into `myeventlane_payout_ledger` |
| Transfer webhooks reconcile ledger | Route `/stripe/webhook/payout` → `StripeWebhookController::handle()` |

### Implication

Observed architecture:

1. Customer pays **platform** Stripe account (Commerce Stripe gateway).
2. Platform records vendor liability in `myeventlane_payout_ledger`.
3. Admin creates Stripe **Transfer** to vendor Connect account.
4. Webhook reconciles ledger status.

---

## Finding CF-003 — Connect validation subscriber is unwired

### Severity

**High** (operational / financial control gap)

### Evidence

`StripeConnectValidationSubscriber` exists but:

- No service definition / `event_subscriber` tag in `myeventlane_commerce.services.yml`.
- Runtime (Phase 2): `CheckoutEvents::COMPLETION` listeners are only Commerce core/cart/log — **not** this class.

Even if wired, the method only logs — it would still not block completion.

---

## Finding CF-004 — Direct ticket/boost PI helpers appear unused

### Severity

**Medium** (dead / parallel path risk)

| Method | Callers outside definition |
| --- | --- |
| `StripeService::createPaymentIntentForTicketSale()` | **None found** |
| `StripeService::createPaymentIntentForBoost()` | **None found** |

Classification: **UNUSED**. Do not delete without the dead-code checklist.

---

## Finding CF-005 — Active gateway credentials drift from config sync

### Severity

**High** (ops / security / environment drift)

| Source | `stripe` gateway keys |
| --- | --- |
| `config/sync/...stripe.yml` | Empty keys; no OAuth fields |
| Active DDEV config | `authentication_method: stripe_connect`; non-empty keys/token; `stripe_user_id` set |
| Env vars in this DDEV process | `MEL_STRIPE_*` unset |

Secret values must never be committed or pasted into docs.

---

## Finding CF-006 — Payout ledger rows are not created on order place

### Severity

**Critical (financial operations)** — vendor liability recording is not tied to the payment lifecycle.

### Evidence

Repo-wide search for inserts into `myeventlane_payout_ledger` finds a single writer:

- `PlatformMetricsService::buildKpis()` in `web/modules/custom/myeventlane_admin_dashboard/src/Service/PlatformMetricsService.php`
- Insert runs as a side effect of KPI aggregation over completed orders in a date window when admin metrics paths execute.

No `OrderEvents::ORDER_PAID` subscriber inserts ledger rows (Phase 2: 13 `ORDER_PAID` listeners listed; none are ledger writers).

### Implication

If admin KPI/payout screens are never loaded for a period, unpaid ledger rows for completed orders may not exist. Batch Transfer workflows that select from the ledger would then omit those orders until metrics code runs.

---

## Finding CF-007 — Ledger inserts ALL completed orders (including non-vendor-revenue)

### Severity

**Critical (financial operations / incorrect liabilities)** — Phase 2 runtime verification.

### Evidence

`PlatformMetricsService::buildKpis()` selects `commerce_order` where `state = completed` with **no order-type or product-type filter**, then inserts missing ledger rows with `commission = gross * commission_rate` and `status = unpaid`.

Runtime ledger composition (DDEV, 20 Jul 2026):

| Order type / product | Ledger presence |
| --- | --- |
| `default` | 98 unpaid + 46 approved |
| `rsvp_donation` | 11 unpaid (e.g. order 495: gross 25, commission 2.50, net 22.50) |
| `platform_donation` | 2 unpaid (e.g. order 474: gross 100, commission 10, net 90) |
| Orders with `boost` order items | 16 ledger orders |
| Orders with `mel_pro_subscription_variation` | 3 ledger orders |

### Implication

Platform-owned revenue (Boost, MEL Pro) and donations can appear as **vendor payout liabilities**. Executing Transfers against these rows could overpay vendors or misclassify platform income.

### Classification

Lazy ledger creation: **ACTIVE but incorrect scope** (audit).  
**Remediation (insert path):** `PlatformMetricsService` now skips non-vendor-payable orders (Boost, platform/RSVP donation, MEL Pro, recurring). Historical polluted rows remain until ops cleanup. Timing (CF-006) unchanged.

---

## Finding CF-008 — Three gateways apply to every AUD cart; tickets charged on all three

### Severity

**Critical (checkout / ops)** — Phase 2 runtime verification.

### Evidence

Draft AUD carts (`loadMultipleForOrder`):

- `mel_stripe_cc` (manual, **no currency condition**)
- `stripe` (Card Element, AUD)
- `stripe_pe_recurring` (Payment Element, AUD, `payment_method_usage: off_session`)

Historical `commerce_payment` rows (completed) by variation type:

| Gateway | `ticket_variation` | Other notable |
| --- | --- | --- |
| `stripe` | 105 | boost 17; Pro 0 in this cut |
| `stripe_pe_recurring` | 11 | Pro 1 |
| `mel_stripe_cc` | 8 | Pro 1 |

RSVP donation order 520 and platform donation order 512 used gateway `stripe`.

### Implication

Commerce does **not** segregate ticket vs Pro vs test gateways by order type. Manual gateway can complete ticket orders without Stripe. Recurring PE gateway is usable for one-time ticket checkout.

### Remediation (20 July 2026)

| Control | Change |
| --- | --- |
| `mel_stripe_cc` | Condition `current_user_role: administrator` + filter subscriber |
| `stripe_pe_recurring` | Conditions AUD **and** `mel_pro_subscription_variation`; filter removes PE from normal carts; removes Card Element from Pro/recurring |
| Customers | Tickets/Boost/donations → `stripe`; MEL Pro/recurring → `stripe_pe_recurring` |

---

## Finding CF-009 — PaymentMethod resource_missing (Phase 5)

### Severity

**High (checkout)** when gateway `access_token` (Connect OAuth) is set while Elements uses platform `publishable_key`.

### Evidence

- Watchdog: `No such PaymentMethod: 'pm_…'` / `resource_missing` during checkout payment method create (`Stripe::doCreatePaymentMethod` → `PaymentMethod::retrieve`).
- Same `pm_…` **retrieves OK** with platform `secret_key`; **fails** with gateway `access_token`.
- Contrib prefers `access_token` over `secret_key` when non-empty (`commerce_stripe` `Stripe::init()`).

### Remediation

Clear `access_token` / use `authentication_method: api_keys` on entity `stripe` for Option A platform collect. Do not suppress the exception.

---

## What is NOT claimed

- Not proven: production currently fails to pay vendors.
- Not proven: destination charges were previously active and later removed.
- Not proven: payout batches have already transferred donation/Boost/Pro ledger rows.
- Not proven: how often `PlatformMetricsService::buildKpis()` runs in production (cron vs page load). Controllers that inject `myeventlane_admin_dashboard.metrics` are the proven callers.

---

## Required decisions before launch

1. **Confirm intended marketplace model:**  
   - **A)** Platform collect + Transfer/ledger (current wiring), or  
   - **B)** Destination charges via `stripe_connect` gateway (code exists, not configured).
2. If **A**: harden ledger creation on `ORDER_PAID` with **order/product allowlisting**; keep Transfer webhooks as payout truth; formally retire unused Connect PI merge for launch.
3. If **B**: create/configure a Commerce payment gateway entity with `plugin: stripe_connect`, retire/condition Card Element for tickets, validate fee/transfer/refund math end-to-end.
4. **Disable or condition `mel_stripe_cc`** outside local/testing before public checkout.
5. **Narrow gateway conditions** so Pro/recurring uses PE off_session and ticket checkout uses a single intentional gateway.
6. If Connect readiness must be a hard checkout gate: register a subscriber (or earlier pane/access check), wire it in `services.yml`, and fail closed.

---

## Audit continuation

Phase 2 launch documentation set:

| Document | Path |
| --- | --- |
| Component lifecycle | `docs/architecture/payment-component-lifecycle.md` |
| Runtime matrix | `docs/architecture/payment-runtime-matrix.md` |
| Gateway runtime | `docs/architecture/payment-gateway-runtime.md` |
| Sequence diagrams | `docs/architecture/payment-sequence-diagrams.md` |
| Ledger review | `docs/architecture/payment-ledger-review.md` |
| Wallet boundary | `docs/architecture/wallet-payment-boundary.md` |
| Risk register | `docs/launch/payment-launch-risk-register.md` |
| Technical debt | `docs/architecture/payment-technical-debt.md` |
| ADRs | `docs/adr/ADR-002-payment-runtime.md`, `docs/adr/ADR-003-stripe-connect-strategy.md` |
| Executive summary | `docs/launch/payment-executive-summary.md` |

This critical-findings file must be read first when assessing Connect / vendor fund routing / payout readiness.
