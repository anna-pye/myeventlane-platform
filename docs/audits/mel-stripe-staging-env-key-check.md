# MEL Staging — Stripe environment and gateway keys

**Date:** 2026-04-28  

---

## TASK 4J — StripeService gateway ID lookup

**Problem:** Staging could not start Stripe Connect onboarding with: *Stripe is not configured for this environment. The platform secret key is missing.*

**Staging gateway IDs observed** (Commerce payment gateways; modes/secrets as audited):

| ID | Plugin | Notes |
| --- | --- | --- |
| `mel_stripe_cc` | manual | Not used for API keys |
| `stripe_for_events` | stripe | Test keys present |
| `vendor_payments` | stripe_connect | Test keys present |

**Root cause:** [`StripeService::getPlatformSecretKey()`](../../web/modules/custom/myeventlane_core/src/Service/StripeService.php) only iterated legacy gateway entity IDs (`mel_stripe`, `stripe`, `stripe_myeventlane_v2`, `stripe_pe_recurring`). Staging stores keys on **`vendor_payments`** and **`stripe_for_events`**, so the service never loaded those configs before falling through to config/env.

**Fix:** Added `vendor_payments` and `stripe_for_events` to a single lookup list (`getStripeGatewayLookupIds()`), tried first, then legacy IDs; unchanged fallbacks: `myeventlane_core.stripe_settings` `platform_secret_key`, `MEL_STRIPE_SECRET_KEY`, and for publishable keys `MEL_STRIPE_PUBLISHABLE_KEY`. No gateway entities created or deleted; no plugin or checkout architecture changes.

**Local verification**

```bash
composer validate
ddev drush cr
```

```bash
ddev drush php-eval '$service = \Drupal::service("myeventlane_core.stripe"); $ref = new ReflectionClass($service); $m = $ref->getMethod("getPlatformSecretKey"); $m->setAccessible(TRUE); try { $key = $m->invoke($service); echo "StripeService platform secret present: " . (!empty($key) ? "yes" : "no") . PHP_EOL; echo "Prefix: " . (!empty($key) ? substr($key, 0, 8) . "..." : "none") . PHP_EOL; } catch (\Throwable $e) { echo "StripeService error: " . $e->getMessage() . PHP_EOL; }'
```

**Staging verification** (after deploy)

```bash
./vendor/bin/drush cr
```

```bash
./vendor/bin/drush php-eval '$storage = \Drupal::entityTypeManager()->getStorage("commerce_payment_gateway"); $ids = $storage->getQuery()->accessCheck(FALSE)->execute(); foreach ($ids as $id) { $gateway = $storage->load($id); $config = $gateway->getPluginConfiguration(); echo $id . " | plugin=" . $gateway->getPluginId() . " | status=" . ($gateway->status() ? "enabled" : "disabled") . " | mode=" . ($config["mode"] ?? "unknown") . " | secret=" . (!empty($config["secret_key"]) ? "yes" : "no") . PHP_EOL; }'
```

```bash
./vendor/bin/drush php-eval '$service = \Drupal::service("myeventlane_core.stripe"); $ref = new ReflectionClass($service); $m = $ref->getMethod("getPlatformSecretKey"); $m->setAccessible(TRUE); try { $key = $m->invoke($service); echo "StripeService platform secret present: " . (!empty($key) ? "yes" : "no") . PHP_EOL; echo "Prefix: " . (!empty($key) ? substr($key, 0, 8) . "..." : "none") . PHP_EOL; } catch (\Throwable $e) { echo "StripeService error: " . $e->getMessage() . PHP_EOL; }'
```

**Expected on staging:** `StripeService platform secret present: yes`; prefix `sk_test_...` (first eight characters only).

Then click **Connect Stripe** once and check logs:

```bash
./vendor/bin/drush ws --count=80 | grep -A4 -B2 -Ei "Stripe is not configured|MEL_STRIPE_SECRET_KEY|Stripe API error|Failed to create Stripe Connect account|Stripe Account Link created|Created AccountLink|managing losses"
```

---

## TASK 4I — Stripe `settings.php` gateway override

**Problem:** Staging could not start Stripe vendor onboarding because the platform secret appeared missing (settings load order / empty `STRIPE_*` overrides).

**Security:** No full `sk_`, `pk_`, or `whsec_` values appear here. Treat any keys previously pasted into terminals or chat as **exposed** and **rotate** test secrets and webhook signing material in the Stripe Dashboard after staging is stable; update env or Commerce UI only—never tickets or Git.

---

## Root cause

Load order in [`web/sites/default/settings.php`](../../web/sites/default/settings.php):

1. [`settings.mel_shared_session.php`](../../web/sites/default/settings.mel_shared_session.php) is required **first** and merges `MEL_STRIPE_SECRET_KEY` / `MEL_STRIPE_PUBLISHABLE_KEY` into Commerce gateway `$config` when those env vars are non-empty.

