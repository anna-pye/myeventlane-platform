# MyEventLane — Customer Journey Acceptance Audit

**Audit type:** Acceptance audit (read-only). No code, config, or content was modified.
**Date:** 2026-06-26
**Branch reviewed:** `fix/branding-hero-upload-ux`
**Reviewers (roles assumed):** Lead Product Owner · Senior UX Architect · Drupal 11 Architect · Commerce 3 Architect · Accessibility Specialist · Launch QA Lead

---

## 1. Method & evidence basis

This audit was performed against the repository exactly as it exists. Every finding below is
anchored to a route, controller, form, template, or config file that was inspected. Where a
journey step could not be confirmed from repository evidence, it is explicitly marked:

> **Repository evidence not found.**

### What this audit can and cannot assert

| Can assert from repo evidence | Cannot assert from repo evidence (needs live run) |
| --- | --- |
| Route exists, path, controller, access requirement | Rendered pixel layout / visual regressions |
| Presence of CTAs, empty states, error handling in templates/controllers | Real Lighthouse / Core Web Vitals performance |
| Accessibility primitives in markup (skip links, `aria-*`, `alt`, roles) | Screen-reader behaviour end-to-end |
| Access/ownership checks in routing + access services | Live payment, refund, payout settlement behaviour |
| Governed copy / empty-state governance | Deliverability of transactional emails |

**Performance, visual-consistency, and mobile scores in this audit are repository-evidence
proxies** (presence of responsive shells, mobile bottom nav, lazy-loading, token system),
**not** measured runtime metrics. Items requiring a live environment are flagged in
`customer-launch-checklist.md` as **VERIFY-LIVE**.

### Scope counted

- Total registered routes in catalogue (`mel-routes.json`): **390**
- Customer/organiser-facing routes (admin/config/translate excluded): **234**
- Theme templates inspected set: **236** Twig files in `web/themes/custom/myeventlane_theme/templates`
- Of theme templates, **124** contain `aria-*` / `role=` / `alt=` primitives; **67** contain explicit empty/no-results handling.

### Authoritative style reference ("MEL Style Guide")

The repository carries a formal design system, treated here as the canonical style guide:

- `DESIGN_SYSTEM.md` — token source, locked hero contract (`.mel-event-hero--featured-style`), card contract.
- `docs/brand/` — `mel-brand-system-v1.md`, `design-tokens.md`, `copy-guidelines.md`, `event-card-system.md`, `homepage-system.md`, `illustration-guidelines.md`, `guide-character-system.md`.

Alignment is judged against these documents.

---

## 2. Scoring key

Each page is scored out of 10 on seven dimensions: **UX, Trust, Accessibility, Mobile,
Performance, Visual Consistency, Conversion Clarity**. See `customer-scorecard.md` for the
full matrix and the overall launch-readiness score. Priorities used throughout:

- **P0** — Launch blocker (safety, money, access, or journey-breaking).
- **P1** — Should fix before public launch (material friction / trust gap).
- **P2** — Fast-follow after launch.
- **P3** — Polish / backlog.

---

## 3. Visitor journey

### 3.1 Landing / Home
- **Route:** `/home` (`system.site.front`), template `page--front.html.twig`; regions driven by `myeventlane_front` blocks (`HomeHeroBlock`, `PopularEventsBlock`, `TrendingCategoriesBlock`, `TrendingInCategoryBlock`).
- **Purpose:** Discovery entry; merchandised sections (Community spotlight, Happening tonight, Hidden Gems, Discover).
- **Target user:** Anonymous visitor / returning customer.
- **Primary CTA:** Hero search + "Explore events" (`view.upcoming_events.page_events`).
- **Secondary CTA:** Section "See today" / "See all Hidden Gems" links.
- **Empty states:** Sections are visibility-gated (`mel_home_show_*` flags, `HomepageSectionVisibility` service) — sections hide rather than render empty. Reasonable.
- **Error states:** Region-driven; no explicit error surface. Acceptable for a block-composed page.
- **Mobile:** Mobile bottom nav present (`MobileBottomNavigationBuilder`); section shells are container-based. Responsive shell present.
- **Accessibility:** Hero component included; broader `aria` coverage strong across theme. Skip-link present on discovery shell (see 3.2) — **confirm landing page exposes a skip link** (not visible in `page--front.html.twig` block override).
- **Trust signals:** Merchandising (popular/trending) acts as social proof. No explicit trust strip on home.
- **Visual consistency:** Uses `mel-section-shell` + tokens — aligned to `homepage-system.md`.
- **Friction:** Section copy intentionally diverges from canonical browse copy (documented in template header) — acceptable but worth a final editorial pass.
- **Priority:** P1 — confirm landing skip-link + a single trust anchor.

