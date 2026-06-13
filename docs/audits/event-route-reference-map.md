# Event Route Reference Map — Phase 1A (WP-3)

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Method:** Static grep across `web/modules/custom` and `web/themes/custom` for `*.php`, `*.twig`, `*.yml`, `*.js`. Test fixtures and `*.routing.yml` definition files excluded from "Referenced By" unless they are the only reference.

**Legend:** `DYNAMIC_REFERENCE` = route name built at runtime (variable, config key, or string prefix match), not a static literal in the file.

---

## A. Create-event entry routes

| Route | Referenced By | File |
|-------|---------------|------|
| `myeventlane_vendor.create_event_gateway` | Footer host menu link | `web/modules/custom/myeventlane_core/myeventlane_core.links.menu.yml` |
| `myeventlane_vendor.create_event_gateway` | Blog CTA URL builder | `web/modules/custom/myeventlane_front/src/Service/BlogArticlePresentationService.php` |
| `myeventlane_vendor.create_event_gateway` | Organiser hub CTAs | `web/modules/custom/myeventlane_front/src/Service/OrganiserHubPageBuilder.php` |
| `myeventlane_vendor.create_event_gateway` | Public footer nav builder | `web/modules/custom/myeventlane_front/src/Service/PublicFooterNavigationBuilder.php` |
| `myeventlane_vendor.create_event_gateway` | Home hero secondary CTA | `web/modules/custom/myeventlane_front/templates/myeventlane-home-hero.html.twig` |
| `myeventlane_vendor.create_event_gateway` | Header create button | `web/themes/custom/myeventlane_theme/templates/region--header.html.twig` |
| `myeventlane_vendor.create_event_gateway` | Hero, footer CTA, host CTA, calendar | `web/themes/custom/myeventlane_theme/components/hero/hero.twig`, `templates/includes/mel-*-default.html.twig`, `templates/page--calendar.html.twig`, `templates/components/mel-calendar-hero.html.twig` |
| `myeventlane_vendor.create_event_gateway` | Radix header (theme on disk) | `web/themes/custom/myeventlane_radix/templates/includes/header.html.twig` |
| `myeventlane_vendor.create_event_gateway` | Public create-event URL helper | `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` (`_myeventlane_theme_public_create_event_url`) |
| `myeventlane_vendor.create_event_gateway` | Hard-coded path alias | `web/themes/custom/myeventlane_theme/templates/views/views-view-unformatted--upcoming-events--homepage-latest.html.twig` (`/create-event`) |
| `myeventlane_vendor.create_event_gateway` | Hard-coded path alias | `web/themes/custom/myeventlane_theme/components/mobile-drawer/mobile-drawer.twig` (default `/create-event`) |
| `myeventlane_vendor.create_event_gateway` | Config URL | `config/sync/myeventlane_front.organiser_hub.yml` (`url: /create-event`) |
| `myeventlane_vendor.create_event_gateway` | Post-auth destination | `web/modules/custom/myeventlane_auth/src/Service/PostAuthRedirectResolver.php` |
| `myeventlane_vendor.create_event_gateway` | Destination normalizer | `web/modules/custom/myeventlane_core/src/Service/MelDestinationNormalizer.php`, `VendorLoginDestinationNormalizer.php` |
| `myeventlane_event_studio.create` | Account menu "Create event" (pre-consolidation) | `web/modules/custom/myeventlane_vendor/myeventlane_vendor.links.menu.yml` |
| `myeventlane_event_studio.create` | Vendor footer URL map | `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`, `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` |
| `myeventlane_event_studio.create` | Dashboard / events index CTAs | `VendorDashboardController.php`, `VendorDashboardViewModelBuilder.php`, `VendorEventIndexViewModelBuilder.php`, `VendorActionQueueBuilder.php` |
| `myeventlane_event_studio.create` | Organiser context block | `web/modules/custom/myeventlane_core/src/Plugin/Block/OrganiserContextBlock.php` |
| `myeventlane_event_studio.create` | Legacy dashboard module | `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`, `templates/myeventlane-vendor-dashboard.html.twig` |
| `myeventlane_event_studio.create` | Analytics / reporting empty states | `VendorAnalyticsViewModelBuilder.php`, `myeventlane-reporting-vendor-insights.html.twig` |
| `myeventlane_event_studio.create` | Vendor theme empty-state CTAs | `event-table.html.twig`, `vendor-event-performance.html.twig`, `mel-vendor-event-performance.html.twig` |
| `myeventlane_event_studio.create` | Gateway redirect target | `CreateEventGatewayController.php` |
| `myeventlane_event_studio.create` | Legacy `/vendor/events/add` redirect | `VendorEventCreateController.php` |
| `myeventlane_event_studio.create` | Onboarding completion CTAs | `VendorOnboardCompleteController.php`, `VendorOnboardFirstEventController.php`, `VendorOnboardProfileForm.php`, `VendorOnboardStripeController.php`, `VendorOnboardController.php` |
| `myeventlane_event_studio.create` | Post-login router | `PostLoginRouter.php` |
| `myeventlane_event_studio.create` | Growth insight action | `GrowthInsightService.php` |
| `myeventlane_event_studio.create` | Operational templates link | `GovernedOperationalTemplates.php` |
| `myeventlane_event_studio.create` | Bulk actions form | `VendorEventsBulkActionsForm.php` |
| `myeventlane_event_studio.create` | Launch protection allowlist | `LaunchRequestProtectionSubscriber.php` |
| `myeventlane_event_studio.create` | Vendor theme negotiator | `VendorThemeNegotiator.php` |
| `myeventlane_event_studio.create` | Onboarding gate subscriber | `EventStudioVendorOnboardingGateSubscriber.php` |
| `myeventlane_event_studio.create` | DYNAMIC_REFERENCE | `OnboardingManager.php` (help listen/ask routes; milestone flags) |
| `myeventlane_vendor.console.events_add` | Controller log + redirect | `VendorEventCreateController.php` |
| `myeventlane_vendor.console.events_add` | Launch protection allowlist | `LaunchRequestProtectionSubscriber.php` |

