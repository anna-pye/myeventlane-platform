# Commerce Stripe review-step compatibility (MEL)

## Problem

Paid checkout showed billing + gateway selection, but Stripe Payment Element
card fields never appeared (`drupalSettings.commerceStripePaymentElement`
undefined; no `commerce_stripe/payment_element` library).

## Root causes (verified)

1. **`stripe_connect` annotation omitted `offsite-payment`.** Drupal plugin
   annotations do not inherit parent `forms`. `plugin_form.factory` threw:
   `The "stripe_connect" plugin did not specify a "offsite-payment" form class`.
2. **`mel_event_checkout` had no `review` step.** Commerce Stripe 2.2.1
   attaches Payment Element only from `stripe_review`, whose
   `default_step = "review"`. Without that step ID, the pane defaults to
   `_disabled`. Return URLs also hardcode `step => 'review'`.

## Evaluation: dedicated Review step vs single-page PE

| Option | Feasible? | Why |
|---|---|---|
| Put `stripe_review` only on `checkout` | **No (unsafe)** | Return URL + `validateStepId()` require order `checkout_step` to equal the hardcoded `review` step. PE also expects billing/gateway already saved from a prior step. |
| Alter `returnUrl` via `hook_js_settings_alter` only | **Incomplete** | `PaymentOffsiteForm` and Express Checkout still hardcode `review`. |
| Add machine step `review`, customer label “Payment” | **Yes (chosen)** | Matches Commerce Stripe contract with minimum UX change. |

## Checkout flow changes

### 1. `MelEventCheckoutFlow::getSteps()` — add `review`

**Why required:** Commerce Stripe hardcodes `step => 'review'` in:

- `StripeReview` Payment Element `returnUrl`
- `PaymentOffsiteForm` failure redirect
- Express Checkout confirm path

Customer-facing label is **Payment** (not “Review”) to preserve MEL wording.
Progress UI remains off (`display_checkout_progress: false`).

### 2. Enable `stripe_review` on step `review`

**Why required:** Sole attach point for `commerce_stripe/payment_element` and
`drupalSettings.commerceStripePaymentElement` in Commerce Stripe 2.2.1.

### 3. Keep `payment_information` on `checkout`

**Why:** Collects gateway + billing before Payment Element loads (stock
Commerce Stripe order of operations). Preserves the existing details page.

### 4. Keep `payment_process` on hidden `payment` step

**Why:** Offsite completion path still required by Commerce; form class now
present on `stripe_connect`.

### 5. `StripeConnect` annotation — add `offsite-payment`

**Why required:** Maps to installed
`Drupal\commerce_stripe\PluginForm\OffsiteRedirect\PaymentOffsiteForm`.
No behaviour change to Connect destination charges.

## Resulting customer path

1. **Checkout** — buyer/attendee details, legal consent, billing/gateway
2. **Payment** (`review` machine ID) — Stripe Payment Element card fields
3. **payment** (hidden) — Commerce offsite processing
4. **complete** — confirmation

Free/zero-balance orders skip Payment Element (`stripe_review::isVisible()`),
so the review step stays invisible and checkout can advance past it.

## Deploy notes

- Import config or run
  `myeventlane_checkout_flow_post_update_enable_stripe_review`
- `drush cr` after deploy (plugin definition + checkout flow cache)