### 3.2 Discovery / Browse
- **Route:** `view.upcoming_events.page_events` and siblings (`page_today`, `page_this_weekend`, `page_popular`, `page_hidden_gems`, `page_free`), shell `includes/mel-discovery-page-shell.html.twig`.
- **Purpose:** Filterable event discovery.
- **Primary CTA:** Event cards → event page.
- **Empty states:** Discovery content delegated to view; theme has 67 templates with explicit empty handling. **VERIFY-LIVE** that the Views "no results" text is MEL-branded for each display.
- **Accessibility:** Skip-link (`#main-content`), `<main>` landmark, visually-hidden section headings ("Discovery results") — strong.
- **Mobile:** Full-bleed hero + contained filters + mobile bottom nav.
- **Visual consistency:** Unified shell across all browse displays — strong alignment.
- **Priority:** P2 — confirm branded empty results per display.

### 3.3 Search
- **Route:** `/search` (`mel_search.view` → `SearchController::build`), autocomplete `/search/autocomplete`.
- **Access:** `_access: 'TRUE'`; routing comments document anonymous-safe Search API indexes, "no order, vendor payout, or account data exposed." Security posture good.
- **Primary CTA:** Result cards; autocomplete suggestions (titles, venues, categories).
- **Empty states:** `item-list--search-results.html.twig` override present. **VERIFY-LIVE** branded zero-results copy.
- **Accessibility/Mobile:** Inherits discovery shell.
- **Priority:** P2.

### 3.4 Calendar
- **Route/Template:** `page--calendar.html.twig` present.
- **Purpose:** Calendar view of events.
- **Status:** Template exists; **the controller/view feeding `/calendar` was not confirmed in this pass** — **VERIFY-LIVE** that the route renders and is linked from primary nav.
- **Priority:** P1 — confirm calendar is reachable and populated, or remove from nav to avoid a dead end.

### 3.5 Categories
- **Route:** `page--events--category.html.twig`; `/my-categories` (`MyCategoriesController`) for authenticated personalisation; `TrendingCategoriesBlock`.
- **Purpose:** Category-scoped discovery.
- **Empty states:** Category browse uses discovery shell.
- **Priority:** P2 — confirm each taxonomy term page has hero/branded empty state.

### 3.6 Event page (highest-value conversion surface)
- **Route:** canonical node view; templates `node--event.html.twig` (338 lines), `page--node--event.html.twig`.
- **Primary CTA:** Booking sidebar card (`mel_booking.cta`) → `/event/{node}/book` (`BookController`).
- **Secondary CTA:** RSVP (`/event/{event}/rsvp`), ICS download (`/event/{node}/ics`), review (`/event/{node}/review`).
- **Trust signals (strong):** availability stamps (`TONIGHT` / `SOLD OUT`), refund-policy block (`field_refund_policy` mapped to human labels incl. "No refunds"), organiser host card, map (`myeventlane_location/event_map`), "Inclusive by design" support helper.
- **Empty/error states:** Cancelled/sold-out states render a "Please check back for updates or contact the organiser." message — graceful.
- **Accessibility:** Hero image carries `alt` (`field_event_image.alt|default(node.label)`); semantic `<section>`/`<h1>`. Good.
- **Mobile:** Sidebar card collapses into stacked layout (token-driven). **VERIFY-LIVE** sticky/clear booking CTA on mobile.
- **Conversion clarity:** Booking access-code gating handled (`#ticket_booking_access`); CTA label/type governed by `mel_booking`. Strong.
- **Priority:** P1 — confirm mobile booking CTA prominence + access-code UX is discoverable, not hidden.

