# MEL Event and Ticket Creation Audit

Date: 2026-05-02

Branch: `feature/mel-event-ticket-creation-audit`

Source branch: `cursor/remove-submit-msg-1992d`

Source HEAD: `7efe7f81 docs(audit): add staging smoke sign-off`

Scope: audit only. No code changes.

## Executive Summary

MyEventLane already has the right core building blocks for event creation, paid ticket tiers, RSVP, donation checkout, attendee capture, and vendor reporting. The safest path is to consolidate and harden the existing MEL code rather than add new modules or parallel flows.

The current canonical vendor event creation surface is Event Studio at `/vendor/events/create` and `/vendor/events/{node}/edit`. Paid ticketing is centered on the custom `mel_ticket_type` entity, linked from `node.event.field_ticket_types`, and projected into Drupal Commerce as one ticket product with multiple `ticket_variation` variations. RSVP donation checkout already uses Drupal Commerce through `rsvp_donation` orders, so donations should continue through Commerce instead of adding a separate payment path.

The main architectural risk is duplication and drift. Event mode, ticket mode, RSVP mode, publish validation, product sync, attendee questions, and reporting are implemented in several overlapping places: Event Studio, legacy wizard forms, Commerce booking, RSVP forms, analytics/reporting services, and vendor console controllers. Some code is intentionally legacy/staff-only, but some paths are stale or inconsistent enough to create production risk.

Overall risk rating: **High before implementation**, because event publishing, checkout, RSVP donations, attendee PII, and vendor isolation are revenue- and trust-critical. The risk can be reduced with a bounded cleanup sequence that keeps Event Studio, `mel_ticket_type`, Drupal Commerce orders, and server-side vendor access as the canonical paths.

## Installed Platform Versions

Verified with Composer only:

- Drupal core: `drupal/core` `11.3.8`
- Drupal core recommended: `drupal/core-recommended` `11.3.8`
- Drupal Commerce: `drupal/commerce` `3.3.5`
- Commerce Stripe: `drupal/commerce_stripe` `2.2.1`
- Commerce PayPal: `drupal/commerce_paypal` `2.1.2`
- Commerce Recurring: `drupal/commerce_recurring` `1.0.0-rc3`

## Current Architecture Map

### Event Creation and Editing

Canonical vendor routes:

- `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml`
  - `myeventlane_event_studio.create`: `/vendor/events/create`
  - `myeventlane_event_studio.edit`: `/vendor/events/{node}/edit`
  - Step routes: `/edit/basic`, `/edit/datetime`, `/edit/tickets`, `/edit/description`, `/edit/preview`, `/edit/publish`
- `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioMelPayloadService.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php`
- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig`
- `web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js`
- `web/modules/custom/myeventlane_event_studio/css/mel-event-studio.css`
- `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.libraries.yml`

Legacy and redirect surfaces:

- `web/modules/custom/myeventlane_event/src/Form/EventWizardBasicsForm.php`
- `web/modules/custom/myeventlane_event/src/Form/EventWizardWhenWhereForm.php`
- `web/modules/custom/myeventlane_event/src/Form/EventWizardTicketsForm.php`
- `web/modules/custom/myeventlane_event/src/Form/EventWizardDetailsForm.php`
- `web/modules/custom/myeventlane_event/src/Form/EventWizardReviewForm.php`
- `web/modules/custom/myeventlane_event/src/Form/EventWizardPublishForm.php`
- `web/modules/custom/myeventlane_event/src/Service/EventWizardPublishValidator.php`
- `web/modules/custom/myeventlane_event_studio/src/EventSubscriber/VendorLegacyWizardRedirectSubscriber.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventCreateController.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/ManageEventEditController.php`
- `web/modules/custom/myeventlane_event/src/Controller/EventDuplicateController.php`

Event mode and CTA:

- `web/modules/custom/myeventlane_event/src/Service/EventModeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventCtaResolver.php`
- `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php`
- `web/modules/custom/myeventlane_schema/config/install/field.storage.node.field_event_type.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_event_type.yml`

### Paid Ticket Architecture

Canonical ticket tier entity and event relationship:

- `web/modules/custom/mel_ticket/src/Entity/TicketType.php`
- `web/modules/custom/mel_ticket/src/Form/TicketTypeForm.php`
- `web/modules/custom/myeventlane_schema/config/install/field.storage.node.field_ticket_types.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_ticket_types.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.storage.node.field_product_target.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_product_target.yml`
- `config/sync/commerce_product.commerce_product_type.ticket.yml`

Ticket lifecycle and Commerce projection:

- `web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`
- `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php`
- `web/modules/custom/myeventlane_vendor/src/Form/EventTicketsWorkspaceForm.php`
- `web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php`
- `web/modules/custom/myeventlane_tickets/src/Controller/EventTicketsController.php`
- `web/modules/custom/myeventlane_tickets/myeventlane_tickets.routing.yml`
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`

