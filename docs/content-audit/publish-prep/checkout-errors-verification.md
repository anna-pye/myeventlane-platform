# Checkout errors — product verification

**Date:** 2026-05-22  
**Draft:** `help-article-drafts/checkout-errors.md`

## Evidence found

| Item | Evidence |
|------|----------|
| Checkout surface | Commerce checkout `MelEventCheckoutFlow`; Stripe Payment Element via `stripe_connect` gateway |
| User-facing validation | `MelReadinessHelper::customerCheckoutErrorSummaryLine()` → “Please complete the required details to continue.” |
| Payment trust copy | “You will not be charged until you confirm your booking.” |
| Recovery route | `/my-tickets` for signed-in users; Help Centre / support for help |
| Contextual help | `checkout_booking` contextual card; support panel on checkout (`myeventlane_help_centre_preprocess_commerce_checkout_form`) |
| Stripe failures | Handled via Commerce Stripe / PaymentIntent (no custom public “card declined” string found in MEL checkout_flow module) |

**Code paths:**

- `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php` — checkout hero/error summary
- `web/modules/custom/myeventlane_checkout_flow/` — checkout panes, grouped summary
- `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/PaymentGateway/StripeConnect.php`
- `web/modules/custom/myeventlane_help_centre/` — checkout help links (not changed in this task)

## User-facing behaviour (safe to describe)

- Checkout can stop if required fields are missing (generic summary message).
- Card or bank declines are typically shown via the payment step (Stripe/Commerce messaging — **exact text not catalogued** in this pass).
- Users should not assume failure means charged — check confirmation email before paying again.
- Recovery: retry checkout, try another card, check My tickets, contact support.

## Unsupported or unverified claims

| Claim | Verdict |
|-------|---------|
| Specific error codes or Stripe decline messages | **Do not list** without staging capture |
| Automatic retry by MEL | Not confirmed — do not promise |
| Refund on failed payment | **Do not claim** — bank holds vary |

## Proposed safe article wording (summary)

Keep draft structure; remove any implication of specific Stripe error strings. Use:

- “If checkout stops or your bank declines the payment…”
- “Read the message on the payment step”
- “Check email and My tickets before paying again”

## Publish readiness

**Ready for editorial update** (as new article) with light edits.

**Reason:** Article is intentionally generic and matches how checkout presents errors today. No false product promises. Recommend **staging spot-check** of one declined test card to optionally add one example message — not required for first publish.

**Next step:** Create new `help_article` node (no duplicate found) with `field_audience: public`, `field_help_status: approved` or `published` after editorial sign-off.