2. A **later** block used to assign:

   - `getenv('STRIPE_PK') ?: ''` and `getenv('STRIPE_SK') ?: ''` directly into `commerce_payment.commerce_payment_gateway.stripe` and `stripe_pe_recurring` `publishable_key` / `secret_key`.

When `STRIPE_PK` / `STRIPE_SK` were **unset**, those expressions became **empty strings** and **overwrote** the effective gateway overrides, erasing MEL- or DB-backed keys for the web runtime. [`StripeService`](../../web/modules/custom/myeventlane_core/src/Service/StripeService.php) then saw no platform secret.

---

## Safe override pattern (current `settings.php` logic)

Only override when the corresponding env var is **non-empty**. **Never** assign empty strings into gateway config from this block.

```php
$stripe_pk = getenv('STRIPE_PK') ?: '';
$stripe_sk = getenv('STRIPE_SK') ?: '';

if ($stripe_pk !== '') {
  $config['commerce_payment.commerce_payment_gateway.stripe']['configuration']['publishable_key'] = $stripe_pk;
  $config['commerce_payment.commerce_payment_gateway.stripe_pe_recurring']['configuration']['publishable_key'] = $stripe_pk;
}

if ($stripe_sk !== '') {
  $config['commerce_payment.commerce_payment_gateway.stripe']['configuration']['secret_key'] = $stripe_sk;
  $config['commerce_payment.commerce_payment_gateway.stripe_pe_recurring']['configuration']['secret_key'] = $stripe_sk;
}
```

**`MEL_STRIPE_SECRET_KEY`:** Unchanged. It continues to be applied in `settings.mel_shared_session.php` **before** this block; this block no longer clears those merges when `STRIPE_*` is unset.

---

## Git and deployment

- **[`.gitignore`](../../.gitignore)** ignores `web/sites/*/*settings*.php`, so **`web/sites/default/settings.php` is not tracked** unless you use `git add -f`. **Do not force-add** unless Anna explicitly approves.
- **Staging:** Apply the same logic **manually** on the server or through your approved deployment process for `settings.php`.

---

## Local verification (DDEV)

Commands run after the fix:

- `php -l web/sites/default/settings.php` — **OK** (no syntax errors).
- `ddev drush cr` — **OK**.
- Reflection on `StripeService::getPlatformSecretKey()` (private method): **platform secret present: yes**; **prefix** `sk_test_...` (first eight characters only; do not log full keys).

---

## Staging operator procedure (exact steps)

1. **SSH** to staging.

2. Go to the Drupal root (adjust path if your host differs):

   ```bash
   cd ~/staging/current
   ```

3. **Patch** `web/sites/default/settings.php`: replace any block that assigns `getenv('STRIPE_PK') ?: ''` / `getenv('STRIPE_SK') ?: ''` **unconditionally** into Commerce Stripe gateway config with the **safe pattern** in § “Safe override pattern” above (preserve surrounding code; do not add secrets to the file).

4. **Clear cache:**

   ```bash
   ./vendor/bin/drush cr
   ```

5. **Verify** Stripe can resolve a platform secret **without printing it:**

   ```bash
   ./vendor/bin/drush php-eval '$service = \Drupal::service("myeventlane_core.stripe"); $ref = new ReflectionClass($service); $m = $ref->getMethod("getPlatformSecretKey"); $m->setAccessible(TRUE); try { $key = $m->invoke($service); echo "StripeService platform secret present: " . (!empty($key) ? "yes" : "no") . PHP_EOL; echo "Prefix: " . (!empty($key) ? substr($key, 0, 8) . "..." : "none") . PHP_EOL; } catch (\Throwable $e) { echo "StripeService error: " . $e->getMessage() . PHP_EOL; }'
   ```

6. If the result is **no**:

   - add the matching test **secret** (and publishable if needed) via **Drupal admin** → Commerce → **Payment gateways** → `stripe` (test mode), **or**
   - set **`MEL_STRIPE_SECRET_KEY`** in the **web** PHP process (PHP-FPM pool / host env), restart PHP or the web server, then run `drush cr` again.

7. Click **Connect Stripe** once on staging.

8. **Logs:**

   ```bash
   ./vendor/bin/drush ws --count=80 | grep -A4 -B2 -Ei "Stripe is not configured|MEL_STRIPE_SECRET_KEY|Stripe API error|Failed to create Stripe Connect account|Stripe Account Link created|Created AccountLink|managing losses"
   ```

**Expected when healthy:**

- No “platform secret key is missing” / “Stripe is not configured” for that reason.
- No **managing losses** message if the Connect **Platform Profile** is complete for the same Stripe test platform account.
- **Stripe Account Link created** / masked `acct_…` in logs where applicable.
- **No** full secret keys, **no** full `connect.stripe.com` Account Link URLs in logs.

---

## Related audits

- Earlier notes: [mel-stripe-staging-key-check.md](mel-stripe-staging-key-check.md) (gateway snapshot / UI-first fix context).

---

## Task 5

Do **not** start Task 5 from this document.
