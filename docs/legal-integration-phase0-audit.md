# Phase 0 — Legal Integration Mode Audit (Confirmed)

**Date:** 2025-02-12  
**Scope:** Confirm current state of all legal-related code paths before implementing C — Legal Integration Mode.

---

## 1) RSVP

| Item | Path | Evidence |
|------|------|----------|
| Module | `web/modules/custom/myeventlane_rsvp/` | Present |
| Routing | `myeventlane_rsvp.routing.yml` | `myeventlane_rsvp.form` → `/event/{node}/rsvp/form`; `myeventlane_rsvp.public_rsvp_form` → `/event/{event}/rsvp` |
| Redirect | RsvpRedirectController L21 | `/event/{event}/rsvp` 301 → `myeventlane_commerce.event_book` |
| Public form | `RsvpPublicForm.php` | Creates `rsvp_submission` entity; supports anonymous (user_id=0) |
| Commerce RSVP | `RsvpBookingForm.php` (myeventlane_commerce) | Used when event has $0 product; adds to cart, checkout |
| Storage | `RsvpSubmission` entity (ContentEntityBase) | `base_table = "rsvp_submission"`; base fields: attendee_name, name, email, phone, guests, status, event_id, user_id, donation, checked_in, checked_in_at |
| Mailer | `myeventlane_rsvp.mailer` | RsvpMailer::sendConfirmation() |
| Legal fields | **NONE** | No field_customer_terms_*, field_privacy_*, field_marketing_opt_in on rsvp_submission |

---

## 2) Booking

| Item | Path | Evidence |
|------|------|----------|
| Controller | `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php` | Renders RSVP or paid form via EventCtaResolver |
| CTA resolver | `EventCtaResolver::getCtaType()` | Returns CTA_RSVP or CTA_PAID |
| RSVP form choice | BookController L165–192 | `buildRsvpOnlyForm()`: RsvpBookingForm (when $0 product) else RsvpPublicForm |
| Route | `myeventlane_commerce.event_book` | `/event/{node}/book` |

---

## 3) Checkout Flow

| Item | Path | Evidence |
|------|------|----------|
| Config | `web/modules/custom/myeventlane_checkout_flow/config/install/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml` | `mel_buyer_details` (weight 0), `mel_legal_consent` (weight 3), `guest_new_account: true` |
| mel_buyer_details | `BuyerDetailsPane.php` | Collects email, first_name, last_name, mobile; supports anonymous via `$customer->isAnonymous()` |
| LegalConsentPane | `LegalConsentPane.php` L22–117 | Single checkbox; stores `field_legal_consent_given`, `field_legal_consent_timestamp`; fallback to `order->setData()` if fields absent |
| Order fields | **Uncertain** | No config YAML for `field_legal_consent_*` in repo; LegalConsentPane uses `hasField()` check + fallback |

---

## 4) LegalConsentPane (Excerpt)

```php
// L40–44: reads if exist
if ($order->hasField('field_legal_consent_given') && !$order->get('field_legal_consent_given')->isEmpty()) {
  $consent_given = (bool) $order->get('field_legal_consent_given')->value;
}
if ($order->hasField('field_legal_consent_timestamp') && !$order->get('field_legal_consent_timestamp')->isEmpty()) {
  $consent_timestamp = (int) $order->get('field_legal_consent_timestamp')->value;
}
// L99–111: stores or falls back to order data
```

---

## 5) Vendor Onboarding

| Item | Path | Evidence |
|------|------|----------|
| Gateway | `CreateEventGatewayController.php` | Redirects based on OnboardingManager state |
| Onboarding stages | `OnboardingStateInterface::STAGE_ORDER` | probe, present, listen, ask, invite, complete |
| Completion check | CreateEventGatewayController L96 | `$state->getStage() === 'complete' && $state->isCompleted()` |
| Vendor terms | **NONE** | No step, no field, no enforcement |
| Routes | `myeventlane_vendor.routing.yml` | onboard.profile, onboard.stripe, onboard.branding, onboard.first_event, onboard.boost, onboard.complete |

---

## 6) Stripe Assertion

| Item | Path | Evidence |
|------|------|----------|
| Method | `VendorConsoleBaseController::assertStripeConnected()` L216–251 | Redirects to `/vendor/onboard/stripe` if vendor has no field_vendor_store or Stripe not connected |
| Call site | `EventWizardCreateController::createDraft()` L60 | `$this->assertStripeConnected();` before getOrCreateDraftEvent() |

---

## 7) Admin Config Pattern

| Module | Path | Example |
|--------|------|---------|
| myeventlane_rsvp | `/admin/config/myeventlane/rsvp` | RsvpSettingsForm |
| myeventlane_core | `/admin/config/myeventlane`, `/admin/config/myeventlane/general` | ConfigEntityListBuilder |
| myeventlane_donations | `/admin/config/myeventlane/donations` | DonationsSettingsForm |

---

## 8) myeventlane_auth (Registration)

| Item | Evidence |
|------|----------|
| Form alter | `myeventlane_auth.module` L17–27 | Alters `user_register_form` |
| Account type | L97–108 | Radios: "I'm attending events" / "I'm running events" |
| Legal checkboxes | **NONE** | No terms, privacy, or marketing opt-in on registration |

---

## 9) Analytics Scripts

| Search | Result |
|--------|--------|
| GA, gtag, Hotjar, Matomo | **Not found** |
| Google Fonts | `myeventlane_theme.theme` L334 — fonts.googleapis.com (font loading) |
| Stripe JS | `stripe-fallback.js` — js.stripe.com (payments) |
| myeventlane_analytics | Internal platform analytics (PopularEventsService, TrendingCategoriesService) — **not third-party tracking** |

**Conclusion:** No analytics/marketing scripts detected. Cookie consent framework still required for future gating.

---

## 10) STOP if Unknown — Resolved

1. **Admin-created users:** Implemented: skip required checkboxes when current user has `administer users` permission (form alter checks this).
2. **IP/User-Agent on vendor terms:** Implemented: optional storage with config `store_vendor_ip_ua` (default FALSE).
3. **commerce_order field_legal_consent_*:** Implemented: fields created in myeventlane_legal update hook; LegalConsentPane stores versioned data.
