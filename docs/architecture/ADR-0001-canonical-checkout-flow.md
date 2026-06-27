# ADR-0001: Canonical Checkout Flow

## Status

Accepted.

Product has selected Architecture B: `mel_event_checkout` is the canonical checkout flow for event ticket checkout. Implementation details are recorded in `docs/architecture/ADR-0001-implementation.md`.

## Background

MyEventLane previously had two checkout-flow architectures represented in the repository:

- Commerce default checkout flow: `commerce_checkout.commerce_checkout_flow.default`.
- MEL custom event checkout flow: `commerce_checkout.commerce_checkout_flow.mel_event_checkout`, plugin `mel_event_checkout`.

The pre-decision active configuration and exported sync assigned the Commerce order type `default` to the Commerce checkout flow `default`. At the same time, the repository contained a custom `mel_event_checkout` plugin, active checkout-flow configuration, install configuration, optional order-type configuration, post-update code, runtime form alters, tests, and documentation that assumed the MEL custom flow existed.

The contradiction was architectural, not a runtime hotfix. Product resolved it by selecting Architecture B.

## Evidence

### Pre-decision active and exported order-type configuration

`ddev drush config:get commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow` returned:

```text
'commerce_order.commerce_order_type.default:third_party_settings.commerce_checkout.checkout_flow': default
```

`config/sync/commerce_order.commerce_order_type.default.yml` also points to Commerce default:

```yaml
third_party_settings:
  commerce_checkout:
    checkout_flow: default
```

`ddev drush config:status --format=table` returned:

```text
[notice] No differences between DB and sync directory.
```

Therefore, before Architecture B implementation, local active configuration and exported configuration agreed: event ticket carts using order type `default` routed through the Commerce `default` checkout flow.

### Ticket cart creation uses order type `default`

`TicketSelectionForm::submitForm()` creates or loads carts using order type `default`:

```php
$cart = $this->cartProvider->getCart('default', $store)
  ?: $this->cartProvider->createCart('default', $store);
```

Evidence path: `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`.

### `mel_event_checkout` plugin exists

The plugin class exists and declares the plugin ID `mel_event_checkout`:

```php
/**
 * Provides a single-page checkout flow for MyEventLane events.
 *
 * This flow presents all checkout panes in a single step with a sidebar
 * for order summary and fee transparency, creating a "single page feel"
 * similar to Humanitix.
 *
 * @CommerceCheckoutFlow(
 *   id = "mel_event_checkout",
 *   label = @Translation("MyEventLane Event Checkout"),
 * )
 */
final class MelEventCheckoutFlow extends CheckoutFlowWithPanesBase {
```

Evidence path: `web/modules/custom/myeventlane_checkout_flow/src/Plugin/Commerce/CheckoutFlow/MelEventCheckoutFlow.php`.

The plugin adds a single `checkout` step, marks it as sidebar-enabled, sets the primary label to "Complete booking", and adds wrapper classes `mel-checkout-single-page` and `mel-checkout-flow-mel-event`.

### `mel_event_checkout` configuration entity exists

Active configuration contains `commerce_checkout.commerce_checkout_flow.mel_event_checkout`. The command:

```bash
ddev drush config:get commerce_checkout.commerce_checkout_flow.mel_event_checkout
```

returned a checkout flow with:

- `id: mel_event_checkout`
- `plugin: mel_event_checkout`
- `label: MyEventLane Event Checkout`
- panes on the `checkout` step: `mel_buyer_details`, `ticket_holder_paragraph`, `mel_legal_consent`, `payment_information`
- sidebar `order_summary`
- disabled `contact_information`, `billing_information`, `myeventlane_attendee_info_per_ticket`

Exported sync also contains the same entity:

- `config/sync/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`

### Fresh install would still provision `mel_event_checkout`

The custom module install config contains:

- `web/modules/custom/myeventlane_checkout_flow/config/install/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`

The custom module install hook also creates the same config entity manually:

- `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.install`

The install hook sets:

- `id: mel_event_checkout`
- `label: MyEventLane Event Checkout`
- `plugin: mel_event_checkout`
- single `checkout` step panes
- disabled Commerce default contact/billing panes

The module also contains optional config assigning the default order type to `mel_event_checkout`:

- `web/modules/custom/myeventlane_checkout_flow/config/optional/commerce_order.commerce_order_type.default.yml`

Therefore, a fresh install of this module still provisions `mel_event_checkout` and attempts to make it the order type checkout flow when optional config applies.

### Historical post-update attempts to switch the order type

