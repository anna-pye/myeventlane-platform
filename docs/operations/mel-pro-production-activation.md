# MEL Pro production activation runbook

This runbook is a release gate. It does not authorise live charges, production
configuration changes, database writes, webhook creation, or secret rotation.

## Ownership boundaries

- Drupal Commerce Recurring is authoritative for MEL Pro subscription and
  entitlement state.
- `stripe_pe_recurring` owns MEL Pro card setup and off-session renewals.
- `stripe` owns ordinary checkout payment methods.
- Stripe Connect and payout webhooks remain separate from MEL Pro.
- Stripe is authoritative for payment-method processing. Signed webhooks are
  the provider outcome path; checkout completion pages are not.

## Automated gate

Run inside the release that is being assessed:

```bash
drush mel:pro-activation-readiness --environment=production
drush mel:pro-activation-readiness --environment=production --format=json
```

The command is read-only. A `FAIL` result blocks activation. A `MANUAL` result
must be evidenced by the operator before go-live.

The production gate expects:

- `stripe` and `stripe_pe_recurring` enabled in live mode;
- `pk_live_...` publishable keys and separate `rk_live_...` restricted keys
  for ordinary and recurring payments;
- signing secrets supplied at runtime, never in active or exported config;
- the 30-day trial and no-trial restart schedules;
- published A$49 trial and restart variations;
- 10% Australian GST with tax-inclusive display;
- Billing Portal enabled;
- Commerce Recurring, Stripe webhook and Pro Boost queues processed by cron;
- recent cron completion and both webhook ledger tables.

## Provider checks

In the live Stripe account, record evidence for all of the following:

1. The account ID and live-mode indicator match the approved MyEventLane
   production account.
2. The MEL Pro endpoint is exactly:
   `https://myeventlane.com.au/payment/notify/stripe_pe_recurring`.
3. The endpoint receives only the approved recurring events:
   `payment_intent.succeeded`, `payment_intent.payment_failed`,
   `payment_intent.canceled`, and `charge.refunded`.
4. The ordinary checkout and Connect/payout destinations are present and do
   not target the MEL Pro gateway.
5. Each endpoint that accepts signed events uses its own signing secret, and
   its Drupal runtime override is paired to that exact endpoint. The legacy
   `stripe` Card Element gateway does not consume a signing secret; do not
   point recurring events at it.
6. The Billing Portal is enabled in live mode. An authenticated organiser can
   open it and return to `/vendor/pro/manage`.

Do not paste secret values into tickets, pull requests, logs, or this runbook.

## Hosting checks

Before exposing production checkout:

- prove the production Drupal release and database bootstrap;
- prove hourly cron and the queue worker are running;
- confirm failed jobs are visible at the AdvancedQueue administration pages;
- configure HSTS at the HTTPS proxy;
- configure a tested Content Security Policy that permits the Stripe and Link
  origins required by the Payment Element without using `default-src *`;
- retain `X-Content-Type-Options: nosniff` and an appropriate referrer policy;
- confirm the production, staging and local environments use different Stripe
  keys and customer namespaces.

## Controlled acceptance

Use one approved production test organiser. Do not use a real customer.

1. Start the first and only trial. Confirm no charge is created that day.
2. Confirm the saved Pro payment method belongs to `stripe_pe_recurring`.
3. Confirm `/vendor/pro/manage`, the start email and renewal date agree.
4. Deliver one signed test-mode-equivalent production webhook using Stripe's
   supported testing controls. Confirm one ledger row and one state change.
5. Redeliver the same event. Confirm no duplicate state change or email.
6. Open Billing Portal, update the test payment method, and return safely.
7. Schedule cancellation, reactivate, then restore the approved baseline.

Live failed-payment or destructive cancellation tests require a separately
approved test window and restoration plan.

## Rollback

If activation fails:

1. Disable the MEL Pro production payment gateway. Do not disable ordinary
   ticket checkout or Connect gateways.
2. Leave the webhook endpoint available while outstanding signed deliveries
   are inspected; do not delete ledger rows.
3. Stop new Pro checkout entry points without removing existing Commerce
   subscriptions or organiser records.
4. Restore the previous immutable application release.
5. Run database updates only when the release notes explicitly require them;
   schema rollback is not assumed safe.
6. Re-run the readiness command and inspect failed queues before reopening.
7. Notify affected organisers only from an approved, deduplicated message
   template.

## Approval record

Activation requires named approval for:

- production release revision;
- live Stripe account and restricted keys;
- live webhook destinations and signing secrets;
- GST, invoice and ABN presentation;
- controlled acceptance organiser;
- rollback owner and support contact.
