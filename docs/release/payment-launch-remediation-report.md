# Payment Launch Remediation — Implementation Report

**Branch:** `feature/payment-launch-remediation`  
**Date:** 20 July 2026  
**Plan:** [`payment-launch-remediation-plan.md`](./payment-launch-remediation-plan.md)  
**Architecture authority:** Option A (ADR-003) — platform collect + Transfer/ledger; Connect destination charges remain dormant.

---

## 1. Summary

Launch blockers CF-008 (gateway sprawl) and CF-007 (ledger pollution on insert) are remediated with small, reversible changes. Phase 5 `No such PaymentMethod` is an environment credential mismatch (Connect `access_token` preferred over platform `secret_key`); active DDEV gateway was corrected to `api_keys`.

| Success criterion | Result |
| --- | --- |
| Ticket → `stripe` | Pass (order 580 anon) |
| Boost → `stripe` | Pass (temp draft cart) |
| MEL Pro → `stripe_pe_recurring` | Pass (temp draft cart) |
| Recurring → `stripe_pe_recurring` only | Pass (order 554) |
| Manual unavailable to customers | Pass (anon ticket); admin still sees `mel_stripe_cc` |
| Ledger insert vendor-payable only | Pass (classifier + insert gate) |
| Wallet unchanged | Pass (no wallet code touched) |
| Stripe Connect destination charges dormant | Pass (no `stripe_connect` entity) |

---

## 2. Files changed

| Path | Change |
| --- | --- |
| `web/modules/custom/myeventlane_commerce/src/Service/OrderItemClassifier.php` | Payout ledger eligibility + recurring-gateway helpers |
| `web/modules/custom/myeventlane_commerce/src/EventSubscriber/FilterPaymentGatewaysSubscriber.php` | **New** — launch gateway matrix filter |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml` | Register filter subscriber |
| `web/modules/custom/myeventlane_commerce/tests/src/Unit/OrderItemClassifierPayoutLedgerTest.php` | **New** unit tests |
| `web/modules/custom/myeventlane_commerce/tests/src/Unit/FilterPaymentGatewaysSubscriberTest.php` | **New** unit tests |
| `web/modules/custom/myeventlane_admin_dashboard/src/Service/PlatformMetricsService.php` | Skip ledger insert unless payout-eligible |
| `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.services.yml` | Inject classifier + logger |
| `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.info.yml` | Depend on `myeventlane_commerce` |
| `config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml` | Admin role condition |
| `config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml` | AUD **and** Pro variation type |
| `docs/release/payment-launch-remediation-plan.md` | Plan + rollback |
| Architecture / launch docs | Before/after updates (see §7) |

**Backups:** `backups/payment-launch-remediation-20260720/`

---

## 3. Config changed

### Exported (config/sync)

1. **`mel_stripe_cc`** — `current_user_role: administrator` (entity preserved, `status: true`).
2. **`stripe_pe_recurring`** — `conditionOperator: AND` with `current_currency: AUD` + `order_variation_type: mel_pro_subscription_variation`.

### Active environment only (not exported secrets)

3. **`stripe` gateway** — cleared `access_token` / `stripe_user_id`; `authentication_method: api_keys` so Commerce Stripe uses platform `secret_key` matching Elements `publishable_key`.

**Deploy note:** After `cim`, re-inject Stripe keys via existing env overlay. Do **not** reintroduce a Connect OAuth `access_token` on the platform Card Element gateway under Option A.

---

## 4. Runtime verification

| Probe | Evidence |
| --- | --- |
| Anon ticket cart 580 | gateways=`[stripe]`, ledger eligible=Y |
| Temp boost draft | gateways=`[stripe]`, ledger=N |
| Temp Pro draft | gateways=`[stripe_pe_recurring]`, ledger=N |
| Recurring order 554 | gateways=`[stripe_pe_recurring]` |
| Admin + ticket | gateways=`[mel_stripe_cc,stripe]` |
| Filter subscriber registered | `FilterPaymentGatewaysSubscriber` listening |
| Completed zero-balance orders | Empty gateway list via Commerce `ZeroBalanceOrderSubscriber` (expected) |
| Sample ledger math | `gross * 0.1 = commission`, `net = gross - commission` — OK on recent rows |
| PM retrieve after auth fix | `secret_key` retrieves `pm_1Tv7ms…` OK; prior failure was `access_token` account mismatch |

**Side effect (expected):** Ticket fast checkout (PE-only) no longer finds a PE gateway on ticket carts. Standard Card Element checkout remains.

**Manual browser checkout:** Not fully exercised in this session (CLI/temp carts + unit tests). Recommended before merge: one live ticket, boost, donation, and Pro payment in DDEV.

---

## 5. Regression results

```text
ddev drush cr — success
PHPUnit (9 tests): OrderItemClassifierPayoutLedgerTest + FilterPaymentGatewaysSubscriberTest — OK
```

PHPUnit reported existing PHPUnit deprecation notices (11); no new assertion failures.

PHPStan: not run in this session.  
Full Functional/Kernel Commerce suite: not run in this session.

---

## 6. Outstanding risks

| Risk | Severity | Notes |
| --- | --- | --- |
| CF-006 — ledger still lazy on KPI, not `ORDER_PAID` | High (payout ops) | Scope filter fixed; timing unchanged |
| Historical polluted ledger rows (Boost/Pro/donations) | High if batched | Not deleted; ops must exclude before Transfers |
| Flat `commission_rate` vs platform fee / MEL % | Medium | Internal ledger math consistent; may not match fee UX |
| Deploy `cim` wiping keys / reintroducing `access_token` | High | Runbook: inject keys; keep access_token empty for Option A |
| Fast checkout disabled for tickets | Low–Medium UX | Intentional with PE scoped to Pro |
| DDEV project root moved to this worktree for verification | Ops | Restore main project listing if needed: unlist/start from preferred path |

---

## 7. Documentation updates

Updated / added:

- `docs/release/payment-launch-remediation-plan.md`
- `docs/release/payment-launch-remediation-report.md` (this file)
- `docs/architecture/payment-critical-findings.md` — remediation status
- `docs/architecture/payment-runtime-matrix.md` — target gateways
- `docs/architecture/payment-gateway-runtime.md` — filter subscriber
- `docs/architecture/payment-ledger-review.md` — eligibility rules
- `docs/launch/payment-launch-risk-register.md` — mitigated rows
- `docs/launch/payment-executive-summary.md` — post-remediation readiness
- `docs/adr/ADR-002-payment-runtime.md` — runtime note for filter + ledger scope

---

## 8. Deployment checklist

1. Merge/deploy code on `feature/payment-launch-remediation`.
2. `drush cim` for gateway condition YAML (or apply conditions manually).
3. Ensure active `stripe` has empty `access_token` and matching platform pk/sk.
4. `drush cr`.
5. Smoke: ticket, boost, platform donation, RSVP donation, MEL Pro, refund, vendor onboarding (no Connect PI).
6. Confirm wallet CTA still appears on ticket confirmation.
7. **Do not** run unrestricted payout batches until historical ledger cleanup SOP is done.
8. Confirm `stripe_connect` gateway entity still absent.

---

## 9. Rollback procedure

See plan § Rollback. Quick path:

1. Restore files from `backups/payment-launch-remediation-20260720/`.
2. Remove `FilterPaymentGatewaysSubscriber` + service.
3. Revert classifier / metrics / info.yml dependency.
4. Re-import previous gateway YAML.
5. `drush cr`.
6. Env-only: restore prior `stripe` auth only if intentionally required (not recommended for Option A).