### 3.7 Organiser profile (public)
- **Routes:** `/organisers` and `/vendors` (`VendorPublicController`), templates `page--vendors.html.twig`, `myeventlane-vendor--*--full.html.twig`, `entity--myeventlane-vendor--full.html.twig`.
- **Purpose:** Public organiser directory + profile.
- **Trust signals:** Organiser profile entity full view — **VERIFY-LIVE** for verified badges / event counts.
- **Note:** Two routes (`/organisers`, `/vendors`) resolve to the same controller — confirm one canonical URL + redirect to avoid duplicate-content / split trust.
- **Priority:** P2.

### 3.8 Registration
- **Route:** `/user/register`, template `page--user-register.html.twig` (shares login shell); MEL onboarding at `/onboard/account` (`CustomerOnboardAccountController`) → explore → first-action → my-tickets.
- **Primary CTA:** Create account; onboarding flow has a 4-step guided path.
- **Empty/error states:** Standard Drupal form validation. MEL onboarding adds guided continuity.
- **Priority:** P2 — confirm register page styling parity with MEL shell (template is a thin extend).

### 3.9 Login
- **Route:** `/user/login`, template `page--user-login.html.twig`; `myeventlane_auth` alters the form to add create-event messaging (incl. under Gin Login).
- **Trust/UX:** Contextual organiser messaging on login is a nice conversion nudge.
- **Priority:** P2 — confirm password-reset email styling (`mimemail-message--user--password-reset.html.twig` exists).

---

## 4. Customer journey

### 4.1 Purchase ticket / Checkout
- **Routes:** `/event/{node}/book` (`BookController`), `/cart/attendee-info/{order_item}` (`AttendeeInfoController`), Commerce checkout flow (`mel_event_checkout` via `myeventlane_checkout_flow`), completion template `commerce/commerce-checkout-completion.html.twig` (215 lines).
- **Primary CTA:** Book → checkout → pay.
- **Empty states:** `commerce/commerce-cart-empty-page.html.twig` — governed empty state (`mel_cart_empty_state` from PHP), illustration, help/support trust nav. Strong.
- **Completion:** Governed copy via `MelCustomerContinuityPresenter`; success hero, email line, order reference, per-event ticket cards with images (`alt=""` decorative). Strong continuity.
- **Trust:** Checkout trust nav (Help Centre / Support), tax-invoice PDF template present (`mel-tax-invoice-pdf.html.twig`).
- **Commerce risk:** Attendee-info capture per order item; donation/boost line items filtered out of ticket summary. **VERIFY-LIVE**: payment-state gating before "paid" confirmation copy (`mel_confirm_paid` flag exists — confirm it is driven by actual payment state, not order placement).
- **Accessibility:** Confirmation hero uses `role="region"` + `aria-labelledby`. Good.
- **Priority:** P0 — **verify `mel_confirm_paid` cannot show a "paid/confirmed" state for an unpaid/pending order** (Commerce payment-state correctness).

### 4.2 RSVP
- **Routes:** `/event/{event}/rsvp` (`RsvpRedirectController`), `/event/{node}/rsvp/form` (`RsvpFormController`), thank-you `/event/{event}/rsvp/thank-you`, cancel `/rsvp/{rsvp_id}/cancel` (custom access `rsvp_cancel_access`), ICS bundle `/my-profile/download-rsvps.ics`.
- **Access:** Public RSVP via `_permission: access content`; cancel gated by custom access service; vendor RSVP management gated by `vendor_event_access`. Sound separation.
- **Empty/confirmation:** Dedicated thank-you + "RSVP Confirmed" title; ICS download for calendar add. Strong.
- **Priority:** P2.