Commerce checkout and capacity enforcement:

- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketStatusEvaluator.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketStatusService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketCapacityService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketVariationSoldService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketTierAccessService.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketAvailabilityCommerceSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketCapacityOrderSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`
- `web/modules/custom/myeventlane_commerce/myeventlane_commerce.module`

### RSVP and Donations

Canonical RSVP booking:

- `web/modules/custom/myeventlane_commerce/myeventlane_commerce.routing.yml`
  - `myeventlane_commerce.event_book`: `/event/{node}/book`
- `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php`
- `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php`
- `web/modules/custom/myeventlane_rsvp/src/Controller/RsvpRedirectController.php`
- `web/modules/custom/myeventlane_rsvp/src/Controller/RsvpFormController.php`
- `web/modules/custom/myeventlane_rsvp/src/Controller/RsvpThankYouController.php`
- `web/modules/custom/myeventlane_rsvp/src/Form/RsvpCancelConfirmForm.php`
- `web/modules/custom/myeventlane_rsvp/src/Access/RsvpCancelAccess.php`

RSVP persistence:

- `web/modules/custom/myeventlane_rsvp/src/Entity/RsvpSubmission.php`
- `web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionManager.php`
- `web/modules/custom/myeventlane_rsvp/src/Service/UserRsvpRepository.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/AttendanceManager.php`
- `web/modules/custom/myeventlane_event_attendees/src/Entity/EventAttendee.php`

Donation checkout:

- `config/sync/myeventlane_donations.settings.yml`
- `web/modules/custom/myeventlane_donations/src/Form/DonationSettingsForm.php`
- `web/modules/custom/myeventlane_donations/src/Service/RsvpDonationService.php`
- `web/modules/custom/myeventlane_donations/src/EventSubscriber/RsvpDonationCheckoutRedirectSubscriber.php`
- `web/modules/custom/myeventlane_rsvp/src/EventSubscriber/RsvpDonationConfirmationSubscriber.php`
- `web/modules/custom/myeventlane_donations/myeventlane_donations.libraries.yml`
- `web/modules/custom/myeventlane_donations/css/donation-rsvp.css`

### Attendee Questions

Authoring and storage:

- `web/modules/custom/myeventlane_vendor/src/Form/EventCheckoutQuestionsForm.php`
- `web/modules/custom/myeventlane_schema/config/install/paragraphs.paragraphs_type.attendee_extra_field.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.storage.node.field_attendee_questions.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_attendee_questions.yml`
- `config/sync/field.storage.paragraph.field_question_type.yml`

Capture and display:

- `web/modules/custom/myeventlane_event_attendees/src/Service/EventAttendeeQuestionCaptureService.php`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php`
- `web/modules/custom/myeventlane_event_attendees/src/Controller/VendorAttendeeController.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/VendorAttendeePresentationService.php`
- `web/modules/custom/myeventlane_rsvp/src/Controller/VendorRsvpExportController.php`

