# Event Wizard Rendering Audit

**Project:** MyEventLane v2  
**Scope:** Event creation wizard (vendor flow: `/vendor/events/create` → steps → review → publish).  
**Mode:** Read-only audit + recommendations. No behaviour changes in this document.

---

## 1. Wizard flow summary

| Step        | Route                          | Form class                 | Form display mode     | Renders via                          |
|------------|----------------------------------|----------------------------|------------------------|--------------------------------------|
| Create     | `myeventlane_event.wizard.create` | Controller (redirect)      | —                      | Redirect to basics                   |
| Basics     | `myeventlane_event.wizard.basics` | EventWizardBasicsForm     | `wizard_step_1`        | Prefix + form + suffix               |
| When & Where | `myeventlane_event.wizard.when_where` | EventWizardWhenWhereForm | `wizard_step_2`        | Prefix + form + suffix               |
| Tickets    | `myeventlane_event.wizard.tickets`  | EventWizardTicketsForm  | `wizard_step_4`        | Prefix + form + suffix               |
| Details    | `myeventlane_event.wizard.details`  | EventWizardDetailsForm   | `wizard_step_details`  | Prefix + form + suffix               |
| Review     | `myeventlane_event.wizard.review`   | EventWizardReviewForm    | —                      | Full theme `myeventlane_event_wizard_review` |
| Publish    | `myeventlane_event.wizard.publish`  | EventWizardPublishForm   | —                      | Prefix + form + suffix               |

Form IDs: `event_wizard_basics_form`, `event_wizard_when_where_form`, `event_wizard_tickets_form`, `event_wizard_details_form`, `event_wizard_review_form`, `event_wizard_publish_form`.

---

## 2. Critical finding: wizard CSS/JS not loaded on step forms

**Issue:** The library `myeventlane_event/event_wizard` (CSS + JS for `.mel-event-wizard`, `.mel-wizard-steps`, stepper behaviour) is **only** attached by:

- `EventWizardForm` (form ID `event_wizard`) — legacy single-form wizard, not the current step flow.
- `EventFormAlter` — legacy node edit wizard when `myeventlane.enable_legacy_node_wizard` is TRUE.

**Current step forms do not attach the wizard library:**

- EventWizardBasicsForm  
- EventWizardWhenWhereForm  
- EventWizardTicketsForm  
- EventWizardDetailsForm  
- EventWizardPublishForm  

**Effect:** On Basics, When & Where, Tickets, Details, and Publish, the HTML from `event-wizard-step-prefix.html.twig` and `event-wizard-step-suffix.html.twig` is correct (`.mel-event-wizard`, `.mel-wizard-steps`, etc.), but **no wizard CSS or JS is loaded**. Layout and stepper styling/behaviour can appear broken or unstyled.

**Review step:** EventWizardReviewForm returns a render array with `#theme => 'myeventlane_event_wizard_review'` and does **not** set `#attached['library']`. So the review page also does not load the wizard library.

**Recommendation (high priority):**

1. In `myeventlane_event_form_alter()`, when `str_starts_with($form_id, 'event_wizard_')`, add:
   ```php
   $form['#attached']['library'][] = 'myeventlane_event/event_wizard';
   ```
   so all step forms (basics, when_where, tickets, details, publish) get the library in one place.

2. In `EventWizardReviewForm::buildForm()`, add to the returned render array:
   ```php
   '#attached' => [
     'library' => ['myeventlane_event/event_wizard'],
   ],
   ```
   so the review page uses the same wizard styles/layout.

---

## 3. Template usage

**Prefix/suffix (step forms):**

- `buildWizardPrefix()` renders theme hook `myeventlane_event_wizard_step_prefix` → `event-wizard-step-prefix.html.twig`.
- `buildWizardSuffix()` renders `myeventlane_event_wizard_step_suffix` → `event-wizard-step-suffix.html.twig`.
- The form’s direct children (fields + actions) are rendered by the form API **between** prefix and suffix. Structure is correct: prefix opens `.mel-event-wizard` → nav → `.mel-event-wizard__main` → `.mel-wizard-step-card` → `.mel-wizard-step-card__content`; form elements fill the content div; suffix closes the divs.

