# MyEventLane Customer + Vendor Onboarding — Source of Truth Audit

**Audit date:** 2025-02-12  
**Scope:** Drupal 11, Customer and Vendor onboarding flows  
**Method:** Code/config audit only — no implementation changes

---

## 1) Executive Summary

### What's working
- **Customer RSVP:** Anonymous RSVP allowed via `/event/{event}/rsvp` → unified `/event/{node}/book`; writes to `rsvp_submission` entity; confirmation email via `myeventlane_rsvp.mailer`.
- **Customer ticket purchase:** Unified booking at `myeventlane_commerce.event_book`; checkout supports anonymous via `mel_buyer_details` pane and `guest_new_account: true`; order receipt emails via `myeventlane_messaging` OrderPlacedSubscriber.
- **Customer My Account:** Custom dashboard at `/my-account`, `/my-settings`, `/my-past-events`; UserProfileRedirectSubscriber sends `/user/{uid}` to `/my-account`.
- **Vendor onboarding:** Step-by-step flow (account → profile → stripe → branding → first event → boost → complete); CreateEventGateway enforces onboarding before event wizard.
- **Vendor entity:** 1:1 with user; created by OnboardingManager.ensureVendorExists() during onboarding completion; Stripe account ID on `commerce_store` (field_stripe_account_id, field_stripe_connected).
- **Stripe Connect:** Enforced before creating events; EventWizardCreateController.assertStripeConnected() blocks wizard entry.
- **Event wizard:** Steps basics → when_where → tickets → details → review → publish; TicketTypeManager creates products/variations; Stripe required before any event creation.
- **Registration:** myeventlane_auth provides account type choice (customer/organiser); vendor intent redirects to onboard.profile when `?vendor=1`.
- **Checkout legal consent:** LegalConsentPane records terms checkbox on order (field_legal_consent_given).

### What's missing
- **Cookie consent:** No cookie consent module or conditional script loading found.
- **Vendor legal acceptance:** No vendor-specific terms acceptance stored on vendor entity or user.
- **Header "Dashboard" for customers:** Header links all logged-in users to `/dashboard`, which redirects to vendor dashboard; customer users get 403 (VendorConsoleAccess).
- **Explicit "Register" CTA:** Main header only shows "Login"; Sign up link only on login page.
- **Post-registration redirect:** Standard user module; vendor intent (`?vendor=1`) redirects to onboard.profile via form alter.

### What's risky
- **Customer/vendor dashboard confusion:** `/dashboard` always goes to vendor console; customers without `access vendor console` receive 403.
- **Gin Login:** In composer but no theme hook override evidence for its templates; custom user--login/user--register used.
- **\Drupal:: calls in RsvpPublicForm.php:** Lines 194–195 use `\Drupal::config()` and `\Drupal::hasService()` — violates DI rule.

---

## 2) Customer Onboarding Audit

### 2.1 Entry points

| Location | Evidence | Conditions | Links/Routes |
|----------|----------|------------|--------------|
| Event page CTA (RSVP/Buy Ticket) | `web/themes/custom/myeventlane_theme/templates/event/event-card.html.twig` L30 | `ticket_type == 'rsvp'` → "RSVP", else "Get tickets" | Links to event canonical or booking |
| Header Login | `web/themes/custom/myeventlane_theme/templates/region--header.html.twig` L37, L71 | `logged_in` → Dashboard; else Login | `/user/login` |
| Header Create Event | `region--header.html.twig` L37–43 | Always shown | `/create-event` |
| My Account | `web/themes/custom/myeventlane_theme/templates/user/user--edit.html.twig` L16 | User edit page | Title "My Account" |
| Book page | `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php` | Renders RSVP or paid form based on EventCtaResolver | `myeventlane_commerce.event_book` `/event/{node}/book` |
| Radix header | `web/themes/custom/myeventlane_radix/templates/includes/header.html.twig` L44, L70 | Same pattern | `/user/login` |

**Create account prompts:** Only on login form (`user--login.html.twig`, `form--user-login-form.html.twig`) via "Sign up" link to `user.register`.

### 2.2 Registration + login