### Highlights and More Options

Highlights:

- `web/modules/custom/myeventlane_schema/config/install/paragraphs.paragraphs_type.event_highlight.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.storage.node.field_event_highlights.yml`
- `web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_event_highlights.yml`
- `config/sync/field.storage.paragraph.field_highlight_icon.yml`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventHighlightHelper.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioDescriptionForm.php`
- `web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js`
- `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-studio.scss`

More options:

- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioDescriptionForm.php`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-studio.scss`

### Sales and Admin Reporting

Vendor and reporting services:

- `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/MetricsAggregator.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventTicketsController.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventOverviewController.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php`
- `web/modules/custom/myeventlane_reporting/src/Controller/EventInsightsController.php`
- `web/modules/custom/myeventlane_reporting/src/Controller/ChartDataController.php`
- `web/modules/custom/myeventlane_reporting/myeventlane_reporting.routing.yml`
- `web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php`
- `web/modules/custom/myeventlane_event/src/Service/EventStatsService.php`

Vendor access:

- `web/modules/custom/myeventlane_vendor/src/Controller/VendorConsoleBaseController.php`
- `web/modules/custom/myeventlane_vendor/src/Service/EventVendorAccessChecker.php`
- `web/modules/custom/myeventlane_tickets/src/Service/EventAccess.php`
- `web/modules/custom/myeventlane_tickets/src/Controller/EventTicketsController.php`

## Confirmed Working Areas

- Event Studio is the intended vendor event create/edit UX and has routes, controller, form, save service, Twig shell, JS, and library wiring.
- Legacy event wizard routes still exist, but `VendorLegacyWizardRedirectSubscriber` redirects normal vendors toward the unified Event Studio path.
- `field_event_type` has an explicit event-mode model with allowed values including RSVP, paid, both, and external.
- Paid multi-ticket architecture exists: multiple `mel_ticket_type` tiers are attached to an event and projected to multiple Commerce variations on a ticket product.
- Per-ticket capacity, price, sale start/end, visibility, group rules, and variation mapping exist on or around `mel_ticket_type`.
- Public paid checkout uses the unified booking route and `TicketSelectionForm`.
- Ticket capacity is enforced in more than one runtime layer: selection form, cart validation subscriber, and order placement subscriber.
- RSVP booking uses the same `/event/{node}/book` route, with `/event/{event}/rsvp` redirecting to it.
- RSVP donations already use Drupal Commerce through `RsvpDonationService` and `rsvp_donation` orders.
- Event-level attendee question paragraphs exist and are used by RSVP and paid checkout paths.
- Vendor attendee export can include custom answers from `event_attendee.extra_data`.
- Vendor ticket sales reporting exists through `TicketSalesService`, with ticket breakdown and event summary surfaces.
- Server-side vendor ownership/access checks exist in the canonical vendor controllers and ticket access service.

## Broken, Incomplete, or Risky Areas

### Event Creation Flow

Risk rating: **High**

- Event Studio and the legacy wizard both contain event mode, ticket, details, and publish logic. This increases the chance that staff-only, redirected, and vendor paths disagree.
- `EventWizardPublishValidator` is wired to the legacy publish form, not clearly to the main `EventStudioForm` save path. Publish required-field behavior may differ between Studio and wizard.
- `EventProductManager::syncProducts()` is referenced by legacy publish/tests, while Studio paid sync runs through `MelTicketTypeManager` and `TicketTierLifecycleService`.
- `EventStudioSaveService::applyTicketPayload()` is a critical area for `paid` versus `both`. Evidence from the audit indicates that direct `field_product_target` and `field_ticket_types` handling can diverge for hybrid paid+RSVP events.
- `EventDuplicateController` redirects duplicated events to the core node edit route instead of Event Studio, which is likely inconsistent with the vendor UX.

### Ticket Creation

Risk rating: **High**

- The multiple-ticket model is present and appropriate, but several ticket management surfaces exist: Event Studio, Event Studio step forms, EventTicketsWorkspaceForm, legacy wizard, and core entity forms.
- Capacity is custom-tier driven, not Commerce Stock driven. This is acceptable, but must be explicit in implementation and admin copy.
- Orphan Commerce variations are unpublished rather than deleted. This is likely correct for order history, but cleanup logic must not assume deletion.
- `TicketSelectionForm` attaches `myeventlane_theme/ticket_matrix`, but the theme library was not found in `myeventlane_theme.libraries.yml`. `myeventlane_commerce.libraries.yml` defines `ticket_matrix` assets, but the referenced module JS/CSS files were not found. This is likely a broken or dead asset reference.
- `OrderCompletedSubscriber` contains temporary debug logging and uses service locator calls inside helper methods. That is not aligned with the Drupal 11 dependency injection rule and increases PII/logging risk.

### RSVP Events With Donations

Risk rating: **High**

- Drupal Commerce is already the correct mechanism for RSVP donations because donation checkout, order item metadata, target event linkage, Stripe/checkout redirect, and thank-you redirect already exist.
- `RsvpPublicForm` contains a comment saying the submission manager creates `pending_payment` when donation is greater than zero, but `RsvpSubmissionManager` currently creates confirmed submissions. That is stale or incomplete design drift.
- `RsvpDonationConfirmationSubscriber` only changes RSVP status when it is not already confirmed. With the current manager behavior, the status transition is largely a no-op for new RSVP donations.
- `BookController::applyEventDonationConfigToRsvpForm()` appears unused. Donation UX is built in `RsvpPublicForm`.
- `RsvpDonationService` depends on vendor store and Stripe readiness. Failure paths need organiser-facing copy and logging that is clear without exposing sensitive payment data.

Recommended direction: keep Commerce as the donation payment path, but decide whether RSVP donations are confirmed immediately with optional payment follow-up or pending until checkout completion. Do not build a second RSVP/payment flow.

### Attendee Information Questions

Risk rating: **High**

- Current questions are event-level, stored as `field_attendee_questions` paragraph templates on the event. There is no confirmed reusable organiser question library entity.
- RSVP questions are not per-ticket and not per-attendee. The RSVP form captures one shared custom-answer block for the party.
- Paid checkout uses holder paragraphs in `TicketHolderParagraphPane`, which is a separate path from RSVP question capture.
- Supported question types in config include `textfield`, `textarea`, `select`, `checkboxes`, `radios`, `email`, and `tel`. The requested `date`, `phone`, and `checkbox` mapping needs exact product decisions against existing types before implementation. `tel` likely covers phone, but do not rename or add without config review.
- RSVP answers are stored in `event_attendee.extra_data`, not on `rsvp_submission`.
- `syncVendorMirrorForRsvp()` copies the same structured answers onto every mirrored attendee row and also places a nested `attendees` array in `extra_data`. Current presentation code can stringify arrays poorly in CSV/UI.
- `VendorRsvpExportController` exports basic RSVP columns only and does not include custom answers, guest list, or donation amount. `VendorAttendeeController` is the stronger export path for custom answers.
- Temporary debug logs in attendee-related checkout and presentation services risk PII leakage.

### Highlights Section

Risk rating: **Medium**

- Highlight storage exists as `event_highlight` paragraphs with text and icon storage.
- `EventHighlightHelper` loads allowed icon values from `paragraph.field_highlight_icon`, and `EventStudioForm` passes options/errors into the JS settings.
- Icons cannot be selected reliably if the Studio UI is only rendering the hidden `items_state` pattern or if allowed values are not available to the client-side control. `EventStudioDescriptionForm` is especially thinner than the main form and only provides the hidden pattern.
- Public display maps icon keys in `node--event--full.html.twig`, so the safest storage model is to keep a stable icon key plus a human label/text, not rendered SVG/HTML in field data.
- Paragraph access for `event_highlight` appears narrower than other vendor team access: author/admin is handled, but vendor team membership should be aligned with `EventVendorAccessChecker` before team editing is promised.

Recommended UI: a small predefined icon picker using the allowed icon key list, with label preview and no arbitrary uploaded icon in v2 unless product requires custom icons.

### More Options Section

Risk rating: **Medium**

- The More options section is currently template grouping in `mel-event-studio.html.twig`.
- It includes Discovery, Organizer contact, Policies & audience, and Accessibility fields.
- Ticket sale start/end fields are built in forms and rendered under Tickets, not inside More options.
- `EventStudioForm` and `EventStudioDescriptionForm` duplicate several field definitions. This makes help text and grouping likely to drift.

Recommended direction: keep important settings visible, add organiser-facing help text where fields are already built, and avoid hiding revenue- or access-affecting controls in a collapsed section without summary state.

### Ticket Sales and Admin

Risk rating: **High**

- `TicketSalesService` appears to be the correct Commerce-backed vendor sales source for completed-order metrics.
- `TicketTierAnalyticsService`, `TicketVariationSoldService`, `TicketSalesService`, `EventStatsService`, analytics controllers, and reporting controllers can compute overlapping ticket/revenue numbers.
- `ChartDataController::access()` is stricter than the canonical vendor access pattern because it allows user 1, `administer nodes`, or event owner, but does not clearly include `field_event_vendor` team members. This can deny cross-team vendor access even when other event insight pages allow it.
- Cross-vendor denial is present in canonical controllers, but should be regression-tested on all JSON/reporting/export routes, not only page routes.

### Duplicate or Dead Code

Risk rating: **Medium**

Evidence-backed candidates:

- Keep for now, document staff-only: legacy wizard forms and templates under `web/modules/custom/myeventlane_event/src/Form/EventWizard*.php` and `web/modules/custom/myeventlane_event/templates/event-wizard-*.html.twig`.
- Refactor: duplicate event/ticket/detail field logic between `EventStudioForm`, `EventStudioTicketsForm`, `EventStudioDescriptionForm`, and legacy wizard forms.
- Refactor: `EventProductManager::syncProducts()` versus Studio's ticket sync path. Decide one canonical publish/sync orchestration.
- Refactor or remove: `BookController::applyEventDonationConfigToRsvpForm()` if it remains unused.
- Delete if confirmed unreferenced: `vendor-studio.js.bak`.
- Delete if confirmed unreferenced: `vendor-event-form-page.js.bak`.
- Remove or demote before production: `TEMP_DEBUG` logging in attendee checkout and attendee presentation services.
- Investigate before deleting: `web/modules/custom/myeventlane_rsvp/src/Form/RsvpSubmissionForm.php`, `web/modules/custom/myeventlane_event_attendees/src/Form/RsvpForm.php`, and `web/modules/custom/myeventlane_rsvp/src/Controller/RsvpCancelController.php`; current evidence suggests they are not the canonical public RSVP path.

## Exact Implementation Recommendations

### 1. Declare Canonical Event Flow

Make Event Studio the canonical vendor flow for create, edit, draft, preview, publish, paid, RSVP, and both-mode events.

Likely files:

- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventWizardPublishValidator.php`
- `web/modules/custom/myeventlane_event/src/Controller/EventDuplicateController.php`
- `web/modules/custom/myeventlane_event_studio/src/EventSubscriber/VendorLegacyWizardRedirectSubscriber.php`

