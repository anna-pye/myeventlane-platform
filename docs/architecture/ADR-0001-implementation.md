# ADR-0001 Implementation: Canonical MEL Event Checkout

## Status

Implemented with validation notes.

## Decision Implemented

ADR-0001 selected Architecture B. `mel_event_checkout` is the canonical checkout flow for event ticket checkout, and `commerce_order.commerce_order_type.default` must point to that flow.

## Files Changed

- `config/sync/commerce_order.commerce_order_type.default.yml` — exported order-type checkout assignment now points to `mel_event_checkout`.
- `web/modules/custom/myeventlane_checkout_flow/config/install/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml` — fresh-install checkout-flow pane contract now matches the canonical exported MEL flow.
- `tests/e2e/scripts/prepare-fixture.php` — E2E fixture now asserts canonical checkout config instead of mutating active configuration.
- `tests/e2e/README.md` — setup now requires canonical config import rather than update hooks forcing the checkout flow.
- `docs/architecture/ADR-0001-canonical-checkout-flow.md` — ADR status and implementation direction reflect accepted Architecture B.
- `docs/PHASE_1_CHECKOUT_FLOW_IMPLEMENTATION.md` — obsolete Commerce default plugin assignment instructions replaced with canonical order-type verification.
- `docs/audits/launch-friction-audit.md` — checkout-flow config drift marked resolved.
- `docs/audits/workflow-experience-audit.md` — checkout-flow config drift marked resolved.
- `docs/launch/customer-acceptance/customer-acceptance.md` — customer checkout evidence names `mel_event_checkout`.
- `docs/architecture/ADR-0001-implementation.md` — records implementation, validation, risk, and rollback.

## Configuration Changed

- Active configuration was updated with:

```bash
ddev drush cset commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow mel_event_checkout -y
ddev drush cex -y
```

- Exported sync config now sets:

```yaml
third_party_settings:
  commerce_checkout:
    checkout_flow: mel_event_checkout
```

- Fresh-install optional order-type config already sets `checkout_flow: mel_event_checkout`.
- Fresh-install checkout-flow install config now matches the exported MEL checkout-flow pane placement for the canonical panes:
  - `mel_buyer_details` on `checkout`
  - `ticket_holder_paragraph` on `checkout`
  - `mel_legal_consent` on `checkout`
  - `payment_information` on `checkout`
  - `order_summary` on `_sidebar`
  - default contact/billing/legacy attendee panes disabled

## Tests Updated

- `tests/e2e/scripts/prepare-fixture.php` no longer edits `commerce_order.commerce_order_type.default`.
- The E2E fixture fails loudly if the active order-type checkout flow is not `mel_event_checkout`.
- `tests/e2e/README.md` now documents config import as the setup path for the canonical checkout assignment.

## Validation Performed

### Composer and Drupal config

- `composer validate` on the host did not complete because `vendor/composer/installed.json` was not readable from the host environment.
- `ddev composer validate` passed: `./composer.json is valid`.
- `ddev drush cr` passed.
- `ddev drush config:status` passed after implementation: no differences between DB and sync.
- `ddev drush cget commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow` returned `mel_event_checkout`.
- `ddev drush cget commerce_checkout.commerce_checkout_flow.mel_event_checkout` returned the expected `mel_event_checkout` entity, plugin, panes, and sidebar order summary.

### PHPUnit

- Focused checkout/customer/ticket governance slice passed:

```bash
ddev exec bash -lc 'cd web && ../vendor/bin/phpunit -c phpunit-governance.xml --filter "MelCheckout|OperationalCheckout|CustomerHubPageAccount|TicketBacked|OperationalCartProjection|OperationalOrderItemDisplay"'
```

Result: 60 tests, 1240 assertions, OK. PHPUnit reported 1 warning and 128 PHPUnit deprecations.

- Refund kernel tests did not execute assertions because the existing test service graph is incomplete:

```bash
ddev exec bash -lc 'SIMPLETEST_DB=sqlite://localhost/:memory: ./vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_refunds/tests/src/Kernel/RefundAccessTest.php web/modules/custom/myeventlane_refunds/tests/src/Kernel/BuyerRefundFormTest.php web/modules/custom/myeventlane_refunds/tests/src/Kernel/VendorRefundFormAccessTest.php --do-not-cache-result'
```