| Component | Evidence |
|----------|----------|
| gin_login | In composer (`composer.json` L42: `drupal/gin_login: 2.1.x-dev`). No custom override of gin templates found. |
| Custom templates | `myeventlane_theme/templates/user/user--login.html.twig`, `user--register.html.twig`; `form/form--user-login-form.html.twig` (both themes) |
| Custom controller routes | None for login/register — uses core `user.login`, `user.register` |
| Theme hook suggestions | Standard `user--login`, `user--register` |

**Routes:** `user.login` (`/user/login`), `user.register` (`/user/register`).

### 2.3 Guest vs required account rules

**RSVP:**
- **Anonymous allowed:** YES. Route `myeventlane_rsvp.public_rsvp_form` requires `_permission: 'access content'`; anonymous has this by default.
- **Evidence:** `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml` L21–22 — no `_user_is_logged_in`.
- **Storage:** `rsvp_submission` entity, `user_id` = 0 for anonymous (`RsvpPublicForm.php` L435).
- **Unified flow:** `/event/{event}/rsvp` 301 redirects to `myeventlane_commerce.event_book` (`RsvpRedirectController.php` L26).

**Commerce checkout:**
- **Guest checkout:** Effectively YES. `contact_information` pane disabled; `mel_buyer_details` collects email/name for anonymous.
- **Evidence:** `commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml` — `guest_new_account: true`, `guest_order_assign: false`; `BuyerDetailsPane.php` L134 handles `$customer->isAnonymous()`.
- **Config:** `web/modules/custom/myeventlane_checkout_flow/config/install/commerce_checkout.commerce_checkout_flow.mel_event_checkout.yml`.
- **No explicit `allow_guest_checkout`** in mel flow; Commerce default flow has `allow_guest_checkout: false` in contact_information; mel uses custom pane.

### 2.4 Post-registration redirect and first-run experience

| Action | Redirect | Evidence |
|--------|----------|----------|
| Registration (customer) | Drupal default (typically `/user/{uid}`) | No custom subscriber |
| Registration (vendor intent) | `myeventlane_vendor.onboard.profile` | `myeventlane_vendor.module` L237–243 `_myeventlane_vendor_redirect_to_onboard` when `?vendor=1` |
| Login | Core/destination query | VendorDomainSubscriber may redirect vendor domain requests |
| Own profile visit | `/my-account` | `UserProfileRedirectSubscriber.php` L67–68 |
| RSVP complete | `myeventlane_rsvp.thankyou` | `RsvpPublicForm.php` L561–565 |
| RSVP with donation | `commerce_checkout.form` | `RsvpPublicForm.php` L450–452 |
| Ticket purchase complete | Commerce checkout completion (order summary) | OrderCompletedSubscriber creates attendee records; no custom redirect found |

### 2.5 Emails and confirmations

| Type | Module/Service | Template | Trigger |
|------|----------------|----------|---------|
| RSVP confirmation | `myeventlane_rsvp.mailer` | `mel-rsvp-confirmation-email` (`myeventlane_rsvp.theme` L34) | RsvpPublicForm submit (L538–541) |
| Order receipt | `myeventlane_messaging.manager` | `order_receipt` template | OrderPlacedSubscriber on `commerce_order.place.post_transition` |
| Account creation | Drupal user module | Core | Standard user registration |
| Ticket purchase | OrderPlacedSubscriber | `order_receipt` | `myeventlane_messaging/src/EventSubscriber/OrderPlacedSubscriber.php` L367 |

**Mail key:** `myeventlane_rsvp_mail()` in `myeventlane_rsvp.module` L17.

### 2.6 Cookie consent / privacy

- **Cookie consent module:** NONE found. No contrib module for cookie consent in composer or config.
- **Conditional analytics/marketing:** No evidence of consent-gated script loading.
- **Privacy link:** Footer has `/privacy` (`myeventlane_radix/templates/includes/footer.html.twig` L31).

---

## 3) Vendor Onboarding Audit

### 3.1 Vendor creation

| Item | Evidence |
|------|----------|
| Entity type | `myeventlane_vendor` (`web/modules/custom/myeventlane_vendor/src/Entity/Vendor.php`) |
| Base table | `myeventlane_vendor` |
| Bundles | Single bundle `myeventlane_vendor` |
| Creation moment | On first `/create-event` access: CreateEventGatewayController → OnboardingManager.ensureVendorExists() when onboarding complete; Vendor created at profile step submit |
| 1:1 enforcement | `Vendor.php` L89–114: preSave() throws if duplicate uid |
| Admin creation | `entity.myeventlane_vendor.add_form` at `/admin/structure/myeventlane/vendor/add` |