Actions:

- Move or reuse publish validation so Event Studio and any retained staff wizard share one checklist.
- Fix duplicated `paid` / `both` handling around ticket payload and product references.
- Redirect event duplicates back to Event Studio.
- Keep legacy wizard only if staff still needs it; otherwise schedule deletion in a separate task.

### 2. Keep `mel_ticket_type` as the Paid Ticket Source of Truth

Do not add another ticket type model. Use `mel_ticket_type` plus Commerce product variations.

Likely files:

- `web/modules/custom/mel_ticket/src/Entity/TicketType.php`
- `web/modules/custom/mel_ticket/src/Form/TicketTypeForm.php`
- `web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`
- `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`
- `web/modules/custom/myeventlane_vendor/src/Form/EventTicketsWorkspaceForm.php`
- `web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php`
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`

Actions:

- Ensure create/edit/delete/reorder all pass through `TicketTierLifecycleService`.
- Make per-ticket capacity, sale window, visibility, sold count, remaining, and revenue explicit in the vendor UI.
- Fix or remove the `ticket_matrix` library references.
- Preserve historical Commerce variations by unpublishing rather than deleting unless a migration plan says otherwise.

### 3. Use Commerce for RSVP Donations

Keep RSVP donations as Commerce orders and do not build a separate payment flow.

Likely files:

- `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php`
- `web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionManager.php`
- `web/modules/custom/myeventlane_donations/src/Service/RsvpDonationService.php`
- `web/modules/custom/myeventlane_donations/src/EventSubscriber/RsvpDonationCheckoutRedirectSubscriber.php`
- `web/modules/custom/myeventlane_rsvp/src/EventSubscriber/RsvpDonationConfirmationSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php`

Actions:

- Decide confirmed-immediately versus pending-payment RSVP donation semantics.
- Align comments, entity status writes, confirmation emails, and thank-you behavior to that decision.
- Remove unused donation helper code if it is not part of the selected path.
- Keep failure paths loud with safe logs and user-facing warnings.

### 4. Design Attendee Questions as a Shared Template Model

Use existing paragraph templates first, but add a reusable organiser library only if product confirms that event-copy reuse is insufficient.

Likely files:

- `web/modules/custom/myeventlane_vendor/src/Form/EventCheckoutQuestionsForm.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/EventAttendeeQuestionCaptureService.php`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php`
- `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/AttendanceManager.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/VendorAttendeePresentationService.php`
- `web/modules/custom/myeventlane_event_attendees/src/Controller/VendorAttendeeController.php`

