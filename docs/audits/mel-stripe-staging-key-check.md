# Stripe staging gateway / Connect — key check

**Date:** 2026-04-28  
**Scope:** Investigate platform secret resolution for Connect (`StripeService::getPlatformSecretKey()`), compare DDEV vs staging, document safe fix and verification. **No** secret keys, full Account Link URLs, or `config/sync` exports in this file.

**Repo references:** [`web/modules/custom/myeventlane_core/src/Service/StripeService.php`](../../web/modules/custom/myeventlane_core/src/Service/StripeService.php) (`getPlatformSecretKey`, `getPlatformPublishableKey`, `melGetEnv`); [`web/sites/default/settings.mel_shared_session.php`](../../web/sites/default/settings.mel_shared_session.php) (env merge into Commerce gateway config); [`web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php`](../../web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php) (missing key vs `ApiErrorException`).

---

## 1. How the platform secret is resolved (code)

1. Commerce payment gateway entities, **first non-empty** `secret_key` wins, in order: `mel_stripe` → `stripe` → `stripe_myeventlane_v2` → `stripe_pe_recurring`.
2. Config `myeventlane_core.stripe_settings` → `platform_secret_key` (if present in the environment).
3. Environment variable `MEL_STRIPE_SECRET_KEY` (`getenv` / `$_ENV` / `$_SERVER`).

`settings.mel_shared_session.php` merges `MEL_STRIPE_SECRET_KEY` and `MEL_STRIPE_PUBLISHABLE_KEY` into **only** the `stripe`, `stripe_myeventlane_v2`, and `stripe_pe_recurring` gateway config — **not** a `mel_stripe` config id. If an environment uses a `mel_stripe` gateway with keys only in the DB, resolution still hits `stripe` second if the first is empty, or the final `melGetEnv` fallback.

**FPM vs CLI:** Drush runs under PHP-CLI. Environment variables set only for **PHP-FPM** (or the web SAPI) may be **absent** in `drush php-eval`. A “secret key present: no” result in CLI does **not** prove the website has no key if the host injects `MEL_STRIPE_SECRET_KEY` only for the web pool.

---

## 2. DDEV gateway status (local, this audit)

Captured via `ddev drush` from project root (Apr 2026).

| Check | Result |
|--------|--------|
| Resolved gateway (`mel_stripe` else `stripe`) | **`stripe`** — label “MEL - Stripe CC”, plugin `stripe` |
| Mode | **test** |
| Auth | **api_keys** (`authentication_method` / auth fields as reported by plugin config) |
| Publishable key prefix | **`pk_test_51RWx3wH…`** (first 16 chars only recorded here) |
| Secret key present (plugin config as loaded) | **yes** |
| Other gateway ids | `mel_stripe`: **missing**; `stripe_myeventlane_v2`: **missing**; `stripe_pe_recurring`: **exists** |

**Note:** `drush cget commerce_payment.commerce_payment_gateway.stripe --include-overridden` on DDEV prints full keys in terminal output. Do **not** paste that output into tickets or docs; use prefixes and yes/no only.

---

## 3. Staging gateway status — before fix (as reported)

| Check | Result (reported) |
|--------|-------------------|
| Gateway | `stripe` (or `mel_stripe` if that is the first resolved id on staging) |
| Mode | **test** |
| Publishable key prefix | **`pk_test_51RWx3wH…`** (matches DDEV prefix) |
| Secret key present (entity `drush php-eval` as run on staging) | **no** |
| Symptoms | Connect fails; logs have included `Stripe API error: account n/a`, `Failed to create Stripe Connect account`, and text about **managing losses** (Stripe Connect / platform requirements). |

**Interpretation:** If logs show `Stripe API error` with a Stripe error body, that is the `ApiErrorException` path: a request reached Stripe. That can co-exist with an empty key in **CLI**-visible config, or with a separate need to complete the **Connect Platform Profile** in the Stripe Dashboard (test mode) for the platform account that owns the keys.

---

