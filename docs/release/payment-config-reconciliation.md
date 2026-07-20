# Payment Configuration Reconciliation

**Date:** 20 July 2026  
**Branch:** `feature/payment-launch-remediation`  
**Authority:** ADR-002, ADR-003 (Option A), `payment-launch-remediation-report.md`, `payment-launch-remediation-precommit-audit.md`  
**Mode:** Read-only — no `cex`, no config writes, no code changes

---

## 1. Which config is the intended launch state?

**Tracked `config/sync` (including the current working-tree edits to two gateway files) represents the intended launch structure.**

Active DDEV gateway configuration does **not**.

| Source | Role for launch |
| --- | --- |
| **Tracked sync** | Source of truth for entity IDs, plugins, conditions, PE usage flags, empty secret placeholders |
| **Active DDEV** | Environment-only credentials + currently **drifted / unsafe** shape — must be repaired by import/overlay, **never** by exporting into git |

This matches Option A (ADR-003): platform-collect Commerce Stripe + Transfers; custom `stripe_connect` plugin stays unwired (0 entities).

---

## 2. Intended launch matrix (normative)

| Entity ID | Plugin | Enabled | Conditions | Auth (runtime) | Payment method usage | Webhooks (gateway) | Customer visibility |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `stripe` | `stripe` (Card Element) | Yes | AUD | Platform **API keys** (`secret_key` + matching `publishable_key`); **`access_token` empty** | N/A (Card Element) | Gateway webhook secret may be empty if sync checkout is used | Tickets, Boost, donations |
| `stripe_pe_recurring` | `stripe_payment_element` | Yes | AUD **AND** `mel_pro_subscription_variation` | Platform API keys (same rule) | `off_session` | `webhook_signing_secret` empty in sync OK unless PE webhooks required | MEL Pro / recurring only |
| `mel_stripe_cc` | `manual` | Yes (preserved) | `current_user_role: administrator` | N/A | N/A | N/A | Administrators only |
| *(none)* | `stripe_connect` | — | — | — | — | — | Must remain absent |

Secrets belong in env / active overlay only — always empty strings in tracked YAML.

---

## 3. Tracked sync (working tree) vs intended

| File | Matches intended structure? | Needs further edit before commit? |
| --- | --- | --- |
| `config/sync/commerce_payment.commerce_payment_gateway.stripe.yml` | **Yes** — plugin `stripe`, AUD, empty keys, no `access_token` | **No** |
| `config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml` | **Yes** (WT) — PE, `off_session`, AUD + Pro variation, `AND`, empty keys | **No further edit** — already updated; include in commit |
| `config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml` | **Yes** (WT) — manual, admin role, empty of secrets | **No further edit** — already updated; include in commit |

### Sync file details verified

| Field | `stripe` sync | `stripe_pe_recurring` sync (WT) | `mel_stripe_cc` sync (WT) |
| --- | --- | --- | --- |
| Entity present in sync | Yes | Yes | Yes |
| Plugin | `stripe` | `stripe_payment_element` | `manual` |
| Status | `true` | `true` | `true` |
| Conditions | `current_currency: AUD` | AUD + `order_variation_type: mel_pro_subscription_variation` | `current_user_role: administrator` |
| conditionOperator | `OR` (single condition) | `AND` | `AND` |
| payment_method_types | `credit_card` | `stripe_card` | `credit_card` |
| payment_method_usage | n/a | `off_session` | n/a |
| capture_method | n/a | `automatic` | n/a |
| authentication_method | unset (no OAuth fields) | unset | n/a |
| publishable_key / secret_key | `''` | `''` | n/a |
| access_token | absent | absent | n/a |
| webhook_signing_secret | absent | `''` | n/a |
| express_checkout.enable_on_cart | n/a | `false` | n/a |

**No secrets present in tracked sync.**

---

## 4. Active DDEV vs intended (runtime probe, secrets redacted)

**Entities loaded:** `stripe`, `stripe_pe_recurring` only.  
**Plugins discoverable:** `manual`, `stripe`, `stripe_connect`, `stripe_payment_element`.  
**`stripe_connect` entities:** 0 (correct — keep dormant).