### 4.3 Waitlist
- **Routes:** `/event/{node}/waitlist/signup`, `/event/{node}/waitlist/position` (`WaitlistController`), vendor management `/vendor/event/{node}/waitlist` (+ export).
- **Purpose:** Capacity overflow capture + position feedback.
- **Status:** Controllers present; **VERIFY-LIVE** customer-facing signup confirmation + position display copy.
- **Priority:** P1 — waitlist is a capacity-trust feature; confirm the customer sees position + next-step messaging.

### 4.4 Emails (transactional)
- **Evidence:** `myeventlane_messaging` email base template `email/mel-email-base.html.twig`; `mimemail-message.html.twig`, password-reset override; notification inbox/bell (`myeventlane_notifications`); unsubscribe route `/email/unsubscribe/{uid}/{ts}/{h}` with signed token.
- **Trust:** Branded email base + unsubscribe + preference management present.
- **Gaps:** Full per-event transactional set (order confirmation, ticket delivery, refund, reminder) **not enumerated in this pass** — **VERIFY-LIVE** the complete mail-key inventory and that ticket PDFs/wallet passes attach or link.
- **Priority:** P1 — confirm complete transactional email coverage + deliverability.

### 4.5 My Tickets
- **Route:** `/my-tickets` (`MyTicketsController`, 171 lines), detail `/my-tickets/order/{commerce_order}`; template delegated to `myeventlane_checkout_flow` (parity-governed via `mel-template-parity.json`).
- **Artifacts:** Ticket PDF (`/ticket/pdf/{order_item_id}` + by-code), wallet passes (`/wallet/apple/...`, `/wallet/google/...`), resend, assign-by-token (`/ticket/assign/{token}`).
- **Empty states:** **VERIFY-LIVE** "no tickets yet" state copy.
- **Trust/UX:** Rich post-purchase toolset (PDF, wallet, transfer/assign). Strong.
- **Priority:** P2.

### 4.6 Saved Events
- **Status:** **Repository evidence not found** for a dedicated customer "Saved Events" / wishlist / favourites route or feature. `/my-categories` (interest personalisation) exists but is not a saved-events list.
- **Impact:** A journey step named in the launch spec has no implementation evidence. Either the feature is out of scope for v1 or it is missing.
- **Priority:** P1 — product decision: implement, or remove "Saved Events" from launch messaging/nav.

### 4.7 Customer Dashboard
- **Routes:** `/my-account` (`MyAccountController`), `/my-events` (`CustomerDashboardController`), `/my-past-events`, `/my-settings/{user}`, `/my-categories`.
- **Purpose:** Account hub: events, past events, settings, interests.
- **Empty states:** **VERIFY-LIVE** per-tab empty states.
- **Trust:** Settings + profile management present.
- **Priority:** P2.

### 4.8 Refund journey (customer)
- **Routes:** `/my-tickets/order/{commerce_order}/refund` (`myeventlane_refunds.buyer_refund`, `BuyerRefundForm`, 283 lines); vendor side `/vendor/events/{node}/refund-requests` + approve/reject; escalations refund portal.
- **Commerce risk (high):** Refunds touch payment + order state. Form is substantial (283 lines) implying validation. **VERIFY-LIVE**: refund cannot be requested twice, cannot exceed paid amount, and is gated to the order owner.
- **Trust:** Customer-initiated request → vendor approval workflow is appropriate.
- **Priority:** P0 — verify refund ownership + amount + idempotency guards before launch.

### 4.9 Accessibility (cross-cutting)
- **Evidence:** Skip links (`mel-discovery-page-shell`), `<main id="main-content">` landmarks, visually-hidden headings, `role`/`aria-labelledby` on confirmation, `alt` on event hero, decorative images `alt=""` + `aria-hidden`. 124/236 templates carry a11y primitives.
- **Gaps:** Coverage is strong but not universal (112 templates without primitives — many are non-visual/partials). **VERIFY-LIVE** keyboard traps on booking/checkout, focus order, colour contrast against pastel tokens.
- **Priority:** P1 — formal WCAG 2.1 AA pass on the booking + checkout + login critical path.