`myeventlane_checkout_flow_post_update_use_mel_event_checkout()` still attempts to change `commerce_order.commerce_order_type.default` to `mel_event_checkout`:

```php
function myeventlane_checkout_flow_post_update_use_mel_event_checkout(array &$sandbox): void {
  $config = \Drupal::configFactory()->getEditable('commerce_order.commerce_order_type.default');
  $third_party = $config->get('third_party_settings.commerce_checkout') ?? [];
  $third_party['checkout_flow'] = 'mel_event_checkout';
  $config->set('third_party_settings.commerce_checkout', $third_party);
  $config->save();
}
```

Evidence path: `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.post_update.php`.

Local post-update bookkeeping includes:

```text
311=myeventlane_checkout_flow_post_update_checkout_shell
312=myeventlane_checkout_flow_post_update_use_mel_event_checkout
```

But active config remains `default`, and `ddev drush updatedb:status --format=table` returned no pending updates. This suggests the update was recorded as run and the current active/sync config later returned to `default`, or that the sync import became authoritative after the update had run.

### Runtime references

Runtime code still contains a form alter specifically for the custom checkout-flow form ID:

```php
/**
 * Implements hook_form_FORM_ID_alter() for commerce_checkout_flow_mel_event_checkout.
 */
function myeventlane_checkout_flow_form_commerce_checkout_flow_mel_event_checkout_alter(array &$form, FormStateInterface $form_state): void {
```

Evidence path: `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.module`.

That alter:

- forces `commerce_checkout_form__with_sidebar`
- adds a "Back to cart" link when there is no previous checkout step
- attaches checkout UX helpers
- attaches fast checkout UI
- handles complete-step sidebar cleanup

If Commerce default is canonical, this form alter does not target the active checkout-flow form ID and its UX additions are not applied to default checkout unless duplicated elsewhere.

Theme templates also explicitly account for MEL custom panes:

- `web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-form.html.twig`
- `web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-form--with-sidebar.html.twig`

Both reference panes such as `mel_buyer_details`, `mel_legal_consent`, `ticket_holder_paragraph`, and `payment_information`.

### Test coverage and E2E assumptions

The E2E checkout README says:

```text
ddev drush config:import -y   # imports canonical mel_event_checkout assignment
```

It also states that the test:

```text
Proceeds to MEL single-page checkout (`mel_event_checkout`)
```

Evidence path: `tests/e2e/README.md`.

The E2E fixture script mutates active config to enforce the MEL custom checkout flow:

```php
// Ensure ticket carts use the MEL single-page checkout flow (not Commerce default).
$orderTypeConfig = \Drupal::configFactory()->getEditable('commerce_order.commerce_order_type.default');
$thirdParty = $orderTypeConfig->get('third_party_settings.commerce_checkout') ?? [];
if (($thirdParty['checkout_flow'] ?? 'default') !== 'mel_event_checkout') {
  $thirdParty['checkout_flow'] = 'mel_event_checkout';
  $orderTypeConfig->set('third_party_settings.commerce_checkout', $thirdParty);
  $orderTypeConfig->save(TRUE);
}
```

Evidence path: `tests/e2e/scripts/prepare-fixture.php`.

This is strong evidence that E2E checkout tests currently assume Architecture B, even though repository sync and active config currently run Architecture A.

### Customer journey evidence

The paid ticket journey is:

1. `/event/{node}/book`
2. `BookController::book()`
3. `TicketSelectionForm`
4. Commerce cart page
5. `commerce_checkout.form`
6. Checkout completion
7. ticket issuance on `OrderEvents::ORDER_PAID`

Evidence appears in:

- `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php`
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- `web/modules/custom/myeventlane_tickets/src/EventSubscriber/OrderPaidSubscriber.php`
- `web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-completion.html.twig`

The checkout flow choice affects step 5: pane sequence, form ID, form alter targeting, checkout UX, guest buyer details, attendee capture placement, legal consent placement, payment pane placement, and sidebar behaviour.

Ticket issuance itself is downstream of payment/order events and is not directly tied to the checkout-flow plugin by repository evidence.

### Launch validation evidence

`docs/launch/launch-certification/launch-validation.md` records:

- `ddev composer validate` passed
- `ddev drush status` bootstrapped
- `ddev drush config:status` had no differences
- `ddev drush cr` rebuilt clean
- specific organiser/customer route checks passed

It does not record an explicit checkout-flow assertion such as:

```bash
ddev drush config:get commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow
```

It also does not record a live checkout regression run against either Architecture A or Architecture B.