Actions:

- Decide whether assignment is event-wide only in v2 or per-ticket as well.
- If per-ticket assignment is required, attach question templates to `mel_ticket_type` or a join entity, not ad hoc JSON.
- Normalize answer storage so RSVP and paid exports read the same shape.
- Remove nested raw `attendees` arrays from custom-answer output.
- Add privacy review for logs, exports, and access.

### 5. Fix Highlights Without Adding a Parallel Icon System

Keep `field_highlight_icon` as a stable key and render from a known allowed-value list.

Likely files:

- `web/modules/custom/myeventlane_event_studio/src/Service/EventHighlightHelper.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioDescriptionForm.php`
- `web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js`
- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig`
- `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`
- `web/modules/custom/myeventlane_event/myeventlane_event.module`

Actions:

- Ensure the icon picker has the allowed icon options in every reachable Studio form.
- Use icon key plus label/text storage.
- Align paragraph access with vendor team access if vendor team editing is supported.

### 6. Improve More Options Grouping and Help

Likely files:

- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioDescriptionForm.php`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-studio.scss`

Actions:

- Add concise organiser help text to existing fields.
- Keep critical settings visible or summarize them when collapsed.
- Extract shared form build helpers where repeated fields are drifting.

### 7. Consolidate Sales Metrics and Access

Likely files:

- `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php`
- `web/modules/custom/myeventlane_event/src/Service/EventStatsService.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketTierAnalyticsService.php`
- `web/modules/custom/myeventlane_reporting/src/Controller/ChartDataController.php`
- `web/modules/custom/myeventlane_reporting/src/Controller/EventInsightsController.php`
- `web/modules/custom/myeventlane_vendor/src/Service/EventVendorAccessChecker.php`
- `web/modules/custom/myeventlane_tickets/src/Service/EventAccess.php`

Actions:

- Declare `TicketSalesService` or `TicketTierAnalyticsService` as canonical for vendor-facing Commerce ticket metrics.
- Align chart JSON access with `EventVendorAccessChecker`.
- Test cross-vendor denial on pages, JSON endpoints, exports, and order/ticket routes.

## Exact Files Likely Needing Changes

Highest priority:

- `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/MelTicketTypeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventWizardPublishValidator.php`
- `web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`
- `web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`
- `web/modules/custom/myeventlane_vendor/src/Form/EventTicketsWorkspaceForm.php`
- `web/modules/custom/myeventlane_vendor/src/Ticketing/EventTicketsBuilder.php`
- `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- `web/modules/custom/myeventlane_commerce/src/Service/TicketAvailabilityService.php`
- `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php`
- `web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionManager.php`
- `web/modules/custom/myeventlane_donations/src/Service/RsvpDonationService.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/EventAttendeeQuestionCaptureService.php`
- `web/modules/custom/myeventlane_event_attendees/src/Service/VendorAttendeePresentationService.php`
- `web/modules/custom/myeventlane_reporting/src/Controller/ChartDataController.php`