Result: 5 errors, 0 assertions. Root cause: `myeventlane_checkout_flow.checkout_ux_attacher` depends on missing service `myeventlane_surface.state_readiness_helper` in the refund kernel test container. This was not caused by checkout-flow config mutation, but it leaves refund kernel validation incomplete.

### E2E

- E2E fixture preparation passed:

```bash
ddev drush php:script tests/e2e/scripts/prepare-fixture.php
```

The script returned the paid fixture and did not mutate checkout-flow config.

- First `npm run test:e2e` attempt failed inside the sandbox during `npm ci` with EPERM unlink errors in `tests/e2e/node_modules`.
- Retried outside the sandbox. The first Playwright run reached `/checkout/{order}/complete` but failed because the test expected paid confirmation copy before manual payment was received. The page correctly showed pending-payment copy: `Order received` and `We’re waiting for payment confirmation`.
- The E2E assertion was updated to preserve payment integrity:
  - manual payment expects pending-payment completion copy before simulated payment receipt
  - Stripe test mode still expects paid confirmation copy
- Final `npm run test:e2e` passed: 1 test, manual payment mode.
- E2E teardown restored payment gateways, but the earlier direct fixture run left two gateway configs drifted. A final `ddev drush cim -y && ddev drush cr && ddev drush config:status` restored active config to sync and confirmed no differences.

### Static checks

- Cursor lints reported no diagnostics for the edited E2E TypeScript/PHP files after adding payment gateway PHPDoc annotations.
- `ddev exec php -l tests/e2e/scripts/prepare-fixture.php` passed with no syntax errors.

## Manual Verification Completed

Evidence-backed checks completed:

- Manual payment E2E checkout reached checkout completion.
- Pending manual-payment completion copy was shown before payment receipt.
- Ticket holder capture, legal consent, order summary, sidebar, and checkout completion were exercised by the passing E2E test.
- The E2E test simulated manual payment receipt, then verified the order state was `completed` and at least one `myeventlane_ticket` existed for the holder email.
- Active and sync configuration agreed after validation, with `mel_event_checkout` as the canonical order-type checkout flow.

Manual scenarios still requiring explicit evidence before launch sign-off:

- Paid Stripe checkout
- Failed payment
- Free RSVP
- Confirmation email delivery
- My Tickets browser journey beyond the issued ticket/order-link evidence in E2E
- Refund request browser journey
- Refund kernel assertions after the test service graph is fixed

## Known Risks

- Commerce checkout behaviour changes from the pre-decision active `default` flow to the custom MEL single-step flow.
- Payment pane placement and checkout step sequence must be validated with Stripe and manual gateways.
- Ticket-holder capture and legal consent depend on the MEL checkout panes persisting data before order placement.
- Production or staging environments that still run Commerce default checkout need config import and regression validation before launch.
- Manual Commerce validation cannot be marked PASS without direct evidence.

## Rollback Procedure

### Configuration Rollback

1. Restore the previous order-type checkout assignment:

```bash
ddev drush cset commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow default -y
ddev drush cex -y
ddev drush cr
```

2. Confirm rollback:

```bash
ddev drush cget commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow
ddev drush config:status
```

Expected rollback value: `default`.

### Git Rollback

1. Revert this task's focused config, test, and documentation changes.
2. Re-import configuration if the rollback is applied from Git:

```bash
ddev drush cim -y
ddev drush cr
```

### Checkout Validation

1. Run checkout smoke tests for the restored flow.
2. Review draft orders created during the implementation or rollback window.
3. Confirm checkout completion still reaches the expected completion route.

### Payment Validation

1. Confirm manual gateway checkout still reaches the expected payment/order state.
2. Confirm Stripe test checkout still uses test mode and does not expose live keys.
3. Confirm failed and pending payments do not show paid confirmation states.

### Ticket Validation

1. Confirm paid orders still trigger ticket issuance.
2. Confirm My Tickets can load the issued tickets.
3. Confirm refund request access and ownership checks still apply.
