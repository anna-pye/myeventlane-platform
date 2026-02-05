# Event wizard Twig templates – usage

**Current wizard flow:** Basics → When & Where → Tickets → Details → Review → Publish → Success.

---

## In use (invoked by code)

| Template | Theme hook | Used by |
|----------|------------|--------|
| **event-wizard-step-prefix.html.twig** | `myeventlane_event_wizard_step_prefix` | `EventWizardBaseForm::buildWizardPrefix()` – stepper nav + card header for Basics, When & Where, Tickets, Details, Publish |
| **event-wizard-step-suffix.html.twig** | `myeventlane_event_wizard_step_suffix` | `EventWizardBaseForm::buildWizardSuffix()` – closing layout divs |
| **event-wizard-review.html.twig** | `myeventlane_event_wizard_review` | `EventWizardReviewForm::buildForm()` – Review & Publish page |
| **event-wizard-success.html.twig** | `myeventlane_event_wizard_success` | `VendorEventWizardController::success()` – post-publish success screen |

---

## Unused (legacy, not invoked)

| Template | Theme hook | Reason |
|----------|------------|--------|
| **event-wizard-step.html.twig** | `myeventlane_event_wizard_step` | Step forms use prefix + form + suffix; no code calls this theme hook. |
| **event-wizard-tickets.html.twig** | `myeventlane_event_wizard_tickets` | Tickets step is `EventWizardTicketsForm` (wizard_step_4 form) with prefix/suffix; no code calls this theme hook. |

Content in the two unused templates does not appear in the current wizard. They are kept for reference; you can remove the theme hook entries in `myeventlane_event_theme()` and delete the files if you want to drop legacy code.