Likely supporting files:

- `web/modules/custom/myeventlane_event/src/Controller/EventDuplicateController.php`
- `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventModeManager.php`
- `web/modules/custom/myeventlane_event/src/Service/EventCtaResolver.php`
- `web/modules/custom/myeventlane_commerce/src/Controller/BookController.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketAvailabilityCommerceSubscriber.php`
- `web/modules/custom/myeventlane_commerce/src/EventSubscriber/TicketCapacityOrderSubscriber.php`
- `web/modules/custom/myeventlane_event_studio/src/Service/EventHighlightHelper.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioDescriptionForm.php`
- `web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js`
- `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig`
- `web/modules/custom/myeventlane_event/myeventlane_event.module`
- `web/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-studio.scss`
- `web/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss`

Deletion candidates, after one more reference check:

- `vendor-studio.js.bak`
- `vendor-event-form-page.js.bak`

## Questions for Anna

These do not block writing this audit, but they block a correct implementation build:

1. Should `both` events mean paid tickets plus RSVP registration on the same public booking page, or should paid ticket purchase win and RSVP be a secondary/admin-only mode?
2. For RSVP donations, should an RSVP be confirmed immediately before donation checkout, or should donation RSVPs have a pending state until Commerce checkout completes?
3. Should attendee questions in v2 be event-wide only, per-ticket only, or support both event-wide and per-ticket assignment?
4. Is a reusable organiser question library required for v2, or is copying/editing event-level question templates acceptable for launch?
5. Should vendor team members in `field_event_vendor.field_vendor_users` be allowed to edit highlights and access all chart JSON endpoints, matching the broader vendor console access pattern?
6. Are `date` and single `checkbox` required as new attendee question field types, or can launch use the existing `textfield`, `textarea`, `select`, `checkboxes`, `radios`, `email`, and `tel` types?