**Unused template:**

- `myeventlane_event_wizard_step` / `event-wizard-step.html.twig` is **never used**. Step forms do not wrap content in this theme hook; they use prefix + form + suffix. The template is dead code (can be removed or reserved for future use).

**Review step:**

- Uses `myeventlane_event_wizard_review` / `event-wizard-review.html.twig` with the same layout pattern (`.mel-event-wizard`, `.mel-wizard-steps`, `.mel-event-wizard__main`, `.mel-wizard-step-card`). Layout is consistent; only the library is missing (see §2).

**Vendor theme:**

- Vendor theme only has form suggestions for `myeventlane_event_wizard` (legacy form), not for `event_wizard_*_form`. So the current step forms use the default form rendering; no vendor overrides. Wizard markup comes from the module’s prefix/suffix templates.

---

## 4. Form display modes and repair

| Mode               | Config exists | Used by step        | Post-update repair |
|--------------------|---------------|---------------------|--------------------|
| wizard_step_1      | Yes           | Basics              | Yes                |
| wizard_step_2      | Yes           | When & Where        | Yes                |
| wizard_step_3      | Yes           | **Unused**          | No                 |
| wizard_step_4      | Yes           | Tickets             | Yes                |
| wizard_step_5      | Yes           | **Unused**          | No                 |
| wizard_step_details| Yes           | Details             | No (install/update)|

- Details step uses `wizard_step_details`; created/updated in `myeventlane_event_update_11011()` and `myeventlane_event_update_11013()`; not in `repair_event_wizard_form_displays` post_update. That’s acceptable if config/install and updates are the source of truth for Details.
- wizard_step_3 and wizard_step_5 are unused; safe to leave or remove from config later.

---

## 5. Step-specific copy in prefix template

In `event-wizard-step-prefix.html.twig`, title and description are step-specific for:

- `basics`, `when_where`, `tickets`, `publish`.

For **details** and **review** the template falls back to:

- Title: `{{ title }}` (form’s `#title`).
- Description: “Complete this step to continue.”

So Details and Review get generic copy. Optional improvement: add `details` and `review` branches with clearer, step-specific titles and descriptions (e.g. Details: “Policies, highlights, and accessibility”; Review: “Almost there! Review your event”).

---

## 6. Phase 3D debug logging (audit-only)

In `_myeventlane_event_details_step_descriptions()` two temporary `watchdog` notices were added for verification:

- “Phase 3D DEBUG: details step alter fired for form_id=@id”
- “Phase 3D DEBUG: details fields=@fields”

**Recommendation:** Remove these two log calls once Phase 3D verification is complete, to avoid log noise.

---

## 7. Summary of recommendations

| Priority | Action |
|----------|--------|
| **High** | Attach `myeventlane_event/event_wizard` for all wizard step forms in `hook_form_alter` when `str_starts_with($form_id, 'event_wizard_')`. |
| **High** | Attach `myeventlane_event/event_wizard` on the Review step render array in `EventWizardReviewForm::buildForm()`. |
| **Low**  | Add step-specific title/description for Details and Review in `event-wizard-step-prefix.html.twig`. |
| **Low**  | Remove Phase 3D DEBUG logging from `_myeventlane_event_details_step_descriptions()` after verification. |
| **Optional** | Remove or document unused `event-wizard-step.html.twig` and theme hook `myeventlane_event_wizard_step`. |

---

## 8. Verification checklist (after applying recommendations)

1. Load `/vendor/events/{id}/build/basics` (and other steps): confirm wizard CSS loads (e.g. `.mel-event-wizard` has layout, stepper is styled).
2. Confirm JS runs: e.g. stepper links or any wizard-specific behaviour work as expected.
3. Load Review: confirm same wizard library and layout.
4. Re-check Details step: descriptions and Event Information card behaviour as per Phase 3D.

---

**End of audit.** No code changes were made; recommendations only.
