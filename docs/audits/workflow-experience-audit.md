# Workflow Experience Audit

**Date:** 2026-06-23  
**Scope:** End-to-end organiser and customer journeys (routes, menus, templates, copy, empty states, confirmation flows).  
**Method:** Repository grep, routing/controllers, existing audits cross-reference. No invented routes or fields.

---

## Executive summary

MEL already has strong workflow infrastructure: Event Studio as the organiser editing surface, `MelCustomerContinuityPresenter` for RSVP/checkout confirmation CTAs, governed empty-state copy in `MelReadinessHelper`, and a canonical customer hub via `AccountLinksService`. Remaining friction is mostly **terminology drift** (Vendor/Dashboard/Analytics in UI), **duplicate legacy URLs** (redirected silently to Studio), **Pro-gated analytics**, and **a few empty states** not wired to governed copy.

Recommended approach: small label/copy passes first, then empty-state wiring, then confirmation route alignment — no architecture changes.

---

## 1. Organiser journey map

| Step | Route(s) | Path | Access | Primary files |
|------|----------|------|--------|---------------|
| 1. Register | `user.register` | `/user/register` | Core registration | Onboard entry: `VendorOnboardAccountController` → `/vendor/onboard/account` |
| 2. Become organiser | `myeventlane_vendor.onboard.*` | `/vendor/onboard/...` | `access content` + logged in (later steps) | `myeventlane_vendor.routing.yml`, onboard templates |
| 3. Connect Stripe | `myeventlane_vendor.onboard.stripe`, `myeventlane_vendor.stripe_connect` | `/vendor/onboard/stripe`, `/stripe/connect` | Onboard: logged in; Connect: `access vendor console` | `StripeConnectController.php` |
| 4. Create RSVP event | `myeventlane_event_studio.create`, `myeventlane_vendor.create_event_gateway` | `/vendor/events/create`, `/create-event` | Studio: vendor console; gateway: public | `EventStudioTicketsForm` (`field_event_type` = `rsvp`) |
| 5. Create paid event | Same as step 4 | Same | Same | `field_event_type` = `paid`; Stripe checked at publish |
| 6. Publish | `myeventlane_event_studio.publish` (POST) | `/vendor/events/{node}/studio/publish` | `EventStudioAccess` + CSRF | `EventStudioPublishController.php` |
| 7. Organiser home | `myeventlane_vendor.console.dashboard` | `/vendor/dashboard` | `access vendor console` + `access vendor dashboard` | `VendorDashboardController.php`, `dashboard/dashboard.html.twig` |
| 8. Attendees | `myeventlane_event_studio.workspace_attendees` | `/vendor/events/{node}/studio/attendees` | `EventStudioAccess` | Legacy `/vendor/events/{node}/attendees` → redirect |
| 9. Insights | `myeventlane_analytics.dashboard`, `myeventlane_event_studio.workspace_analytics` | `/vendor/analytics`, `.../studio/analytics` | Pro gate on main analytics; Studio section readonly | `AnalyticsDashboardController.php` |
| 10. Grow / share | `myeventlane_vendor.console.boost`, `myeventlane_boost.*`, dashboard growth cards | `/vendor/boost`, `/vendor/events/{event}/boost` | `vendor_console` | `VendorBoostController.php`, `GrowthInsightService` |

**Implemented flow:**

```text
Register → /vendor/onboard/account → profile → stripe (optional) → branding → first-event → boost → complete
→ /create-event or /vendor/events/create → Event Studio draft → tickets (RSVP/paid) → POST publish
→ /vendor/dashboard → studio attendees / analytics / boost
```

---

## 2. Customer journey map

| Step | Route(s) | Path | Primary files |
|------|----------|------|-----------------|
| 1. Find event | `view.upcoming_events.page_events`, `mel_search.view`, homepage blocks | `/events`, `/search`, `/home` | `views.view.upcoming_events.yml` |
| 2. View event | `entity.node.canonical` | `/events/{title}` (Pathauto) | Event theme templates |
| 3. RSVP | `myeventlane_commerce.event_book` | `/event/{node}/book` | `RsvpPublicForm.php` |
| 4. Buy ticket | `myeventlane_commerce.event_book` → `commerce_cart.page` → `commerce_checkout.form` | `/event/{node}/book` → `/cart` → `/checkout/{order}/{step}` | `TicketSelectionForm.php`, `MelEventCheckoutFlow` |
| 5. Confirmation | RSVP: `myeventlane_rsvp.thankyou`; Paid: checkout `complete` step | `/event/{event}/rsvp/thank-you`, `/checkout/{order}/complete` | `MelCustomerContinuityPresenter`, `commerce-checkout-completion.html.twig` |
| 6. Add to calendar | `myeventlane_rsvp.ics_download`, checkout calendar links | `/event/{node}/ics` | `MyTicketsOrderViewModelBuilder::calendarUrl()` |
| 7. View ticket | `myeventlane_checkout_flow.order_detail` | `/my-tickets/order/{commerce_order}` | `myeventlane-order-detail.html.twig` |
| 8. Download PDF | `myeventlane_tickets.download_pdf_by_code` | `/ticket/{ticket_code}/pdf` | `TicketDownloadController.php` |
| 9. My Tickets | `myeventlane_checkout_flow.my_tickets` | `/my-tickets` | `MyTicketsController.php` |

