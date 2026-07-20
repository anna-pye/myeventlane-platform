# MEL Payment Technical Debt Register

**Status:** READ-ONLY Phase 2  
**Date:** 20 July 2026  
**Rule:** Never recommend deleting code unless runtime evidence proves: no callers, no service definition, no routing, no plugin manager use, no event subscriber, no queue worker, no reflection, no DI, no Commerce registration.

---

## Safe after launch

| Item | Problem | Evidence | Suggested action |
| --- | --- | --- | --- |
| `StripeService::createPaymentIntentForTicketSale()` | Dead direct PI path | No callers (CF-004) | Deprecate in docs; delete only after full dead-code checklist |
| `StripeService::createPaymentIntentForBoost()` | Dead direct PI path | No callers | Same |
| `StripeService::getOrCreateAccount()` | Superseded by `ensureConnectAccountIdForStore()` | No external callers | Same |
| Pro subscription webhook spam on retries | Audit-only, no idempotency store | Phase 1 Stage 8 | Add idempotency or reduce log noise |
| Dual fee calculators (Connect service vs metrics `commission_rate`) | Confusing SoT | Phase 1 Stage 10 | Unify after model A confirmed |
| Fast checkout vs Card Element dual UX | Two PE/CE paths | FastCheckout + mel_event_checkout panes | Simplify UX after gateway conditions fixed |
| DonationPane / checkout_donation legacy | Hidden pane still in codebase | Phase 1 donations map | Remove after confirming no config enables it |

---

## Needs product decision

| Item | Problem | Evidence | Decision needed |
| --- | --- | --- | --- |
| Option A vs Option B marketplace model | Destination charges unused; Transfers active | CF-001, CF-002 | Which model is launch truth? |
| Connect hard gate at checkout | Subscriber unwired; log-only even if wired | CF-003 | Must vendors be Connect-ready before selling? |
| Manual gateway in non-local envs | Tickets completed without Stripe | CF-008 | Allow any prod manual completion? |
| Organiser donation economics | Fee math in unused Connect PI path | Phase 1 | How donations affect vendor net |
| Boost/Pro in payout ledger | Platform SKUs treated as vendor liabilities | CF-007 | Never? Always exclude? |
| RSVP/platform donations in ledger | Donation nets marked unpaid to stores | CF-007 | Exclude from payout ledger |

---

## Needs architecture decision

| Item | Problem | Evidence | Decision needed |
| --- | --- | --- | --- |
| Ledger write timing | Lazy KPI vs `ORDER_PAID` | CF-006; ledger review | Primary writer + backfill role |
| Ledger eligibility rules | Unfiltered completed orders | CF-007 | Allowlist by order type / purchased entity |
| Gateway condition matrix | All AUD gateways apply to all carts | CF-008; gateway runtime | Order-type / product conditions |
| Commerce Stripe OAuth auth on `stripe` entity | Active uses `authentication_method=stripe_connect` vs sync keys | CF-005 | Canonical credential pattern for deploy |
| Payment webhook strategy | Secrets empty; sync capture assumed | Phase 1 Stage 8 | Required for launch or post-launch? |
| Custom `stripe_connect` plugin retention | Extends PE; entity absent | CF-001 | Future Option B vs delete later |

See ADRs:

- [`ADR-002-payment-runtime.md`](../adr/ADR-002-payment-runtime.md)  
- [`ADR-003-stripe-connect-strategy.md`](../adr/ADR-003-stripe-connect-strategy.md)

---

## Legacy

| Item | Notes | Evidence |
| --- | --- | --- |
| `DonationPane` always invisible | Legacy checkout donation UI | Phase 1 |
| `checkout_donation` item type | Parallel to `field_mel_donation` adjustment | Phase 1 |
| Direct PI helpers on `StripeService` | Pre-Commerce or alternate design remnant | CF-004 |
| StripeConnect plugin form annotations referencing offsite forms | Plugin extends PE but annotation still lists offsite form keys | `StripeConnect.php` annotation |

---

## Dormant

| Item | Why dormant | Do not delete yet |
| --- | --- | --- |
| Plugin `stripe_connect` | Discoverable; 0 entities | Commerce registration exists |
| `StripeConnectValidationSubscriber` class | No service tag | May be wired later for hard gate |
| `StripeConnectPaymentService::getConnectPaymentIntentParams()` PI merge | Only called from unwired plugin | Service still used for validation/reporting paths |
| Pro webhook without secret | Endpoint exists; rejects/unusable without secret | Route + controller registered |
| Express checkout on PE gateway | `enable_on_cart=false` | Config present for future enablement |

---

## Deletion checklist (mandatory)

Before any deletion PR:

1. Repo-wide PHP/YML/Twig search for class, method, service id, plugin id, route name.  
2. Runtime: `plugin.manager.commerce_payment_gateway` definitions.  
3. Runtime: `Drupal::hasService()` / container service ids.  
4. Runtime: event dispatcher listeners.  
5. Runtime: queue worker plugin discovery.  
6. Config sync + active config entities.  
7. Tests referencing the symbol.  
8. Reflection / serialized plugin ids in DB (payment gateway plugin field).  

If any check is positive → **Keep until post-launch** or **Needs investigation**, not delete.