### 3.2 Vendor dashboard

| Route | Path | Controller | Access |
|-------|------|------------|--------|
| Dashboard | `/vendor/dashboard` | VendorDashboardController | VendorConsoleAccess |
| Events list | `/vendor/events` | VendorEventsController | VendorConsoleAccess |
| Event overview | `/vendor/events/{event}/overview` | VendorEventOverviewController | VendorConsoleAccess |
| Zero events | Dashboard template shows empty states | `myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` |
| Draft vs published | Event table and filters in dashboard | VendorEventsController, DashboardEventLoader |

**Route:** `myeventlane_vendor.console.dashboard`; access: `_custom_access: '\Drupal\myeventlane_vendor\Access\VendorConsoleAccess::access'`.

### 3.3 Stripe Connect onboarding enforcement

| Aspect | Evidence |
|--------|----------|
| Account ID storage | `commerce_store` fields: `field_stripe_account_id`, `field_stripe_connected`, `field_stripe_charges_enabled`, `field_stripe_payouts_enabled` |
| Vendor entity | Also has `field_stripe_account_id` (StripeConnectController L187–188) |
| Onboarding status check | `VendorConsoleBaseController::assertStripeConnected()` L216–251; called by EventWizardCreateController.createDraft() L60 |
| Paid event publishing block | Event creation (including draft) blocked without Stripe; publish form itself does not re-check |
| UI indicators | VendorOnboardStripeController, stripe-panel component, VendorProfileSettingsForm L703–778 |
| Connect route | `myeventlane_vendor.stripe_connect` `/vendor/stripe/connect` |

### 3.4 First event wizard

| Step | Route | Form/Controller | Creates |
|------|-------|-----------------|---------|
| Create | `myeventlane_event.wizard.create` | EventWizardCreateController.createDraft() | Draft event node |
| Basics | `myeventlane_event.wizard.basics` | EventWizardBasicsForm | — |
| When/Where | `myeventlane_event.wizard.when_where` | EventWizardWhenWhereForm | — |
| Tickets | `myeventlane_event.wizard.tickets` | EventWizardTicketsForm | Products/variations via TicketTypeManager |
| Details | `myeventlane_event.wizard.details` | EventWizardDetailsForm | — |
| Review | `myeventlane_event.wizard.review` | EventWizardReviewForm | — |
| Publish | `myeventlane_event.wizard.publish` | EventWizardPublishForm | Sets status=1 |

**Image saving:** Event design/form fields handle images; EventDesignForm L142 redirects to standard edit for image fields.

**RSVP vs Paid:** EventWizardTicketsForm uses TicketTypeManager; ticket type selection in form display `wizard_step_4`.

**Auto-creation:** rsvp_submission nodes not created by wizard; products/variations created when ticket types configured.

### 3.5 Vendor legal acceptance

**NOT IMPLEMENTED.** No `field_terms_accepted`, `field_legal_consent`, or vendor-specific terms field on `myeventlane_vendor` or user. Checkout LegalConsentPane is for buyer order consent only.

---

## 4) Gaps and Risks (Ranked)

### High
1. **Customer Dashboard 403:** Logged-in customers clicking "Dashboard" go to `/dashboard` → `/vendor/dashboard` → 403 (no `access vendor console`). Need routing based on role (customer → `/my-account`, vendor → `/vendor/dashboard`).
2. **No cookie consent:** Analytics/marketing scripts may run without consent; GDPR risk.
3. **\Drupal:: in RsvpPublicForm:** Violates project rule; use DI.

### Medium
4. **No vendor terms acceptance:** Vendors do not explicitly accept platform/organiser terms.
5. **Register CTA discoverability:** "Sign up" only on login page; no prominent header CTA.
6. **Post-purchase redirect:** No explicit post-checkout redirect to My Account or tickets page documented; relies on Commerce default.

### Low
7. **Gin Login usage unclear:** Module present; actual template precedence vs custom overrides needs verification.
8. **Two dashboard concepts:** `/my-account` (customer) vs `/vendor/dashboard` (vendor); `/dashboard` conflates them.

---

## 5) Next Audit Steps (UNKNOWN Items)

