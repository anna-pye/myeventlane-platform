# MyEventLane E2E checkout tests

Playwright end-to-end coverage for the critical customer path:

**browse event → add ticket → checkout → order confirmation → issued ticket**

## Prerequisites

### DDEV state

1. DDEV project running: `ddev start`
2. Site reachable at `https://myeventlane.ddev.site` (or set `MEL_E2E_BASE_URL`)
3. Config imported and cache warm:

```bash
ddev drush updb -y   # ensures default order type uses mel_event_checkout
ddev drush cr
```

4. `myeventlane_seed` enabled (fixture seeder):

```bash
ddev drush pm:enable myeventlane_seed -y
```

Global setup runs `drush mel:seed-events --use-settings` automatically if the paid fixture is missing.

### Test user

No login is required. The test completes checkout as a **guest** with a unique email:

- Pattern: `e2e-buyer+{timestamp}@example.test`
- Override: `MEL_E2E_BUYER_EMAIL=you@example.test`

### Test event fixture

Default paid fixture (from `myeventlane_seed`):

| Field | Value |
|--------|--------|
| Title | `[MEL TEST] Event 8 - Paid` |
| Book path | `/event/{nid}/book` (resolved at runtime) |
| Public alias | `/events/mel-test-event-8-paid` (typical after seed) |
| Ticket tiers | General admission, Concession, Early bird |
| Attendee questions | Optional only (dietary + “how did you hear”) |

Override title: `MEL_E2E_EVENT_TITLE='[MEL TEST] Event 8 - Paid'`

Seed manually:

```bash
ddev drush mel:seed-events --use-settings
```

### Payment mode

Controlled by `MEL_E2E_PAYMENT_MODE` (default: **`manual`**).

| Mode | Gateway | Notes |
|------|---------|--------|
| `manual` (default) | `mel_stripe_cc` (Commerce Manual) | Deterministic; no Stripe keys. Global setup temporarily disables Stripe gateways and restores them in teardown. After checkout, the test runs `complete-manual-payment.php` to apply the Manual gateway **receive** transition so `ORDER_PAID` fires and tickets issue (simulates vendor marking payment received). |
| `stripe_test` | `stripe` in **test** mode | Requires `MEL_STRIPE_PUBLISHABLE_KEY` and `MEL_STRIPE_SECRET_KEY` in DDEV `web_environment`. Uses card `4242…`. |

**Do not use live Stripe keys.** Test mode only when Stripe is involved.

## Install

From repository root:

```bash
cd tests/e2e
npm ci
npm run install:browsers   # required once per machine / after Playwright upgrades
```

Or from repository root:

```bash
npm run test:e2e:install
```

## Run

From repository root:

```bash
npm run test:e2e
```

Or from `tests/e2e`:

```bash
npm test
# headed debug
npm run test:headed
```

With explicit payment mode:

```bash
MEL_E2E_PAYMENT_MODE=manual npm run test:e2e
MEL_E2E_PAYMENT_MODE=stripe_test npm run test:e2e
```

## What the test asserts

1. Opens the seeded paid event book page
2. Adds one ticket and continues to cart
3. Proceeds to MEL single-page checkout (`mel_event_checkout`)
4. Fills buyer details, ticket holder identity, and legal consent
5. Completes payment (manual or Stripe test)
6. Sees confirmation hero: **“Great choice. You're all set.”**
7. Verifies via Drush that the order is `completed` and at least one `myeventlane_ticket` exists for the holder email

## Framework audit

| Tool | Present in repo? |
|------|------------------|
| Playwright | **Yes** — `tests/e2e/` (also ad-hoc in `scripts/audit/*-browser-verification.mjs`) |
| Cypress | No |
| Behat | No |
| Nightwatch | No |

## CI note

These tests expect a bootstrapped DDEV site with the seed fixture. They are not wired into `.github/workflows/reusable-build.yml` yet. Run locally before release or add a DDEV job when CI runners support it.

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Fixture missing | `ddev drush mel:seed-events --use-settings` |
| Manual gateway missing | `ddev drush config:get commerce_payment.commerce_payment_gateway.mel_stripe_cc status` |
| Stripe test fails | Keys in `.ddev/config.local.yaml` `web_environment`; gateway mode `test` |
| Checkout validation errors | Event sold out or sales window closed — re-seed or pick another paid `[MEL TEST]` event |
