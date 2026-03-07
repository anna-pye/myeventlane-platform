# Event Workspace Route Consolidation Report

Date: 2026-03-07

## Scope

This pass consolidates canonical event workspace URL ownership under `myeventlane_vendor` for:

- `/vendor/events/{event}`
- `/vendor/events/{event}/overview`
- `/vendor/events/{event}/tickets`
- `/vendor/events/{event}/attendees`
- `/vendor/events/{event}/orders`
- `/vendor/events/{event}/promotion`
- `/vendor/events/{event}/analytics`
- `/vendor/events/{event}/settings`
- `/vendor/events/{event}/publish`

## Route Ownership Table (`/vendor/events/{event}*`)

| Route name | Path | Owning module | Controller / form | Type |
|---|---|---|---|---|
| `myeventlane_vendor.console.event_workspace` | `/vendor/events/{event}` | `myeventlane_vendor` | `myeventlane_vendor.controller.event_workspace:workspace` | canonical |
| `myeventlane_vendor.console.event_overview` | `/vendor/events/{event}/overview` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_overview:overview` | canonical |
| `myeventlane_vendor.console.event_tickets` | `/vendor/events/{event}/tickets` | `myeventlane_vendor` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::overview` | canonical (delegated) |
| `myeventlane_vendor.console.event_attendees` | `/vendor/events/{event}/attendees` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_attendees:attendees` | canonical |
| `myeventlane_vendor.console.event_orders` | `/vendor/events/{event}/orders` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_orders:orders` | canonical |
| `myeventlane_vendor.console.event_promotion` | `/vendor/events/{event}/promotion` | `myeventlane_vendor` | `\Drupal\myeventlane_vendor_comms\Form\VendorEventCommsForm` | canonical (delegated) |
| `myeventlane_vendor.console.event_analytics` | `/vendor/events/{event}/analytics` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_analytics:analytics` | canonical |
| `myeventlane_vendor.console.event_settings` | `/vendor/events/{event}/settings` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_settings:settings` | canonical |
| `myeventlane_vendor.console.event_publish` | `/vendor/events/{event}/publish` | `myeventlane_vendor` | `myeventlane_vendor.controller.event_workspace:publish` | canonical |
| `myeventlane_vendor.console.boost_event_export` | `/vendor/events/{event}/boost/export` | `myeventlane_vendor` | `myeventlane_vendor.controller.boost_event_export:export` | feature-internal |
| `myeventlane_vendor.console.event_order_view` | `/vendor/events/{event}/orders/{order}` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_order_view:view` | feature-internal |
| `myeventlane_vendor.console.event_rsvps` | `/vendor/events/{event}/rsvps` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_rsvps:rsvps` | feature-internal |
| `myeventlane_vendor_comms.branding` | `/vendor/events/{event}/promotion/branding` | `myeventlane_vendor` | `\Drupal\myeventlane_messaging\Form\EventBrandOverrideForm` | feature-internal |
| `myeventlane_tickets.event_tickets_types` | `/vendor/events/{event}/tickets/types` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::typesList` | feature-internal |
| `myeventlane_tickets.event_tickets_settings` | `/vendor/events/{event}/tickets/settings` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::settings` | feature-internal |
| `myeventlane_tickets.event_tickets_groups` | `/vendor/events/{event}/tickets/groups` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\VendorEventTicketGroupsController::list` | feature-internal |
| `myeventlane_tickets.event_tickets_groups_add` | `/vendor/events/{event}/tickets/groups/add` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::groupsAdd` | feature-internal |
| `myeventlane_tickets.event_tickets_groups_edit` | `/vendor/events/{event}/tickets/groups/{mel_ticket_group}/edit` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::groupsEdit` | feature-internal |
| `entity.mel_ticket_group.delete_form` | `/vendor/events/{event}/tickets/groups/{mel_ticket_group}/delete` | `myeventlane_tickets` | `mel_ticket_group.delete` entity form | feature-internal |
| `myeventlane_tickets.event_tickets_access_codes` | `/vendor/events/{event}/tickets/access-codes` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\VendorEventAccessCodesController::list` | feature-internal |
| `myeventlane_tickets.event_tickets_access_codes_add` | `/vendor/events/{event}/tickets/access-codes/add` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::accessCodesAdd` | feature-internal |
| `myeventlane_tickets.event_tickets_access_codes_edit` | `/vendor/events/{event}/tickets/access-codes/{mel_access_code}/edit` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::accessCodesEdit` | feature-internal |
| `myeventlane_tickets.event_tickets_widgets` | `/vendor/events/{event}/tickets/widgets` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\VendorEventWidgetsController::list` | feature-internal |
| `myeventlane_tickets.event_tickets_widgets_add` | `/vendor/events/{event}/tickets/widgets/add` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::widgetsAdd` | feature-internal |
| `myeventlane_tickets.event_tickets_widgets_edit` | `/vendor/events/{event}/tickets/widgets/{mel_purchase_surface}/edit` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::widgetsEdit` | feature-internal |
| `entity.mel_purchase_surface.delete_form` | `/vendor/events/{event}/tickets/widgets/{mel_purchase_surface}/delete` | `myeventlane_tickets` | `mel_purchase_surface.delete` entity form | feature-internal |
| `myeventlane_tickets.add_ticket_modal` | `/vendor/events/{event}/tickets/types/add-modal` | `myeventlane_tickets` | `\Drupal\myeventlane_tickets\Controller\EventTicketsController::addTicketModal` | feature-internal |
| `myeventlane_boost.vendor_boost_wizard` | `/vendor/events/{event}/boost/wizard` | `myeventlane_boost` | `\Drupal\myeventlane_boost\Controller\WizardController::wizard` | feature-internal |
| `myeventlane_boost.wizard.step1` | `/vendor/events/{event}/boost/wizard/step-1` | `myeventlane_boost` | `\Drupal\myeventlane_boost\Controller\WizardController::step1` | feature-internal |
| `myeventlane_boost.wizard.step2` | `/vendor/events/{event}/boost/wizard/step-2` | `myeventlane_boost` | `\Drupal\myeventlane_boost\Controller\WizardController::step2` | feature-internal |
| `myeventlane_boost.wizard.step3` | `/vendor/events/{event}/boost/wizard/step-3` | `myeventlane_boost` | `\Drupal\myeventlane_boost\Controller\WizardController::step3` | feature-internal |
| `myeventlane_boost.wizard.step4` | `/vendor/events/{event}/boost/wizard/step-4` | `myeventlane_boost` | `\Drupal\myeventlane_boost\Controller\WizardController::step4` | feature-internal |
| `myeventlane_boost.wizard.step5` | `/vendor/events/{event}/boost/wizard/step-5` | `myeventlane_boost` | `\Drupal\myeventlane_boost\Controller\WizardController::step5` | feature-internal |
| `myeventlane_event.wizard.basics` | `/vendor/events/{event}/build/basics` | `myeventlane_event` | `\Drupal\myeventlane_event\Form\EventWizardBasicsForm` | feature-internal |
| `myeventlane_event.wizard.when_where` | `/vendor/events/{event}/build/when-where` | `myeventlane_event` | `\Drupal\myeventlane_event\Form\EventWizardWhenWhereForm` | feature-internal |
| `myeventlane_event.wizard.tickets` | `/vendor/events/{event}/build/tickets` | `myeventlane_event` | `\Drupal\myeventlane_event\Form\EventWizardTicketsForm` | feature-internal |
| `myeventlane_event.wizard.details` | `/vendor/events/{event}/build/details` | `myeventlane_event` | `\Drupal\myeventlane_event\Form\EventWizardDetailsForm` | feature-internal |
| `myeventlane_event.wizard.review` | `/vendor/events/{event}/build/review` | `myeventlane_event` | `\Drupal\myeventlane_event\Form\EventWizardReviewForm` | feature-internal |
| `myeventlane_event.wizard.publish` | `/vendor/events/{event}/build/publish` | `myeventlane_event` | `\Drupal\myeventlane_event\Form\EventWizardPublishForm` | feature-internal |
| `myeventlane_event.wizard.success` | `/vendor/events/{event}/build/success` | `myeventlane_event` | `\Drupal\myeventlane_event\Controller\VendorEventWizardController::success` | feature-internal |
| `myeventlane_reporting.event_insights.overview` | `/vendor/events/{event}/insights` | `myeventlane_reporting` | `myeventlane_reporting.event_insights:overview` | feature-internal |
| `myeventlane_reporting.event_insights.sales` | `/vendor/events/{event}/insights/sales` | `myeventlane_reporting` | `myeventlane_reporting.event_insights:sales` | feature-internal |
| `myeventlane_reporting.event_insights.attendees` | `/vendor/events/{event}/insights/attendees` | `myeventlane_reporting` | `myeventlane_reporting.event_insights:attendees` | feature-internal |
| `myeventlane_reporting.event_insights.checkins` | `/vendor/events/{event}/insights/checkins` | `myeventlane_reporting` | `myeventlane_reporting.event_insights:checkins` | feature-internal |
| `myeventlane_reporting.event_insights.traffic` | `/vendor/events/{event}/insights/traffic` | `myeventlane_reporting` | `myeventlane_reporting.event_insights:traffic` | feature-internal |
| `myeventlane_diagnostics.ajax` | `/vendor/events/{event}/diagnostics` | `myeventlane_diagnostics` | `\Drupal\myeventlane_diagnostics\Controller\DiagnosticsController::ajax` | feature-internal |
| `myeventlane_diagnostics.widget` | `/vendor/events/{event}/diagnostics/widget` | `myeventlane_diagnostics` | `\Drupal\myeventlane_diagnostics\Controller\DiagnosticsController::ajax` | feature-internal |

## Routes Removed

- `myeventlane_tickets.event_tickets_overview` (duplicate canonical owner for `/vendor/events/{event}/tickets`).

## Routes Retained

- All nine canonical workspace route paths are retained in `myeventlane_vendor` with normalized names:
  - `myeventlane_vendor.console.event_workspace`
  - `myeventlane_vendor.console.event_overview`
  - `myeventlane_vendor.console.event_tickets`
  - `myeventlane_vendor.console.event_attendees`
  - `myeventlane_vendor.console.event_orders`
  - `myeventlane_vendor.console.event_promotion`
  - `myeventlane_vendor.console.event_analytics`
  - `myeventlane_vendor.console.event_settings`
  - `myeventlane_vendor.console.event_publish`

## Routes Delegated

- `myeventlane_vendor.console.event_tickets` delegates to feature logic:
  - Controller: `\Drupal\myeventlane_tickets\Controller\EventTicketsController::overview`
  - Access callback: `myeventlane_tickets.access.event_tickets:access`
- `myeventlane_vendor.console.event_promotion` delegates to feature logic:
  - Form: `\Drupal\myeventlane_vendor_comms\Form\VendorEventCommsForm`
  - Access callback: `\Drupal\myeventlane_vendor_comms\Controller\VendorCommsController::checkAccess`

## Remaining Non-Canonical Feature Routes

Non-canonical feature routes remain under `/vendor/events/{event}/*` for:

- Tickets internals (`/tickets/types`, `/tickets/settings`, `/tickets/groups/*`, `/tickets/access-codes/*`, `/tickets/widgets/*`)
- Boost wizard (`/boost/wizard/*`)
- Event build wizard (`/build/*`)
- Event insights (`/insights/*`)
- Diagnostics (`/diagnostics*`)
- Promotion branding (`/promotion/branding`)

These are intentionally retained as feature-internal routes.

## Verification

Commands run:

- `ddev drush cr`
- `ddev drush core:route | rg "/vendor/events/"`

Result:

- Exactly one canonical owner exists per required canonical workspace path.
- Canonical paths resolve through `myeventlane_vendor` only.

## Risks Not Addressed

- Route-like references in generated artifacts/docs (for example `mel-routes.json`, `docs/phase-11-vendor-comms.md`) still mention old route names and may be stale documentation output.
- Existing `myeventlane_vendor_comms` form internals still use static `\Drupal::*` calls; not changed in this single-task routing pass.