## Proposed Cursor Implementation Task Sequence

1. **Event Studio canonical flow**
   - Align publish validation, duplicate-event redirect, and `paid` / `both` payload handling.
   - Stop after verification.

2. **Paid ticket tier workspace**
   - Consolidate create/edit/delete/reorder through `TicketTierLifecycleService`.
   - Add or expose per-ticket sold, remaining, revenue, capacity, sale window, and visibility.
   - Fix `ticket_matrix` asset/library issue.
   - Stop after verification.

3. **RSVP donation semantics**
   - Choose confirmed-immediately or pending-payment.
   - Align `RsvpPublicForm`, `RsvpSubmissionManager`, donation order creation, confirmation subscriber, and thank-you copy.
   - Remove unused donation helper if not used.
   - Stop after verification.

4. **Attendee questions**
   - Normalize RSVP and paid answer storage/output.
   - Add required/optional handling parity.
   - Add per-ticket assignment or reusable library only after Anna confirms product scope.
   - Remove PII-heavy temporary debug logging.
   - Stop after verification.

5. **Highlights and More options**
   - Fix icon picker availability and access.
   - Add organiser help text.
   - Refactor duplicated field builders only where needed for parity.
   - Stop after verification.

6. **Sales metrics and vendor access**
   - Declare canonical sales metric services per surface.
   - Align chart/export access with `EventVendorAccessChecker`.
   - Add cross-vendor denial tests.
   - Stop after verification.