---

## B. Event Studio edit and workspace routes

| Route | Referenced By | File |
|-------|---------------|------|
| `myeventlane_event_studio.edit` | Gateway draft resume redirect | `CreateEventGatewayController.php` |
| `myeventlane_event_studio.edit` | Vendor card edit link | `web/themes/custom/myeventlane_vendor_theme/templates/node--event--vendor-card.html.twig` |
| `myeventlane_event_studio.edit` | Manage event / boost / ticket forms | `EventDesignForm.php`, `EventTicketManagerForm.php`, `BoostPerformanceService.php` |
| `myeventlane_event_studio.edit` | Vendor Studio redirect target | `VendorStudioController.php` |
| `myeventlane_event_studio.edit` | Legacy redirect subscriber | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event_studio.workspace` | Dashboard deep links | `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` |
| `myeventlane_event_studio.workspace_analytics` | Dashboard analytics links | `dashboard.html.twig`, `mel-vendor-event-performance.html.twig` |
| `myeventlane_event_studio.workspace_*` (sections) | Section plugins | `web/modules/custom/myeventlane_event_studio/src/Plugin/EventStudioSection/*.php` |
| `myeventlane_event_studio.workspace_extras` | Commerce sales summary | `EventOperationalExtrasSalesSummaryBuilder.php` |
| `myeventlane_event_studio.workspace_*` | DYNAMIC_REFERENCE | `VendorLegacyWizardRedirectSubscriber.php` (redirect map) |
| `myeventlane_event_studio.publish` | Studio template / JS | `mel-event-studio.html.twig`, Event Studio libraries |
| `myeventlane_event_studio.autosave` | Autosave controller access check | `EventStudioAutosaveController.php` |
| `myeventlane_event_studio.ai_assist` | AI assist builder | `EventStudioAiAssistBuilder.php` |
| `myeventlane_event_studio.edit_basic` | Legacy redirect subscriber only | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event_studio.edit_datetime` | Legacy redirect subscriber only | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event_studio.edit_tickets` | Legacy redirect subscriber only | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event_studio.edit_description` | Legacy redirect subscriber only | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event_studio.edit_preview` | Legacy redirect subscriber only | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event_studio.edit_publish` | Legacy redirect subscriber only | `VendorLegacyWizardRedirectSubscriber.php` |

---

## C. Vendor console event workspace routes

| Route | Referenced By | File |
|-------|---------------|------|
| `myeventlane_vendor.console.events` | Account menu, footer URLs, dashboard | `myeventlane_vendor.links.menu.yml`, theme footer helpers, `VendorDashboardViewModelBuilder.php` |
| `myeventlane_vendor.console.event_workspace` | DYNAMIC_REFERENCE | `VendorEventTabsService.php`, `EventWorkspaceController.php` |
| `myeventlane_vendor.console.event_overview` | Legacy redirect subscriber | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_vendor.console.event_orders` | Event tabs / orders controllers | `VendorEventOrdersController.php`, `VendorEventTabsService.php` |
| `myeventlane_vendor.console.event_tickets` | Ticket manager form redirect | `EventTicketManagerForm.php`, `ManageEventTicketsController.php` |
| `myeventlane_vendor.console.event_analytics` | Analytics controller | `VendorEventAnalyticsController.php` |
| `myeventlane_vendor.console.event_promotion` | Vendor comms | `VendorEventCommsForm.php` |
| `myeventlane_vendor.console.studio` | Redirect entry only | `VendorStudioController.php` |
| `myeventlane_vendor.console.studio.event_*` | Vendor Studio JSON API URLs | `VendorStudioController.php` (lines ~1162-1168) |
| `myeventlane_vendor.manage_event.edit` | Legacy redirect subscriber | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_vendor.manage_event.design` | Manage event navigation + redirect subscriber | `ManageEventNavigation.php`, `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_vendor.manage_event.content` | Manage controllers + twig tickets nav | `ManageEventContentController.php`, `event/tickets.html.twig` |
| `myeventlane_vendor.manage_event.tickets` | Twig nav + redirect subscriber | `event/tickets.html.twig`, `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_vendor.manage_event.checkout_questions` | Twig nav | `event/tickets.html.twig` |
| `myeventlane_vendor.manage_event.series` | Series forms + dashboard | `GenerateSeriesInstancesForm.php`, `VendorDashboardController.php` |
| `myeventlane_vendor.manage_event.promote` | Placeholder subscriber + nav | `ManageEventPlaceholderNoIndexSubscriber.php`, `ManageEventNavigation.php`, `VendorDashboardViewModelBuilder.php` |
| `myeventlane_vendor.manage_event.payments` | Twig nav (stub target) | `event/tickets.html.twig` |
| `myeventlane_vendor.manage_event.{comms,advanced}` | Placeholder subscriber + nav | `ManageEventPlaceholderNoIndexSubscriber.php`, `ManageEventNavigation.php` |

---

## D. Legacy wizard routes (`myeventlane_event.wizard.*`)

| Route | Referenced By | File |
|-------|---------------|------|
| `myeventlane_event.wizard.basics` | Wizard base form steps | `EventWizardBaseForm.php` |
| `myeventlane_event.wizard.when_where` | Wizard base form + twig | `EventWizardBaseForm.php`, `event-wizard-tickets.html.twig` |
| `myeventlane_event.wizard.tickets` | Wizard forms + review | `EventWizardReviewForm.php`, `event-wizard-tickets.html.twig`, `WizardReviewSummaryBuilder.php` |
| `myeventlane_event.wizard.details` | Wizard base + review twig | `EventWizardBaseForm.php`, `event-wizard-review.html.twig` |
| `myeventlane_event.wizard.review` | Wizard controller + publish form | `VendorEventWizardController.php`, `EventWizardPublishForm.php`, twig templates |
| `myeventlane_event.wizard.publish` | Wizard base + review form | `EventWizardBaseForm.php`, `EventWizardReviewForm.php` |
| `myeventlane_event.wizard.success` | Publish form redirect | `EventWizardPublishForm.php` |
| `myeventlane_event.wizard.*` | Vendor redirect (all steps) | `VendorLegacyWizardRedirectSubscriber.php` |
| `myeventlane_event.wizard.*` | DYNAMIC_REFERENCE | `HelpContextResolver.php` (`str_starts_with($route_name, 'myeventlane_event.wizard')`) |
| `myeventlane_event.wizard.*` | Page template docblock | `page--vendor--event-wizard.html.twig` |

---

## E. Prefix / dynamic reference patterns

| Pattern | Referenced By | File |
|---------|---------------|------|
| `myeventlane_event_studio.*` | Help context | `HelpContextResolver.php` |
| `myeventlane_event_studio.*` | Vendor theme negotiator list | `VendorThemeNegotiator.php` |
| `myeventlane_event_studio.*` | Surface workflow registry | `MelWorkflowRegistry.php` |
| `myeventlane_vendor.console.*` | DYNAMIC_REFERENCE | `VendorConsoleAccess.php` (path prefix checks) |
| `/create-event` (path) | Email template hard-coded | `mimemail-message--user--register-no-approval-required.html.twig` |
| `/create-event` (path) | Trust content foundation | `TrustContentFoundation.php` |

---

## F. Reference counts (grep, 2026-06-13)

| Pattern | Match lines (approx.) | Files (approx.) |
|---------|----------------------|-----------------|
| `myeventlane_event_studio.*` | 251 | 80+ |
| `myeventlane_vendor.*` (console/manage/onboard/studio) | 426 | 60+ |
| `myeventlane_event.wizard.*` | 54 | 15 |

Reproduce:

```bash
rg -n "myeventlane_event_studio\." web/modules/custom web/themes/custom --glob '*.{php,twig,yml,js}'
rg -n "myeventlane_vendor\.(console|manage_event|create_event|onboard|studio)" web/modules/custom web/themes/custom --glob '*.{php,twig,yml,js}'
rg -n "myeventlane_event\.wizard" web/modules/custom web/themes/custom --glob '*.{php,twig,yml,js}'
```

---

## G. Phase 1A consolidation note

After WP-3 navigation consolidation, user-facing **Create event** links should reference `myeventlane_vendor.create_event_gateway`. Internal post-gateway redirects and onboarding flow continuations may still reference `myeventlane_event_studio.create` by design (`CreateEventGatewayController.php:213`).
