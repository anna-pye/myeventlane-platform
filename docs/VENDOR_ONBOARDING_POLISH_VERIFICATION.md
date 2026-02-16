# Vendor Onboarding Polish — Implementation Verification

**Date:** 2025-02-15

---

## 1) Files Added

| Path | Purpose |
|------|---------|
| `web/themes/custom/myeventlane_vendor_theme/templates/components/onboarding-progress.html.twig` | Progress indicator component (STAGE_ORDER, Stripe + Terms locked) |
| `web/themes/custom/myeventlane_vendor_theme/templates/vendor-onboard-step.html.twig` | Theme override for onboarding steps (uses progress component) |
| `web/modules/custom/myeventlane_vendor/templates/create-event-gateway-complete-setup.html.twig` | "Complete your organiser setup" page |
| `web/modules/custom/myeventlane_vendor/templates/vendor-onboard-complete-celebration.html.twig` | Celebration page at /vendor/onboard/complete |

---

## 2) Files Modified

| Path | Changes |
|------|---------|
| `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` | Preprocess for vendor_onboard_step, form__vendor_onboard_profile_form; `_myeventlane_vendor_theme_build_onboarding_stages()` helper |
| `web/themes/custom/myeventlane_vendor_theme/templates/form/form--vendor-onboard-profile-form.html.twig` | Replaced progress div with onboarding-progress include |
| `web/modules/custom/myeventlane_vendor/src/Controller/CreateEventGatewayController.php` | RequestStack; gateway returns render array when incomplete (no ?auto=1); `buildCompleteSetupPage()` |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorOnboardCompleteController.php` | RequestStack; celebration page when no ?auto=1 |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.module` | Theme hooks: vendor_onboard_complete_celebration, create_event_gateway_complete_setup |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` | show_onboarding_badge, next_onboarding_route in page vars |
| `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` | Setup incomplete badge; empty state CTA → create_event_gateway |
| `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` | Theme variables: show_onboarding_badge, next_onboarding_route |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard.scss` | .mel-dashboard__setup-badge styles |
| `web/modules/custom/myeventlane_vendor/css/onboarding.css` | .mel-onboarding-progress component styles |

---

## 3) Full Contents of Key Files

### onboarding-progress.html.twig
See: `web/themes/custom/myeventlane_vendor_theme/templates/components/onboarding-progress.html.twig`

### CreateEventGatewayController.php (modified parts)
- Added RequestStack dependency
- gateway() return type: `RedirectResponse|array`
- Incomplete path: if ?auto=1 → redirect to next route; else → buildCompleteSetupPage()
- New private method: buildCompleteSetupPage()

### VendorOnboardCompleteController.php (modified parts)
- Added RequestStack dependency
- complete() return type: `RedirectResponse|array`
- After completion logic: if ?auto=1 → redirect to wizard; else → render vendor_onboard_complete_celebration

### LegalGatekeeper
- No new method added. Existing `hasVendorAcceptedTerms()` is used (non-assertive check).

---

## 4) Confirmation Checklist

- [x] **No Stripe logic altered** — assertStripeConnected, StripeService, VendorConsoleBaseController unchanged
- [x] **No Legal enforcement altered** — LegalGatekeeper::assertVendorTermsAccepted() unchanged; only uses hasVendorAcceptedTerms() for UI
- [x] **STAGE_ORDER unchanged** — OnboardingStateInterface::STAGE_ORDER not modified
- [x] **No stage duplication** — Single source: OnboardingManager::getNextActionForAuthenticatedVendor, getNextVendorOnboardRouteForAuthenticated

---

## 5) Test Checklist (Manual)

### A) New vendor (no Stripe)
1. Log in as vendor-intent user (no Stripe connected).
2. Visit `/create-event`.
3. **Expect:** "Complete your organiser setup" page (not redirect).
4. Click "Continue setup" → lands on next onboarding step.
5. Visit any onboarding step (e.g. profile, stripe) → progress shows steps after "Payments" as locked.

### B) Stripe connected, terms not accepted
1. Vendor with Stripe connected but terms not accepted.
2. Visit onboarding step after listen (e.g. first-event).
3. **Expect:** Progress locks steps after listen (terms lock).
4. Visit `/create-event` → LegalGatekeeper blocks and redirects to terms (unchanged enforcement).

### C) Completed vendor
1. Vendor with onboarding complete and terms accepted.
2. Visit `/create-event`.
3. **Expect:** Proceeds to wizard.create as before.
4. Visit `/vendor/dashboard`.
5. **Expect:** No "Setup incomplete" badge.

### D) Zero events vendor
1. Vendor with zero events (show_welcome = true).
2. Visit `/vendor/dashboard`.
3. **Expect:** Welcome banner with "Create Event" button.
4. Click → goes to `/create-event` (gateway).

### E) Branding route
1. Visit `/vendor/onboard/branding`.
2. **Expect:** Progress shows "Payments" (listen) as current stage.

### F) Completion celebration
1. Complete onboarding to boost step, click "Continue to dashboard introduction".
2. **Expect:** Celebration page "You're ready to publish" with "Create your first event" CTA.
3. Visit same URL with `?auto=1` → redirects to wizard.

---

## 6) Commands

```bash
drush cr
```

---

*Vendor onboarding now provides Stripe + Terms locked progress visibility, explanatory gateway UX, celebration completion page, and dashboard polish without modifying enforcement logic.*