## Original Intent

Repository evidence indicates `mel_event_checkout` was introduced as a custom single-page checkout flow for MyEventLane events.

Primary evidence:

- `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.info.yml` describes the module as: "Custom single-page checkout flow for MyEventLane events. Provides Humanitix-level checkout experience with grouped panes, donation support, and fee transparency."
- `MelEventCheckoutFlow.php` describes the plugin as creating a single-page feel similar to Humanitix.
- `docs/PHASE_1_CHECKOUT_FLOW_IMPLEMENTATION.md` states the purpose was "Custom single-page checkout flow for MyEventLane events" with plugin ID `mel_event_checkout`.
- The same implementation summary says the flow type is "Single-step checkout with sidebar support" and lists pane order as buyer details, attendee details, donation, legal consent, payment.
- The module optional config assigns `commerce_order.commerce_order_type.default` to `mel_event_checkout`.
- The post-update explicitly says: "Switch default order type to use mel_event_checkout flow."
- E2E fixtures assert ticket carts use MEL single-page checkout, not Commerce default.

The original installation instructions in `docs/PHASE_1_CHECKOUT_FLOW_IMPLEMENTATION.md` were inconsistent with the later exported architecture because they targeted `commerce_checkout.commerce_checkout_flow.default`. The canonical Architecture B implementation uses the separate checkout-flow config entity `mel_event_checkout` and assigns order type `default` to that entity.

Repository evidence supports this conclusion:

- `mel_event_checkout` was intended to replace the active event order checkout flow for ticket purchases.
- It was implemented as a separate checkout-flow entity and plugin.
- It was not merely an extension of the Commerce default flow in the current repository, because the custom form alter targets `commerce_checkout_flow_mel_event_checkout`, not Commerce default.

## Alternatives

### Architecture A: Commerce default is canonical (rejected)

#### Description

Order type `default` would have continued to use `commerce_checkout.commerce_checkout_flow.default`, plugin `multistep_default`. Product rejected this architecture because it left MEL checkout plugin, form alter, custom panes, tests, and fresh-install config in conflict with the canonical runtime path.

#### Current usage

Pre-decision active config and `config/sync` used this architecture:

- `commerce_order.commerce_order_type.default` -> `checkout_flow: default`
- `commerce_checkout.commerce_checkout_flow.default` -> `plugin: multistep_default`

#### Advantages

- Matched active configuration and exported config before the Architecture B implementation.
- Matches clean `drush config:status`.
- Avoided immediate order-type configuration change.
- Uses Drupal Commerce's standard multistep checkout plugin.
- Lower risk of custom checkout-flow plugin defects.
- Lower risk if production already operates successfully on Commerce default.

#### Disadvantages

- Contradicts module metadata, install config, optional config, post-update code, E2E setup, and several architecture/audit documents.
- Leaves `MelEventCheckoutFlow` unused for ticket checkout unless another order type uses it.
- Leaves `myeventlane_checkout_flow_form_commerce_checkout_flow_mel_event_checkout_alter()` stranded for default checkout.
- May bypass MEL custom checkout UX attachment, fast checkout attachment, guided sidebar template forcing, and custom single-page form classes.
- May not surface custom panes such as `mel_buyer_details`, `ticket_holder_paragraph`, and `mel_legal_consent` unless separately configured into Commerce default.
- Creates ongoing confusion for future engineers and tests.

#### Migration effort

If Product had chosen Architecture A, this would have been mainly cleanup and retargeting work:

- Confirm default checkout has all required buyer, attendee, legal, payment, and ticket-holder behaviour.
- Decide whether custom panes remain in use on Commerce default or become obsolete.
- Remove or rewrite obsolete `mel_event_checkout` plugin/config/update/test references only after live checkout proof.
- Update E2E setup so it no longer mutates active config to `mel_event_checkout`.
- Update documentation that incorrectly states `mel_event_checkout` is active.

Estimated effort would have been medium. The risk was not deletion itself; the risk was proving that default checkout provided the complete MEL ticket purchase contract.

#### Risk

Architecture A remained high risk because runtime code and E2E scripts assumed `mel_event_checkout`.

Payment risk would have been indirect: choosing Architecture A should not have altered Stripe/payment code, but it may have changed which panes and form alters were present before payment submission.

Order risk would have been moderate: order type would have stayed unchanged, but custom buyer/attendee/legal collection may have been absent or differently sequenced.

#### Future maintenance

Simplifies Commerce alignment if the MEL custom flow is removed. Maintenance improves only if obsolete plugin/config/docs/tests are removed and the default flow is documented as canonical.

