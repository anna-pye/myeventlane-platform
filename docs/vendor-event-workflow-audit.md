# Vendor Event Workflow Audit (Temporary)

## Scope

This audit maps vendor event create/edit entrypoints and validates canonical wizard coverage without changing field storage.

## Route Map (Vendor Create/Edit Entry Points)

| Entry point | Source | Current target class | Flow type |
|---|---|---|---|
| `myeventlane_event.wizard.create` | `/vendor/events/create` | `EventWizardCreateController::createDraft` | Canonical wizard |
| `myeventlane_event.wizard.edit` | `/vendor/events/{node}/edit` | `EventWizardCreateController::edit` | Canonical wizard |
| `myeventlane_vendor.console.events_add` | `/vendor/events/add` | `VendorEventCreateController::buildForm` | Legacy route, now redirects to canonical wizard create |
| `myeventlane_vendor.manage_event.edit` | `/vendor/event/{event}/edit` | `ManageEventEditController::edit` | Legacy route, now redirects to canonical wizard edit |
| Vendor-domain `entity.node.add_form` for event | `/node/add/event` | `VendorDomainSubscriber` | Vendor users redirected to canonical wizard create |
| Vendor-domain `entity.node.edit_form` for event | `/node/{node}/edit` | `VendorDomainSubscriber` | Vendor owners redirected to canonical wizard edit |

### Intentional exception

- Admins with `administer nodes` keep raw node form access for advanced edit (no forced redirect in domain subscriber).

## Wizard Field Coverage Matrix

| Field | Wizard step/form mode | Vendor editable | Notes |
|---|---|---|---|
| `title` | Basics (`wizard_step_1`) | Yes | Required in wizard gating |
| `body` | Basics (`wizard_step_1`) | Yes | Main long description |
| `field_category` | Basics (`wizard_step_1`) | Yes | Required in wizard validation |
| `field_event_image` | Basics (`wizard_step_1`) | Yes | Custom managed file handling in `EventWizardBasicsForm` |
| `field_event_start` | When & Where (`wizard_step_2`) | Yes | Required in wizard gating |
| `field_event_end` | When & Where (`wizard_step_2`) | Yes | Optional |
| `field_location` | When & Where (`wizard_step_2`) | Yes | Required in wizard gating |
| `field_venue_name` | When & Where (`wizard_step_2`) | Mixed | Present in form mode, often hidden in vendor UX |
| `field_event_type` | Tickets (`wizard_step_4`) | Yes | Required for ticket path branching |
| `field_capacity` | Tickets (`wizard_step_4`) | Yes | Conditional by event type |
| `field_waitlist_capacity` | Tickets (`wizard_step_4`) | Yes | RSVP/Both flows |
| `field_attendee_questions` | Tickets + Details (`wizard_step_4`, `wizard_step_details`) | Yes | Editable in both form modes |
| `field_ticket_types` | Tickets (`wizard_step_4`) | Yes | Paid/Both flows |
| `field_product_target` | Tickets (`wizard_step_4`) | Yes | Paid/Both flows |
| `field_collect_per_ticket` | Tickets (`wizard_step_4`) | Yes | Paid/Both flows |
| `field_external_url` | Tickets (`wizard_step_4`) | Yes | External mode |
| `field_event_intro` | Details (`wizard_step_details`) | Yes | Intro text |
| `field_event_highlights` | Details (`wizard_step_details`) | Yes | Paragraph highlights |
| `field_refund_policy` | Details (`wizard_step_details`) | Yes | Policy selection |
| `field_age_restriction` | Details (`wizard_step_details`) | Yes | Age controls |
| `field_accessibility` | Details (`wizard_step_details`) | Yes | Drives conditional sub-fields |
| `field_accessibility_contact` | Details (`wizard_step_details`) | Yes | Conditional |
| `field_accessibility_directions` | Details (`wizard_step_details`) | Yes | Conditional |
| `field_accessibility_entry` | Details (`wizard_step_details`) | Yes | Conditional |
| `field_accessibility_parking` | Details (`wizard_step_details`) | Yes | Conditional |
| `field_event_vendor` | Hidden in wizard modes | No | System-managed |
| `field_event_store` | Hidden in wizard modes | No | System-managed |
| `moderation_state` | Publish step/controller | System | Workflow-managed |

## Raw Form Fields Missing in Canonical Wizard (Observed)

These appear in `node.event.default` but are hidden/not surfaced in canonical step forms:

- `field_event_summary`
- `field_featured`
- `field_promoted`
- `field_promo_expires`
- `field_reviews_enabled`
- `field_tags`
- `field_event_setup_complete`

No field storage changes were made in this pass. Any additions should be UI-only and mapped to existing fields.

## Temporary Diagnostics Added

Temporary logs were added to vendor create/edit entrypoints and wizard step loads with:

- route name
- event id
- form id
- canonical wizard flag

All log lines are prefixed with `TEMP diagnostics` and marked for removal.