7. **Dead code cleanup**
   - Remove confirmed backup files and stale unused helpers.
   - Keep legacy wizard only if staff usage is confirmed; otherwise retire in a separate task.
   - Stop after verification.

## Test Plan

### Static and Drupal Checks

- `composer validate`
- `ddev drush status`
- `ddev drush cr`
- `ddev drush updatedb:status`
- `ddev drush config:status`
- `ddev drush route:rebuild`

### PHPUnit or Kernel Tests

- Kernel test for Event Studio publish validation parity across RSVP, paid, both, and external events.
- Kernel test for `TicketTierLifecycleService` create/edit/delete/reorder and Commerce variation sync.
- Kernel test for `TicketAvailabilityService` capacity, sale start/end, visibility, price mismatch, and event total capacity.
- Kernel test for RSVP donation state transition using `RsvpSubmissionManager`, `RsvpDonationService`, and `RsvpDonationConfirmationSubscriber`.
- Kernel test for attendee answer normalization for RSVP and paid holder paragraphs.
- Kernel test for `ChartDataController` access using event owner, vendor team member, unrelated vendor, and admin.

### Manual Browser Smoke Tests

- Vendor creates RSVP event as draft, previews, publishes, edits, unpublishes or updates.
- Vendor creates paid event with multiple ticket types, sale windows, quantities, and visibility modes.
- Vendor edits ticket price/capacity/date after publish and verifies public checkout updates.
- Vendor deletes or archives a ticket type and verifies old orders remain visible.
- Vendor creates `both` event and verifies the expected public CTA behavior.
- Vendor creates event highlights and selects each supported icon.
- Vendor uses More options fields and sees clear help text.

### Commerce Checkout Smoke

- Anonymous buyer purchases one ticket type.
- Anonymous buyer purchases multiple ticket types.
- Sold-out ticket cannot be added to cart.
- Sale-not-started and sale-ended tickets cannot be purchased.
- Checkout creates attendee rows and ticket holder data.
- Order item `field_target_event` is populated.
- Vendor ticket sales count, revenue, and remaining capacity update after checkout completion.

### RSVP Donation Smoke

- Anonymous user RSVPs without donation.
- Anonymous user RSVPs with suggested donation.
- Anonymous user RSVPs with custom donation.
- Donation checkout redirects back to RSVP thank-you with order reference.
- Donation failure leaves an understandable user message and safe logs.
- Vendor RSVP and attendee views show the expected RSVP state and donation information according to the chosen product semantics.

### Cross-Vendor Security Smoke

- Vendor A cannot edit Vendor B event in Event Studio.
- Vendor A cannot manage Vendor B ticket types.
- Vendor A cannot view Vendor B orders, attendees, RSVP exports, chart JSON, or analytics pages.
- Vendor team member access matches Anna's product decision.
- Anonymous users cannot access vendor/admin routes or exports.

## Residual Risk

This audit did not execute the site or run browser checkout. Findings are based on repository inspection, route/config/code reads, and Composer package inspection. The highest residual risks are hybrid `both` behavior, donation RSVP status semantics, attendee PII in logs/exports, and access parity across JSON/export routes.
