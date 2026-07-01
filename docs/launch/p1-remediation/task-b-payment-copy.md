# TASK B — Payment-State-Aware Checkout Completion

**Status:** Implemented. **Commerce behaviour unchanged** — presentation now responds to
Commerce payment truth (`$order->isPaid()`).

## Verified finding (source: customer-verification)

Commerce payment + fulfilment behaviour is correct (tickets gated on `OrderEvents::ORDER_PAID`).
But the checkout completion hero always read **"Booking confirmed"** regardless of payment state:
the presenter `MelCustomerContinuityPresenter::buildCheckoutCompletionPresentation()` received no
payment-state argument. Real pending-payment completed orders (#550, #551 via the Manual gateway)
showed confirmed-booking language while payment was still pending.

## Minimum architectural change

Payment state is threaded from the (already order-aware) theme preprocess into the presentation
layer; no payment logic is duplicated — the presenter simply receives one boolean.

| File | Change |
| --- | --- |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` (~line 1276) | Pass `(bool) $order->isPaid()` as the final arg to `buildCheckoutCompletionPresentation()`. |
| `web/modules/custom/myeventlane_surface/src/MelCustomerContinuityPresenter.php` | `buildCheckoutCompletionPresentation()` gains `bool $is_paid = TRUE`; `resolveCheckoutCompletionHero()` gains `bool $is_paid = TRUE` and selects the paid vs pending hero on the ticket path. |
| `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` | Paid lead updated to spec copy; new `customerCheckoutPendingPaymentHero()`. |

Default `TRUE` keeps the single existing caller and any future callers back-compatible.

## Required behaviour (implemented)

| State | Heading | Supporting copy |
| --- | --- | --- |
| **Paid** (`isPaid() === true`) | Booking confirmed | Confirmation and tickets have been sent. |
| **Pending payment** (`isPaid() === false`, tickets) | Order received | We’re waiting for payment confirmation. Your booking will be confirmed once payment has been received. |
| **Free RSVP / zero-total** | Booking confirmed | (unchanged) — zero-total orders report `isPaid() === true`, so they keep existing behaviour. |
| **Failed payment** | — | See note below. |

**Failed payment note:** In Commerce's checkout flow a *failed* payment does not advance the order
to the `complete` step (the customer stays on the payment step with an error), so the completion
template is not rendered for failed payments. The not-paid (`pending`) branch is therefore the
safe catch-all: any non-paid order that *does* reach completion gets the honest "Order received /
awaiting payment" copy and **never** successful-booking language — satisfying the requirement
without inventing fragile failure-state detection. (No invented business rule; documented per the
task's "STOP / document" clause.)

## Accessibility

Only the hero *strings* changed; the template structure is untouched, so existing a11y is
preserved:
- Single `<h1 id="mel-confirmation-hero-title">` — heading hierarchy intact.
- `<header role="region" aria-labelledby="mel-confirmation-hero-title">` — region label tracks the
  new heading automatically.
- Focus order unchanged. Screen-reader wording: "Order received" / "Booking confirmed" are read
  as the page's H1 — clear, calm, unambiguous.

## Style Guide

Copy is short, clear, calm, trustworthy, and never claims success before payment — aligned to MEL
voice and `docs/brand/copy-guidelines.md`. Apostrophe glyph matches existing MEL strings (`’`).

## Risk assessment

- **No fulfilment change.** Tickets remain controlled by Commerce (`OrderEvents::ORDER_PAID`).
- No Stripe, payment-entity, or order-state change. Presentation-only.
- Donation-only and generic (no-ticket) hero paths are unchanged.

## Validation performed

| Check | Result |
| --- | --- |
| Runtime presenter on real orders | #552 (paid) → "Booking confirmed / Confirmation and tickets have been sent."; #551 & #550 (pending) → "Order received / We’re waiting for payment confirmation…"; free/default → "Booking confirmed" ✓ |
| `composer validate` | valid |
| `drush config:status` | no drift |
| `drush cr` | rebuilt clean |
| Unit `MelReadinessHelperCustomerTest` | 3 tests, 22 assertions — OK |

## Before / after behaviour

- **Before:** Completion hero always "Booking confirmed", even for pending-payment orders.
- **After:** "Booking confirmed" only when `$order->isPaid()`; pending orders get "Order received".
  Free/zero-total orders unchanged.