| Check | Intended | Active DDEV | OK? |
| --- | --- | --- | --- |
| Entity `stripe` exists | Yes | Yes | Yes |
| Entity `stripe` plugin | `stripe` (Card Element) | **`stripe_payment_element`** | **No** |
| Entity `stripe` PM types | `credit_card` | **`stripe_card`** | **No** |
| Entity `stripe` auth | API keys; empty access_token | **`stripe_connect` + access_token set; secret_key empty** | **No** |
| Entity `stripe` payment_method_usage | n/a | `on_session` | Drift (wrong plugin) |
| Entity `stripe` webhook secret | optional empty | empty | OK |
| Entity `stripe_pe_recurring` conditions | AUD + Pro variation, AND | AUD + Pro variation, AND | **Yes** |
| Entity `stripe_pe_recurring` usage | `off_session` | `off_session` | **Yes** |
| Entity `stripe_pe_recurring` auth | API keys | **`stripe_connect` + access_token; secret_key empty** | **No** |
| Entity `stripe_pe_recurring` webhook | empty OK | empty | OK |
| Entity `mel_stripe_cc` exists | Yes | **MISSING** | **No** |
| Commerce Stripe init prefers | `secret_key` | **`access_token`** on both Stripe entities | **No** (reintroduces CF-009 class failure) |

**Conclusion:** Active config is environment drift / corruption relative to Option A launch. It must **not** be exported. Repair by importing sync structure + injecting platform keys (empty `access_token`), not by `cex`.

---

## 5. Sync files that need updating (for git)

| Sync file | Action | Why |
| --- | --- | --- |
| `commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml` | **Commit working-tree version** | Adds administrator role condition (CF-008) |
| `commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml` | **Commit working-tree version** | Adds Pro variation condition + `AND` (CF-008 / recurring scope) |
| `commerce_payment.commerce_payment_gateway.stripe.yml` | **Do not change / do not export** | Already matches intended Card Element + AUD + empty secrets. Active wrong plugin/auth must not overwrite this file |

No other Commerce payment gateway sync files need updates for this remediation.

---

## 6. Verification checklist (launch intent)

| Area | Sync (WT) | Active DDEV | Launch gate |
| --- | --- | --- | --- |
| Gateway entities | 3 defined | 2 present (`mel_stripe_cc` missing) | Sync wins; restore via `cim` of sync entity |
| Gateway plugins | `stripe` / PE / `manual` | `stripe` entity wrongly PE | Sync wins |
| Gateway conditions | Admin / AUD / AUD+Pro | PE conditions OK; manual absent; primary wrong | Sync + filter subscriber |
| Authentication method | No Connect token in sync | Connect token preferred on both Stripe entities | Env repair required |
| Payment method usage | PE `off_session` | PE recurring OK; primary wrongly `on_session` PE | Sync wins for primary = Card Element |
| Webhook configuration | Empty gateway secrets in sync | Empty | Acceptable for sync-capture Option A; payout/Pro webhooks are separate settings |
| Secrets in tracked config | None | Present in active only | Do not write active secrets to git |

---

## 7. Required environment repair (not a git export)

Before trusting DDEV/runtime verification again:

1. Import sync gateway entities (especially recreate `mel_stripe_cc`, restore `stripe` plugin to Card Element) **without** committing any re-export that contains keys.  
2. Inject platform `publishable_key` + `secret_key` via existing env overlay / Commerce UI.  
3. Ensure `access_token` and `stripe_user_id` are **empty** on Option A checkout gateways.  
4. Confirm `authentication_method` resolves to API-key path (Commerce Stripe uses `secret_key` when `access_token` empty).  
5. Re-probe: entity IDs, plugins, conditions, anon/admin gateway matrix.  
6. **Never** `drush cex` from this DDEV until active structural shape matches sync and secrets are confirmed scrubbed from export preview.

---

## 8. Commit guidance

**Include in payment remediation commit (config):**

- `config/sync/commerce_payment.commerce_payment_gateway.mel_stripe_cc.yml`  
- `config/sync/commerce_payment.commerce_payment_gateway.stripe_pe_recurring.yml`

**Exclude:**

- Any export of active `stripe` / PE YAML with keys or `access_token`  
- Unrelated config  
- Changes to `stripe.yml` driven by current active drift  

Tracked sync (WT) = intended launch structure. Active DDEV = repair separately.

---

READY TO COMMIT