| UNKNOWN | To resolve |
|---------|------------|
| Exact post-checkout redirect for ticket purchase | Inspect Commerce checkout completion page/redirect; `commerce_checkout` plugin or completion handler |
| Whether `allow_guest_checkout` applies to mel flow | Run `drush config:get commerce_checkout.commerce_checkout_flow.mel_event_checkout` and inspect Commerce Checkout flow plugin source for guest logic |
| User registration email verification | Inspect `user.settings` and `user.mail` config; run `drush config:get user.settings` |
| Gin Login template override chain | Inspect `web/themes/contrib/gin_login` templates and theme inheritance |
| Customer vs vendor dashboard routing intent | Confirm product requirement: should `/dashboard` branch by role? |

---

## 6) Concrete Inventory

### 6.1 Routes (Customer and Vendor)

#### Customer
| Route | Path | Requirements |
|-------|------|--------------|
| myeventlane_account.dashboard | /my-account | _user_is_logged_in |
| myeventlane_account.settings | /my-settings | _user_is_logged_in |
| myeventlane_account.past_events | /my-past-events | _user_is_logged_in |
| myeventlane_account.event_review | /event/{node}/review | _user_is_logged_in, EventReviewRouteAccessCheck |
| myeventlane_commerce.event_book | /event/{node}/book | _permission: access content |
| myeventlane_rsvp.public_rsvp_form | /event/{event}/rsvp | _permission: access content |
| myeventlane_rsvp.thankyou | /event/{event}/rsvp/thank-you | _permission: access content |
| myeventlane_rsvp.form | /event/{node}/rsvp/form | _permission: access content |
| myeventlane_rsvp.user_list | /user/{user}/rsvps | _permission: access content, _user_is_logged_in |
| myeventlane_dashboard.legacy_redirect | /dashboard | _permission: access content, _user_is_logged_in |
| myeventlane_dashboard.customer | /my-events | _permission: access content |
| user.login | /user/login | — |
| user.register | /user/register | — |

#### Vendor
| Route | Path | Requirements |
|-------|------|--------------|
| myeventlane_vendor.create_event_gateway | /create-event | _access: TRUE |
| myeventlane_vendor.onboard | /vendor/onboard | _permission: access content |
| myeventlane_vendor.onboard.account | /vendor/onboard/account | _permission: access content |
| myeventlane_vendor.onboard.profile | /vendor/onboard/profile | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.onboard.stripe | /vendor/onboard/stripe | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.onboard.branding | /vendor/onboard/branding | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.onboard.first_event | /vendor/onboard/first-event | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.onboard.boost | /vendor/onboard/boost | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.onboard.complete | /vendor/onboard/complete | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.console.dashboard | /vendor/dashboard | VendorConsoleAccess |
| myeventlane_vendor.stripe_connect | /vendor/stripe/connect | _permission: access content, _user_is_logged_in |
| myeventlane_vendor.stripe_callback | /stripe/connect/callback | _permission: access content, _user_is_logged_in |
| myeventlane_event.wizard.create | /vendor/events/create | VendorConsoleAccess |
| myeventlane_event.wizard.basics | /vendor/events/{event}/build/basics | VendorConsoleAccess |
| myeventlane_event.wizard.tickets | /vendor/events/{event}/build/tickets | VendorConsoleAccess |
| myeventlane_event.wizard.publish | /vendor/events/{event}/build/publish | VendorConsoleAccess |

### 6.2 Entity types and bundles

| Entity | Bundle(s) | Storage |
|--------|-----------|---------|
| node | event | SQL |
| commerce_product | (event-linked) | SQL |
| commerce_product_variation | (ticket types) | SQL |
| commerce_order | default | SQL |
| commerce_store | online (Stripe fields) | SQL |
| myeventlane_vendor | myeventlane_vendor | SQL |
| rsvp_submission | rsvp_submission | SQL |
| event_attendee | — | SQL (myeventlane_event_attendees) |
| paragraph | ticket_holder, etc. | SQL |

### 6.3 Permissions used in onboarding

| Permission | Usage |
|------------|-------|
| access content | RSVP, book, onboarding routes, dashboard |
| access vendor console | Vendor dashboard, event wizard, Stripe manage |
| access checkout | Attendee info edit |
| administer myeventlane vendor | Admin vendor CRUD |
| administer myeventlane rsvp | RSVP admin settings |
| manage own event rsvps | Vendor RSVP views |

---

*End of audit report.*