If obsolete references remain, this architecture preserves the current ambiguity.

### Architecture B: `mel_event_checkout` is canonical (accepted)

#### Description

Order type `default` uses checkout-flow entity `mel_event_checkout`, plugin `mel_event_checkout`. Ticket purchases use the MEL custom single-step checkout with sidebar and custom panes.

#### Current usage

The architecture exists and is supported by code/config/docs/tests. ADR-0001 implementation makes it the active and exported order-type assignment.

Repository elements supporting this architecture:

- `MelEventCheckoutFlow.php`
- `commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`
- module install config
- module optional order-type config
- post-update
- form alter targeting `commerce_checkout_flow_mel_event_checkout`
- E2E fixture setup
- multiple audit and architecture documents

#### Advantages

- Aligns with original custom checkout intent.
- Aligns with module metadata and E2E test assumptions.
- Activates MEL-specific form alter and guided sidebar behaviour.
- Supports a single-step event checkout with buyer details, attendee details, legal consent, payment, and sidebar order summary.
- Matches MEL’s documented Humanitix-level checkout UX direction.

#### Disadvantages

- Requires an explicit order-type configuration change from the current active/sync state.
- Changes active checkout behaviour and must be treated as Commerce/high-risk.
- Could affect payment pane placement, guest checkout path, and checkout step sequence.
- Requires config export/import discipline and full regression validation.
- May expose hidden assumptions in production if production has been operating on Commerce default.

#### Migration effort

The minimum Architecture B implementation is config canonicalisation:

- Set `commerce_order.commerce_order_type.default` checkout flow to `mel_event_checkout`.
- Export config.
- Verify no config drift.
- Confirm post-update cleanup strategy because the post-update is already recorded locally.
- Run live checkout regression tests across paid, manual gateway, Stripe gateway, zero-balance/free, donation, ticket-holder, legal consent, completion, email, and ticket issuance paths.

Estimated effort: medium to high, because the config edit is small but validation blast radius is Commerce checkout.

#### Risk

High until validated in staging.

Payment risk is moderate to high because the checkout pane sequence and form alter path change before payment submission.

Order-type risk is high because `default` is the ticket cart order type and is also used by many tests and services.

#### Future maintenance

Keeps MEL’s checkout UX in a dedicated plugin and config entity. Maintenance is cleaner if documentation, tests, and config are reconciled to make `mel_event_checkout` unambiguously canonical.

Long-term maintenance requires treating checkout-flow config as a governed Commerce contract and adding explicit tests for the assigned flow.

## Runtime Impact If Switching To `mel_event_checkout`

### Checkout panes

Expected active pane sequence changes from Commerce multistep defaults to a single `checkout` step with:

- `mel_buyer_details`
- `ticket_holder_paragraph`
- `mel_legal_consent`
- `payment_information`
- sidebar `order_summary`

`contact_information`, `billing_information`, and `myeventlane_attendee_info_per_ticket` are disabled in the MEL flow.

### Payment flow

Repository evidence does not show a change to payment gateway plugins or Stripe APIs from switching the checkout-flow assignment. The payment pane remains Commerce `payment_information`.

However, payment pane placement and form context change. That must be validated with:

- Stripe Payment Element
- Manual gateway
- failed payment
- pending payment
- zero-balance/free or no-payment cases if supported

### Order completion

The custom flow changes checkout step structure and form alter targeting. Completion copy is largely theme/presenter governed, but completion route behaviour must be re-tested because the active flow changes step sequence.

### Emails

Repository evidence ties emails to order events and messaging services, not directly to checkout-flow plugin. Still, checkout flow can affect whether orders reach the same state transitions and whether required buyer details are present, so order confirmation and ticket delivery emails must be validated.

### Tickets

Ticket issuance is tied to `OrderEvents::ORDER_PAID` through `OrderPaidSubscriber` and `TicketIssuer`. Repository evidence does not show direct dependency on `mel_event_checkout`.

Ticket-holder capture does depend on checkout panes and order item fields. The `ticket_holder_paragraph` pane must be present and must persist attendee data before order placement.

### Checkout UX

Switching to `mel_event_checkout` activates:

- `mel-checkout-single-page`
- `mel-checkout-flow-mel-event`
- `commerce_checkout_form__with_sidebar`
- "Back to cart" suffix on the primary action
- checkout UX attachment
- fast checkout section attachment
- complete-step sidebar cleanup

### Events

No event entity model change is evidenced. The event booking path still creates cart items through `TicketSelectionForm`.