**Related hub routes:** `myeventlane_account.dashboard` (`/my-account`), `myeventlane_dashboard.customer` (`/my-events`), `myeventlane_rsvp.user_list` (`/user/{user}/rsvps`).

---

## 3. Route / menu inventory

### Organiser account menu (`myeventlane_vendor.links.menu.yml`)

| Menu ID | Label (pre-change) | Route |
|---------|-------------------|-------|
| `menu_account.dashboard` | Dashboard | `myeventlane_vendor.console.dashboard` |
| `menu_account.events` | My events | `myeventlane_vendor.console.events` |
| `menu_account.create_event` | Create event | `myeventlane_vendor.create_event_gateway` |
| `menu_account.settings` | Settings | `myeventlane_vendor.console.settings` |

Vendor shell sidebar built in `myeventlane_vendor_theme.theme` (`_myeventlane_vendor_theme_build_*_shell_nav_items`).

### Customer hub nav (`AccountLinksService.php`)

| ID | Label (pre-change) | Route / path |
|----|-------------------|--------------|
| dashboard | Dashboard | `/my-account` |
| tickets | Tickets | `/my-tickets` |
| saved_events | Saved events | `/my-saved-events` |
| categories | Categories | `/my-categories` |
| followed_organisers | Organisers | `/my-organisers` |
| notifications | Notifications | `/my-notifications` |
| support | Support | escalations portal |
| settings | Settings | `/my-settings/{user}` |

Mobile bottom nav: `MobileBottomNavigationBuilder.php` (Events + Account tabs).

### Event Studio sections (`myeventlane_event_studio.routing.yml`)

Canonical workspace at `/vendor/events/{node}/studio/{section}` — information, branding, content, tickets, questions, capacity, extras, messaging, attendees, fulfilment, orders, analytics, settings. Legacy wizard/manage-event routes redirect via `VendorLegacyWizardRedirectSubscriber`.

---

## 4. Terminology inventory

| System / machine | User-facing (target) | Where still mixed |
|------------------|---------------------|-------------------|
| `vendor` entity/role | Organiser | Public header "Vendors", help "Vendor help", some footers |
| `myeventlane_vendor.console.dashboard` | Home / Organiser home | "Dashboard" in menus, headers, footers |
| Analytics modules/routes | Insights | Vendor sidebar, dashboard panels, events grid links |
| Promotion / boost | Grow event | Dashboard "Promotion guidance", sidebar "Promote event" |
| Commerce order | Booking | Checkout labels "View order"; order state copy |
| Submit | Save / Continue / Publish | Wizard comments only (low risk) |
| Vendor workspace | Event Studio | `layout/page.html.twig` default shell title |

**Stable (do not rename):** route names, permissions, entity types, config IDs, Stripe routes.

---

## 5. Friction points

### Duplicate destinations

1. Three create-event entries: `/create-event`, `/vendor/events/create`, `/vendor/events/add`.
2. Attendees: Studio attendees, `/vendor/attendees` (store-scoped), legacy RSVP list routes.
3. Analytics: `/vendor/analytics` (Pro), per-event legacy route, Studio analytics section.
4. Dashboard aliases: `/dashboard`, `/vendor`, `/vendor/dashboard` (first two redirect).
5. Stripe callbacks: three callback path variants (documented in `mel-stripe-connect-audit.md`).

### Access / trust gaps

1. **Dashboard double permission:** `access vendor console` + `access vendor dashboard` blocks incomplete onboarding from most `/vendor/*`.
2. **Pro-gated `/vendor/analytics`:** base `vendor` role lacks `use pro financial analytics`; Studio analytics is readonly fallback.
3. **Checkout flow config drift:** sync `commerce_order.commerce_order_type.default` may use `default` flow while optional config sets `mel_event_checkout` (see `mel-booking-checkout-verification.md`).
4. **Confirmation primary CTA** previously linked to `entity.commerce_order.user_view` instead of MEL ticket pass UI (`myeventlane_checkout_flow.order_detail`).