### 4.10 Mobile (cross-cutting)
- **Evidence:** `MobileBottomNavigationBuilder` service + `mel_mobile_bottom_nav` region in discovery shell; mobile-first token system; full-bleed hero / contained content pattern.
- **Gaps:** **VERIFY-LIVE** booking sidebar → mobile stacked CTA prominence; checkout on small viewports.
- **Priority:** P1 — device-matrix pass on event page + checkout.

---

## 5. Organiser journey

### 5.1 Onboarding
- **Routes:** `/vendor/onboard` → `account` → `profile` → `stripe` → `branding` → `first-event` → `boost` → `complete` (dedicated controllers each step); terms `/vendor/onboard/terms`.
- **UX:** Clear linear, well-segmented onboarding with payments + branding + first-event built in. Strong.
- **Priority:** P2 — confirm progress indicator + resumability.

### 5.2 Dashboard
- **Route:** `/vendor/dashboard` (`vendor_dashboard:dashboard`); controller `VendorDashboardController.php` is **2,856 lines** — very large; maintainability risk (not a launch blocker).
- **Purpose:** Organiser home: events, sales, actions.
- **Priority:** P2 (UX) / P3 (refactor debt).

### 5.3 Event Studio / Build wizard
- **Routes:** Wizard `/vendor/events/{event}/build/{basics,when-where,tickets,details,review,publish,success}`; Studio `/vendor/studio/event/{event}/{overview,data,tickets,attendees,promotion,settings,publish,submit-review}` (`VendorStudioController`); manage-event tabs (`/vendor/event/{event}/{edit,content,design,tickets,checkout-questions,...}`).
- **Note:** Two parallel authoring surfaces exist (build wizard **and** studio **and** manage-event). **Confirm which is the canonical launch path** — multiple authoring entry points risk organiser confusion and divergent behaviour.
- **Priority:** P1 — declare and signpost one canonical event-authoring path.

### 5.4 Publish
- **Routes:** Wizard `/vendor/events/{event}/build/publish` + `/build/success`; studio `/vendor/studio/event/{event}/publish` + `submit-review`.
- **Risk:** Review/moderation gate (`submit-review`) implies an approval workflow. **VERIFY-LIVE** publish vs submit-for-review states are consistent across both authoring surfaces.
- **Priority:** P1.

### 5.5 Promotion / Boost
- **Routes:** Boost wizard `/vendor/events/{event}/boost/wizard/step-1..5` (`WizardController`), `/vendor/boost`, bridge add-to-cart, performance guide PDF.
- **Commerce risk:** Boost is a paid line item (filtered out of ticket summaries in checkout completion — confirmed). 5-step wizard ending in payment. Strong structure.
- **Priority:** P2.

### 5.6 Analytics / Insights / Reporting
- **Routes:** `/vendor/analytics` (+ per-event, Excel/PDF export), `/vendor/insights`, `/vendor/events/{event}/insights/{overview,attendees,checkins,sales,traffic}`, chart JSON endpoints (`/vendor/charts/*`).
- **Note:** Multiple analytics surfaces (`myeventlane_analytics`, `myeventlane_reporting`, `myeventlane_vendor_analytics`). **Confirm canonical analytics home** to avoid organiser confusion.
- **Priority:** P2.

### 5.7 Attendees / Check-in
- **Routes:** `/vendor/attendees`, `/vendor/events/{event}/attendees` (+ export CSV), check-in surfaces (`myeventlane_checkin`, ticket scan, RSVP check-in, PWA manifest/sw for offline scan).
- **Strength:** PWA offline check-in (`manifest.json`, `sw.js`) is launch-grade for on-site ops.
- **Priority:** P2 — confirm one canonical attendee/check-in path (multiple modules overlap: `myeventlane_checkin`, `myeventlane_checkout_flow`, `myeventlane_tickets`, `myeventlane_rsvp`).