### Commerce compatibility

`MelEventCheckoutFlow` extends `CheckoutFlowWithPanesBase`, so it remains Commerce-native. It is custom code and must stay compatible with Commerce checkout pane expectations.

### Extensions

Modules depending on `myeventlane_checkout_flow` may continue to use services and routes unrelated to the custom checkout-flow plugin, such as My Tickets and vendor attendee/check-in services. Choosing Architecture A does not automatically make the whole module obsolete.

### Validation

Architecture B requires full checkout validation, not only config validation.

### Tests

E2E tests assume Architecture B and assert that the repository already has the expected flow. They must not mutate checkout-flow config in fixture setup.

### Security

No direct route access or entity access change is implied by the flow assignment. Security risk is in checkout data collection and payment submission: buyer details, legal consent, attendee details, and payment pane behaviour must remain server-side validated.

## Accepted Implementation Direction

Architecture B is selected. The `mel_event_checkout` plugin, flow configuration, module install configuration, optional order-type configuration, form alter, checkout panes, and E2E checkout assumptions are canonical Architecture B assets.

The `myeventlane_checkout_flow` module also owns related customer and organiser surfaces:

- My Tickets routes
- order detail/tax invoice routes
- vendor attendee/check-in routes and services
- checkout summary/pricing presentation services
- rate limiting subscriber for `commerce_checkout.form`
- fast checkout access/services

These are not obsolete and must not be removed as part of Architecture B canonicalisation.

### Architecture B implementation tasks

Required implementation tasks:

1. Set `commerce_order.commerce_order_type.default` to `checkout_flow: mel_event_checkout`.
2. Export config to `config/sync`.
3. Add an explicit test/assertion that default ticket orders use `mel_event_checkout`.
4. Remove E2E fixture mutation and replace it with an assertion.
5. Keep the historical post-update as deployment-history context unless a future release needs a new idempotent update.
6. Reconcile docs to state that `mel_event_checkout` is canonical.
7. Verify the custom flow on a fresh install and config import path.

Risk:

- High until staging checkout regression passes.
- Payment pane placement changes relative to current active default flow.
- Checkout step sequence changes relative to current active default flow.
- Guest checkout details and legal consent must be verified.
- Production may currently be operating on Commerce default; switching to custom flow is an active behaviour change.

Validation:

- `composer validate`
- `ddev drush cr`
- `ddev drush config:status`
- `ddev drush config:get commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow`
- `ddev drush config:get commerce_checkout.commerce_checkout_flow.mel_event_checkout`
- paid ticket checkout
- manual gateway checkout
- Stripe gateway checkout
- failed payment state
- pending payment state
- zero-balance/free order if supported
- attendee details required/optional cases
- legal consent required case
- checkout completion copy
- order confirmation email
- ticket issuance after payment
- refund request route after purchase
- My Tickets/order detail route after purchase
- E2E checkout test without runtime config mutation

Rollback:

- Revert order-type config to `checkout_flow: default`.
- Re-import config.
- Rebuild caches.
- Re-run checkout smoke tests.
- Confirm no stuck draft orders require manual handling.

## Validation Plan For Architecture B

Before and after implementation, run these checks on every target environment:

```bash
ddev drush config:get commerce_order.commerce_order_type.default third_party_settings.commerce_checkout.checkout_flow
ddev drush config:get commerce_checkout.commerce_checkout_flow.mel_event_checkout
ddev drush updatedb:status
ddev drush config:status
```

For staging/production, capture equivalent Drush output before changing anything.

After implementation, validation must prove:

- no configuration drift
- expected checkout-flow assignment
- no payment regression
- no order-type regression
- no checkout-flow regression
- no ticket issuance regression
- no email regression
- no customer ticket recovery regression

## Rollback Plan

Because both architectures are present in Git, rollback is config-led unless code/config is removed.

1. Revert `commerce_order.commerce_order_type.default` to `checkout_flow: default`.
2. Re-import config.
3. Rebuild caches.
4. Run checkout smoke tests.

Rollback must include review of draft orders created during the test window.

## Decision Accepted

Product and engineering have selected:

- Architecture B: `mel_event_checkout` is canonical.

ADR-0001 implementation must leave no conflicting source of truth:

- active/sync order type says MEL custom checkout
- plugin/config/install/update/tests/docs say MEL custom checkout

No architectural redesign is authorised by this ADR. Future checkout work must preserve payment integrity, ticket issuance integrity, refund integrity, and Drupal Commerce checkout contracts.
