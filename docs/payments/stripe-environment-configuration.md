# Stripe environment configuration

**Date:** 12 July 2026  
**Mode on DDEV:** `test` (do not switch to live in this remediation)

## Audit reproduction

At task start / after restoring empty keys in sync, `drush config:status` may report:

- `commerce_payment.commerce_payment_gateway.stripe` — Different  
- `commerce_payment.commerce_payment_gateway.stripe_pe_recurring` — Different  

**Expected.** Committed sync keeps `publishable_key` / `secret_key` **empty**. Active DDEV holds test keys via DB and/or `MEL_STRIPE_*` / `STRIPE_*` settings overrides. Do **not** `drush cex` gateways from DDEV into Git. Do **not** `drush cim` those two objects on a working local site if that would wipe test credentials — import only when deliberately resetting to empty + env injection.

Structural non-secret fields (`mode: test`, payment method types, etc.) remain in sync. Optional follow-up: export only non-secret structural keys (`authentication_method`, `express_checkout`, …) without secrets.

## Setting ownership

| Setting | Ownership | Classification |
|---------|-----------|----------------|
| Gateway entity IDs (`stripe`, `stripe_pe_recurring`, `mel_stripe_cc`) | Committed `config/sync` | Non-secret structure |
| `mode` (`test` / `live`) | Committed config (currently `test`) | Environment-specific non-secret — keep `test` in sync; live only via controlled deploy decision |
| `publishable_key` / `secret_key` | Prefer env override; **test keys currently also present in `config/sync`** | (1) expected env secret + (5) incorrect repo secret residue |
| `webhook_signing_secret` | Env `MEL_STRIPE_WEBHOOK_SECRET` via `settings.mel_shared_session.php` | (1) expected env secret |
| `access_token` / `stripe_user_id` (Connect) | Active/DB or Connect OAuth — empty in sync | (1)/(6) Connect state |
| Platform Connect account type / fee model | Product-owned; do not change without sign-off | (6) |

## Environment injection (authoritative for secrets)

Tracked `web/sites/default/settings.mel_shared_session.php` merges when non-empty:

- `MEL_STRIPE_SECRET_KEY`
- `MEL_STRIPE_PUBLISHABLE_KEY`
- `MEL_STRIPE_WEBHOOK_SECRET`

`settings.php` additionally applies `STRIPE_PK` / `STRIPE_SK` when non-empty (does not wipe with empty strings).

DDEV: set in gitignored `.ddev/config.local.yaml` `web_environment`.  
Staging/production: PHP-FPM / host env / secret store — never commit live keys.

## What this task did / did not do

- **Did not** export or commit new API keys  
- **Did not** switch test → live  
- **Did not** create/delete payment gateways  
- **Did not** overwrite local test credentials  
- Documented that **test** publishable/secret keys remain in `config/sync` today — treat as technical debt; rotate if those keys are shared beyond local/staging test accounts, and prefer empty placeholders + env-only injection in a follow-up

## Deployment actions

1. Ensure staging/production define `MEL_STRIPE_*` (and webhook secret for Payment Element).  
2. After config import, confirm gateways still `mode: test` on non-production.  
3. Do not run `drush cex` of gateway secrets from production into Git.