### 5.8 Messaging
- **Routes:** `/vendor/events/{node}/comms` (send update), `/comms/branding`, `/vendor/dashboard/messaging/brand`, `/vendor/audience`.
- **Trust:** Branded organiser comms + audience targeting present.
- **Priority:** P2.

### 5.9 Payouts / Finance
- **Routes:** `/vendor/payouts` (`vendor_payouts:payouts`), Stripe Connect `/vendor/stripe/connect` + `/manage` + callback, BAS report `/vendor/finance/bas` (+ CSV/PDF), donations received.
- **Commerce risk (high):** Payouts + Stripe Connect + BAS (AU tax). **VERIFY-LIVE**: payout figures reconcile to settled orders only; no payout shown before funds settle; Connect callback validates state.
- **Priority:** P0 — verify payout amounts derive from settled/paid orders and the Stripe webhook (`/stripe/webhook/payout`) verifies signatures.

### 5.10 Pro features
- **Routes:** `/vendor/pro` (`ProOverviewController`, 202 lines), `subscribe`, `manage`, `cancel`, `success`, Pro branding (`/vendor/settings/branding`, `ProBrandingController`), Pro comms.
- **Commerce risk:** Subscription lifecycle (subscribe/cancel/success). **VERIFY-LIVE** entitlement gating: Pro-only features deny gracefully for non-Pro organisers.
- **Priority:** P1 — confirm Pro entitlement checks on every Pro-gated route.

---

## 6. Cross-cutting strengths (evidence-based)

1. **Governed copy & empty states** — empty states (cart, discovery) are PHP-governed, not ad-hoc Twig, reducing inconsistent microcopy.
2. **Security posture on public surfaces** — search/discovery routes documented and access-scoped to anonymous-safe Search API indexes; RSVP/refund/vendor routes use custom access services, not blanket permissions.
3. **Design-system discipline** — locked hero contract, single token source, card contract (`DESIGN_SYSTEM.md`) with parity enforcement (`mel-template-parity.json`).
4. **Post-purchase richness** — PDF + Apple/Google Wallet + transfer/assign + resend.
5. **On-site ops** — PWA offline check-in.

## 7. Cross-cutting risks (evidence-based)

| # | Risk | Evidence | Priority |
| --- | --- | --- | --- |
| R1 | Paid-state confirmation copy may not be bound to actual payment state | `mel_confirm_paid` flag in checkout completion template | P0 |
| R2 | Refund idempotency / amount / ownership guards unverified | `BuyerRefundForm` (283 lines) | P0 |
| R3 | Payout figures vs settled funds + webhook signature | `/vendor/payouts`, `/stripe/webhook/payout` | P0 |
| R4 | Multiple event-authoring surfaces (wizard / studio / manage-event) | route catalogue | P1 |
| R5 | Multiple analytics + check-in surfaces (canonical path unclear) | route catalogue | P1/P2 |
| R6 | "Saved Events" journey has no implementation evidence | grep, route catalogue | P1 |
| R7 | `/calendar` rendering/linking unconfirmed | template only | P1 |
| R8 | Duplicate organiser directory URLs (`/organisers`, `/vendors`) | route catalogue | P2 |
| R9 | WCAG AA not formally verified on critical path | partial a11y coverage | P1 |
| R10 | Transactional email set not fully enumerated | messaging templates | P1 |

---

## 8. Overall acceptance verdict

See `customer-scorecard.md` for the numeric launch-readiness score and
`customer-launch-checklist.md` for the go/no-go gate.

**Headline:** MyEventLane presents as a **mature, design-governed, security-conscious platform**
with launch-grade discovery, event, checkout, ticketing, and organiser tooling. **It is not yet
acceptance-clear** because three P0 Commerce-correctness items (paid-state, refunds, payouts)
and several P1 path-clarity/coverage items require **live verification** that cannot be
discharged from repository evidence alone.

> Per audit discipline: no validation commands were run; no "tests passed" claim is made.
> All scores are repository-evidence assessments. Live-verification items are tracked as
> **VERIFY-LIVE** in the launch checklist.
