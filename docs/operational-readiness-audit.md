# Operational Readiness Audit

This audit records the existing readiness systems inspected before adding the lightweight operational readiness intelligence layer. The implementation reuses these sources and keeps business rules in their existing owners.

| Surface | Existing Readiness Logic | Reusable? | Gap |
|----------|--------------------------|------------|-----|
| `MelReadinessHelper` | Canonical vendor/customer readiness copy, action queue strings, dashboard empty strings, checkout trust lines, intelligence copy, attendee operations empty-state slots. | Yes | Needed a presentation-only operational summary builder so dashboard/workspace copy does not drift. |
| Organiser dashboard cards | `VendorDashboardViewModelBuilder` builds vendor profile, public profile, Stripe readiness, KPI cards, event rows, analytics availability, and action queue input. | Yes | Existing readiness was account setup focused; it did not summarize publishing, attendees, payouts, and promotion together. |
| Dashboard attention panels | `VendorActionQueueBuilder` prioritizes missing vendor profile, profile completion, missing booking setup, Stripe/payout readiness, no events, drafts, and analytics unavailability. | Yes | No change required; the new summary sits beside this queue and does not duplicate priority logic. |
| Event workspace | `VendorEventWorkspaceViewModelBuilder` derives event type from `EventStateResolver`, publication status, metrics, readiness items, next action, and presentation alerts. | Yes | Workspace needed a plain-language attendee-visible/public-visibility summary without recalculating state. |
| Event Studio readiness surfaces | `EventStudioForm` uses `VendorPublishRequirementsGate::getReadinessFlags()` to show terms/profile/Stripe publish readiness and existing JS/cards for publish readiness. | Yes | Publish-readiness copy was inline; it now flows through `MelReadinessHelper`. |
| Publishing gates | `VendorPublishRequirementsGate` blocks publishing for terms/profile/Stripe setup; `PaidPublishStripeGate` blocks paid publishing when Stripe charge readiness is missing. | Yes | No gap in enforcement; new guidance remains explanatory only. |
| Stripe readiness checks | Dashboard reads vendor-linked store fields; `PaidPublishStripeGate` validates account id and charges readiness with logging. | Yes | No Stripe logic changes; payout guidance only references existing readiness. |
| Ticket readiness checks | `EventStateResolver`, `VendorEventPresentationAlertsBuilder`, `TicketAvailabilityService`, `TicketTypeManager`, and `BookingFlowResolver` provide event type, mapping, product, and price display checks. | Yes | Summary needed to point organisers to the existing ticket setup status without adding Twig logic. |
| RSVP readiness checks | `EventStateResolver` exposes RSVP state; dashboard/workspace event type labels distinguish RSVP, paid, both, and unknown. | Yes | No new RSVP checks required. |
| Analytics cards | Dashboard KPI strip and analytics availability already use metrics and route access checks. | Yes | Summary can mention activity/readiness without querying analytics separately. |
| Check-in screens | `MelVenueOperationsViewModelBuilder` composes `MelAttendeeCheckinManager`, `MelAttendeeOperationsPresenter`, attendance rows, metrics, and governed empty states. | Yes | Empty row copy needed calmer readiness guidance from helper. |
| Payouts screens | Vendor dashboard and action queue already surface Stripe/payout readiness; Stripe/Connect routes remain access checked. | Yes | No payout flow changes. |
| Attendee screens | `MelAttendeeOperationsPresenter` is the canonical attendee operational view model and uses governed helper slots supplied by callers. | Yes | Existing empty states were reusable; check-in screen now references helper copy. |
| Event overview screens | Workspace and dashboard event rows expose status, type, metrics, and presentation issues. | Yes | New workspace summary provides attendee-visible meaning for those existing values. |
| Contextual help mappings | Help Assistant and Help Centre services exist separately, with retrieval and block/template surfaces. | Yes | No retrieval or prompt widening was needed. |
| Event workspace surfaces | Existing vendor theme workspace shell, live-ops cards, tabs, shortcuts, and metrics are reusable. | Yes | Added summary card using existing MEL live-ops classes. |

## Audited Files

- `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorActionQueueBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorEventWorkspaceViewModelBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorPublishRequirementsGate.php`
- `web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorEventPresentationAlertsBuilder.php`
- `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelAttendeeOperationsPresenter.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Service/MelVenueOperationsViewModelBuilder.php`
- `web/modules/custom/myeventlane_checkout_flow/src/Controller/VendorEventOperationsController.php`
- `web/modules/custom/myeventlane_checkout_flow/templates/mel-venue-operations.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig`
- `web/modules/custom/myeventlane_help_assistant/src/Service/HelpRetriever.php`
