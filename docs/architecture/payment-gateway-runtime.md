# MEL Payment Gateway Runtime Verification

**Status:** Phase 2 audit + launch remediation update  
**Date:** 20 July 2026  
**Commands:** `ddev drush php:eval` entity/plugin/order probes  
**Critical:** [`payment-critical-findings.md`](./payment-critical-findings.md) CF-001, CF-008, CF-009  
**Remediation:** [`../release/payment-launch-remediation-report.md`](../release/payment-launch-remediation-report.md)

---

## 1. Gateway entities (active config)

| Entity ID | Label | Plugin ID | Enabled | Weight | Conditions | Payment method types | Notable config |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `stripe` | MEL - Stripe CC | `stripe` | Yes | null | `current_currency: AUD` | `credit_card` | `mode=test`; `authentication_method=stripe_connect`; publishable/secret/access_token present; webhook secret empty |
| `stripe_pe_recurring` | Stripe (Payment Element) — Recurring | `stripe_payment_element` | Yes | 0 | `current_currency: AUD` | `stripe_card` | `payment_method_usage=off_session`; `capture_method=automatic`; `express_checkout.enable_on_cart=false`; API keys present; webhook signing secret empty |
| `mel_stripe_cc` | MEL - Manual | `manual` | Yes | null | **none** | `credit_card` | No Stripe keys |

**Hidden / unwired plugin**

| Plugin ID | Provider | Entities | Notes |
| --- | --- | --- | --- |
| `stripe_connect` | `myeventlane_commerce` | **0** | Discoverable; never presented because no gateway entity |

---

## 2. Discovered plugins

| Plugin ID | Class | Entities using it |
| --- | --- | --- |
| `stripe` | `Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\Stripe` | 1 (`stripe`) |
| `stripe_payment_element` | `Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement` | 1 (`stripe_pe_recurring`) |
| `stripe_connect` | `Drupal\myeventlane_commerce\Plugin\Commerce\PaymentGateway\StripeConnect` | 0 |
| `manual` | `Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\Manual` | 1 (`mel_stripe_cc`) |

---

## 3. What customers actually get (by flow)

### Method

1. Inspect draft-cart `loadMultipleForOrder()` applicability (what Commerce will offer).  
2. Inspect historical `commerce_payment` rows (what was selected/completed).  
3. Map order types / item variation types.

### Ticket checkout

| Question | Answer | Evidence |
| --- | --- | --- |
| Applicable gateways (AUD draft) | `mel_stripe_cc`, `stripe`, `stripe_pe_recurring` | Draft orders 580, 569, 538, 537 |
| Dominant completed gateway | `stripe` | 105 completed payments on `ticket_variation` |
| Also used for tickets | `stripe_pe_recurring` (11), `mel_stripe_cc` (8) | Payment×variation aggregate |
| Why Commerce offers them | All three `applies()` = Y on AUD drafts; no MEL filter subscriber; method types differ in UI | Runtime applies probes; Phase 1 Stage 2 |

**Why `stripe` is selected most often:** checkout panes (`payment_information` / `stripe_review` / `payment_process` on `mel_event_checkout`) present Card Element (`credit_card`) as the primary path for standard checkout. Fast checkout prefers PE when eligible.

### Boost checkout

| Question | Answer | Evidence |
| --- | --- | --- |
| Applicable | Same three AUD gateways | Draft `default` carts |
| Observed completed | `stripe` | Payment 420: boost_duration via `stripe` |
| Aggregate | 17 completed boost payments on `stripe` | Payment×variation |

### MEL Pro

| Question | Answer | Evidence |
| --- | --- | --- |
| Cart/order type for subscribe | `default` → `mel_event_checkout` | `ProSubscribeForm` + order type config |
| Observed completed | `stripe_pe_recurring` (payment 424); also `mel_stripe_cc` (423) | Payment history |
| Forced to PE? | **No** — conditions are currency-only | No order-type gateway conditions |
| Recurring renewals | Order type `recurring`, no checkout flow | Runtime order type; gateway still applicable by currency if charged through Commerce |

### Platform donation

| Question | Answer | Evidence |
| --- | --- | --- |
| Order type / flow | `platform_donation` / `default` | Runtime |
| Observed gateway | `stripe` | Order 512 payment |
| Applicable | All three on AUD | Same condition logic |

### RSVP donation

| Question | Answer | Evidence |
| --- | --- | --- |
| Order type / flow | `rsvp_donation` / `default` | Runtime |
| Observed gateway | `stripe` | Order 520 payment |

---

## 4. Express checkout

| Item | Runtime value | Implication |
| --- | --- | --- |
| `stripe_pe_recurring` `express_checkout.enable_on_cart` | `false` | Cart-level express buttons disabled in gateway config |
| Fast checkout (MEL) | Requires PE gateway implementing `StripePaymentElementInterface` | `FastCheckoutEligibility::isUsableStripeGateway()` — uses `stripe_pe_recurring` when applicable |
| Card Element gateway `stripe` | Not PE | Not usable for MEL fast checkout confirm path |

---

## 5. Recurring availability

| Item | Value |
| --- | --- |
| `stripe_pe_recurring.payment_method_usage` | `off_session` |
| Commerce Recurring module | enabled |
| Intent | Store payment method for renewals / off-session |
| Gap | Same PE gateway is also selectable for one-time tickets (CF-008) |

---

## 6. AUD restrictions

| Gateway | AUD-only? |
| --- | --- |
| `stripe` | Yes (`current_currency: AUD`) |
| `stripe_pe_recurring` | Yes |
| `mel_stripe_cc` | **No** — always applies when enabled |

Non-AUD carts would still see the manual gateway if it remains enabled.

---

## 7. Why Commerce selected each gateway (mechanism)

### Before remediation (audit)

```text
Order in checkout
  → PaymentGatewayStorage::loadMultipleForOrder(order)
      → conditions only (AUD / none)
  → No MEL FilterPaymentGateways subscriber
  → UI method-type split
```

### After remediation (launch)

```text
Order in checkout
  → PaymentGatewayStorage::loadMultipleForOrder(order)
      → gateway.applies(order) via conditions
           mel_stripe_cc → administrator role
           stripe → AUD
           stripe_pe_recurring → AUD AND mel_pro_subscription_variation
  → MEL FilterPaymentGatewaysSubscriber
           remove mel_stripe_cc unless administrator
           remove stripe_pe_recurring unless Pro/recurring
           remove stripe when Pro/recurring
  → UI / pane filters by payment method type
  → Customer picks remaining gateway
```

**Target consequence:** ticket/boost/donations → `stripe`; MEL Pro/recurring → `stripe_pe_recurring`; manual admin-only.

---

## 8. Config sync vs active (reminder)

| Field | Sync YAML `stripe` | Active DDEV `stripe` |
| --- | --- | --- |
| Keys | empty | present |
| `authentication_method` | absent | `stripe_connect` |
| `access_token` / `stripe_user_id` | absent | present |

See CF-005. Never export secrets.

---

## 9. Launch actions (documentation only)

1. Decide single intentional ticket gateway.  
2. Disable `mel_stripe_cc` in production.  
3. Add order-type or product conditions (post-decision implementation — out of scope here).  
4. Keep `stripe_connect` plugin unwired unless Option B is chosen ([ADR-003](../adr/ADR-003-stripe-connect-strategy.md)).