### Placeholder / dead-end surfaces

- `ManageEventPlaceholderController`: promote, payments, comms, advanced — "coming soon".
- Event Studio deferred sections: merchandise, fulfilment empty states via `EventStudioEmptyStateBuilder`.

### Onboarding inconsistencies

- Onboard step count: 7 vs 6 between account and profile controllers.
- Stripe optional for RSVP during onboarding; required understanding at paid publish (gate exists).

---

## 6. Mobile risks

- Public header uses dropdown nav; primary CTA "Create event" present at 390px baseline.
- Vendor dashboard uses collapsible `<details>` for analytics/account — good for mobile density; ensure 44px tap targets on action buttons (theme SCSS targets this).
- Customer My Tickets uses card layout; governed empty state on overview.
- Legacy redirect layer may confuse bookmarked URLs on mobile share sheets.

---

## 7. Empty states (current)

| Surface | Governed? | Source |
|---------|-----------|--------|
| My Tickets (no orders) | Yes | `MelReadinessHelper::customerMyTicketsOverviewEmptySlots()` |
| My Account dashboard sections | Yes | `GovernedOperationalTemplates` |
| My Events (`/my-events`) | Partial | Inline Twig; preprocess provides governed payload but template did not consume it |
| Organiser dashboard (no events) | Partial | `vendorDashboardEmptyStrings()` — title/message only, CTA not always surfaced in events panel |
| Event Studio deferred sections | Yes | `EventStudioEmptyStateBuilder` |
| Saved events View | Inline | `views.view.mel_saved_events.yml` |

---

## 8. Confirmation / ticket recovery (current)

| Surface | Headline | Actions |
|---------|----------|---------|
| RSVP thank-you | "You're going" (governed) | View event, Add to calendar, cancel, discovery CTAs via `MelCustomerContinuityPresenter` |
| Checkout complete | "Great choice…" / governed hero | Primary ticket/order link, calendar row, view event, continuity discovery |
| Order detail | Wallet pass UI | Download PDF, Add to calendar, View event |
| Emails | `order_confirmation`, `rsvp_confirmation` templates in `config/sync/myeventlane_messaging.template.*.yml` |

---

## 9. Recommended changes (ranked)

| Priority | Change | Impact | Effort | Risk |
|----------|--------|--------|--------|------|
| P1 | UI labels: Dashboard→Home, Analytics→Insights, Vendor→Organiser (public nav) | High clarity | Low | Low — strings only |
| P1 | Checkout confirmation primary → `/my-tickets/order/{id}` | Trust + ticket recovery | Low | Low — same access model |
| P2 | Wire My Events empty state to governed template | Empty state quality | Low | Low |
| P2 | Richer organiser first-event empty copy (RSVP vs paid Stripe note) | Onboarding clarity | Low | Low |
| P3 | Consolidate attendee entry points in docs/help only | Reduced confusion | Med | Low |
| P3 | Align checkout flow config (`mel_event_checkout`) in sync | Booking reliability | Med | Medium — config |
| P4 | Remove or hide placeholder manage-event routes from IA | Fewer dead ends | Med | Low |
| P4 | Pro analytics upsell copy when Insights locked | Conversion | Med | Low |

---

## 10. Changes applied in this pass

See git diff for exact strings. Summary:

- **Phase 2:** Organiser/customer label pass (Home, Insights, Grow event, Organiser nav).
- **Phase 3:** Customer My Events governed empty state; organiser empty-state copy + CTA in dashboard.
- **Phase 4:** Checkout completion primary action → MEL order detail route; booking-confirmed headline.

**Intentionally not changed:** route names, permissions, Stripe logic, Pro gating, placeholder route removal, checkout flow config export, admin/staff analytics surfaces.

---

## 11. Validation

```bash
git status --short
composer validate --check-lock
ddev drush cr
find web/modules/custom web/themes/custom -name "*.php" -print0 | xargs -0 -n1 php -l
rg "Vendor|Dashboard|Analytics|Promotion|Submit" web/modules/custom web/themes/custom config/sync
```

---

## 12. Related audits

- `docs/audits/brand-rollout/onboarding-audit.md`
- `docs/audits/mel-booking-checkout-verification.md`
- `docs/audits/mel-stripe-connect-audit.md`
- `docs/audits/event-management-navigation-map.md`
- `docs/audits/mobile-phase-1-priority-review.md`