## 4. Recommended fix (staging; no `cex`, no committed secrets)

**Preferred:** Drupal admin → Commerce → Configuration → **Payment gateways** → edit the active Stripe gateway → **Test** mode → set **Secret key** to the **`sk_test_…`** that pairs with the **`pk_test_…`** already configured → Save.

**Alternative:** Set **`MEL_STRIPE_SECRET_KEY`** in the **web** PHP environment (e.g. PHP-FPM pool, platform secrets), restart PHP if required, then:

```bash
./vendor/bin/drush cr
```

Do **not** export configuration containing keys. Do **not** commit keys.

---

## 5. Staging gateway status — after fix

**Status:** *Pending confirmation on staging.* After applying §4, re-run:

```bash
./vendor/bin/drush cr
./vendor/bin/drush php-eval '$gateway = \Drupal::entityTypeManager()->getStorage("commerce_payment_gateway")->load("mel_stripe") ?: \Drupal::entityTypeManager()->getStorage("commerce_payment_gateway")->load("stripe"); if (!$gateway) { echo "No gateway found\n"; return; } $config = $gateway->getPluginConfiguration(); echo "Gateway: " . $gateway->id() . PHP_EOL; echo "Mode: " . ($config["mode"] ?? "unknown") . PHP_EOL; echo "Publishable key prefix: " . substr((string) ($config["publishable_key"] ?? ""), 0, 16) . "..." . PHP_EOL; echo "Secret key present: " . (!empty($config["secret_key"]) ? "yes" : "no") . PHP_EOL;'
```

Optionally:

```bash
./vendor/bin/drush cget commerce_payment.commerce_payment_gateway.stripe --include-overridden
```

If staging uses `mel_stripe` or `stripe_myeventlane_v2`, run `cget` for those ids too.

| Check | Result (fill after fix) |
|--------|-------------------------|
| Secret key present (gateway entity / overrides) | |
| Mode | test |

---

## 6. Connect Platform Profile (Stripe Dashboard)

**Completed for test platform account used by staging keys:** *Unknown — confirm in Stripe Dashboard (Developers / Connect / settings as applicable for your account).* DDEV success required completing the Connect Platform Profile for the same logical platform setup.

---

## 7. Verification after fix — Connect + logs

1. Click **Connect Stripe** once on staging (vendor flow).
2. Review watchdog:

```bash
./vendor/bin/drush ws --count=80 | grep -A4 -B2 -Ei "Stripe API error|Failed to create Stripe Connect account|Stripe Account Link created|Created AccountLink|managing losses"
```

**Expect:**

- No “managing losses” / Connect blocking errors once platform profile and keys match.
- **No** raw secret keys or full `https://connect.stripe.com/...` Account Link URLs in logs (existing success path logs masked `acct_` via `StripeService::maskAccountId` where applicable).

**Account Link created after fix:** *Fill yes/no after operator run.*

---

## 8. Log safety checklist

| Item | OK |
|------|-----|
| No `sk_live_` / `sk_test_` strings in log excerpts attached to tickets | |
| No full Account Link URLs copied into docs | |
| Use masked account ids only (`acct_…` shortened) | |

---

## 9. Remaining recommendation (optional; requires Anna approval to implement)

- **Host-managed secrets:** Prefer **`MEL_STRIPE_SECRET_KEY`** (and publishable/webhook as needed) in the **web** process environment for staging/production so DB/UI copies are not the only source of truth.
- **CLI parity:** Document that Drush may lack FPM-only vars; use staging web-driven checks or align CLI env when diagnosing.
- **Optional code/settings parity:** If an environment standardizes on gateway id **`mel_stripe`**, consider extending [`web/sites/default/settings.mel_shared_session.php`](../../web/sites/default/settings.mel_shared_session.php) to merge env vars into `commerce_payment.commerce_payment_gateway.mel_stripe` the same way as `stripe` — **do not change without approval.**

---

## 10. Git

Documentation only; **no commit** from this task unless Anna approves.
