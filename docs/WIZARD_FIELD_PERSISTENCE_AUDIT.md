# Wizard Field Persistence Audit

**Module:** myeventlane_event  
**Scope:** Vendor Event Creation Wizard (all steps)  
**Date:** 2026-02-04  
**Task:** Read-only audit + minimal corrective patches for Form API structure, value extraction, and save logic risks.

---

## A) Audit Summary

| Metric | Count |
|--------|--------|
| **Wizard step forms reviewed** | 6 (Basics, When & Where, Tickets, Details, Review, Publish) |
| **Total wizard fields reviewed** | 20+ (all fields on wizard_step_1, wizard_step_2, wizard_step_4, wizard_step_details) |
| **Fields at risk (before fixes)** | 2 |
| **Fixes applied** | 1 (Tickets validation normalization); 1 already fixed (Basics field_event_image) |

**Risk categories:**

- **High:** Custom nested widgets read via array path without `#tree` → value lost on submit/redirect. **(field_event_image — FIXED in prior work.)**
- **Medium:** Validation reads `getValue('field_name')` while widget submits under `field_name_wrapper` → false validation errors and/or save path mismatch. **(field_event_type on Tickets step — FIXED.)**
- **Low:** EntityFormDisplay-built fields with defensive fallbacks in base form (When & Where, Details) → no change.

---

## B) Field-by-Field Findings

### 1. field_event_image (Basics) — FIXED (prior work)

- **Wizard step:** Basics (EventWizardBasicsForm).
- **File:** `EventWizardBasicsForm.php`.
- **Why it was at risk:** Custom fieldset wrapped managed_file + alt without `#tree`; submitted values were not nested under `field_event_image`, so `getValue(['field_event_image', 'upload'])` was empty and the field was cleared on save.
- **Evidence:** Root cause confirmed; fix applied: `#tree` => TRUE on fieldset, defensive fallbacks, Upload-button submit handler, no clear when fids missing.
- **Status:** No further change.

### 2. field_event_type (Tickets) — FIXED this audit

- **Wizard step:** Tickets (EventWizardTicketsForm).
- **File:** `EventWizardTicketsForm.php`, method `validateForm()`.
- **Why it was at risk:** Validation used `$form_state->getValue('field_event_type')` without running `normalizeFormStateForExtraction()`. Entity form display widgets typically submit under `field_name_wrapper`; `copyFormValuesToEvent()` runs normalization at submit time, but validation runs earlier. So validation could see an empty value even when the user had selected an event type, causing a false "Event type is required" error and/or inconsistent state.
- **Evidence:** When & Where already calls `normalizeFormStateForExtraction()` at the start of `validateForm()` (line 112–113) so that `getValue('field_event_start')` and `getValue('field_location')` see values. Tickets did not; only `copyFormValuesToEvent()` (and thus normalization) ran on submit.
- **Status:** Fixed by calling `normalizeFormStateForExtraction()` at the start of Tickets `validateForm()`.

### 3. field_event_start, field_event_end, field_venue_name, field_location (When & Where)

- **Wizard step:** When & Where (EventWizardWhenWhereForm).
- **File:** `EventWizardBaseForm.php` (`applyWhenWhereFromFormState()`, `applyLocationFromFormState()`); `EventWizardWhenWhereForm.php` (`validateForm()`).
- **Assessment:** **Safe.** When & Where runs `normalizeFormStateForExtraction()` before validation reads. Save path uses multiple fallbacks (direct path + `*_wrapper` paths) and only sets values when found; it never clears when value is structurally missing.

### 4. All Details step fields (wizard_step_details)

- **Wizard step:** Details (EventWizardDetailsForm).
- **File:** `EventWizardDetailsForm.php`; persistence via `EventWizardBaseForm::copyFormValuesToEvent()` only.
- **Assessment:** **Safe.** No custom getValue paths; all persistence via entity form display extraction + fallback loop. No custom fieldsets with nested values.

### 5. Review and Publish steps

- **Assessment:** **Safe.** Review is read-only (no persistable fields). Publish only sets `$event->setPublished(TRUE)` and saves; no form-field mapping.

### 6. copyFormValuesToEvent() and accidental clearing

- **File:** `EventWizardBaseForm.php`.
- **Assessment:** **Safe.** Fallback loop skips `field_event_image`. `applyWhenWhereFromFormState()` and `applyLocationFromFormState()` only set when a value is found; they do not clear when value is missing. Basics step no longer clears `field_event_image` when fids are missing (prior fix).

---

## C) Minimal Fixes

### Fix 1: EventWizardTicketsForm — normalize form state before validation (APPLIED)

**Why:** So that `getValue('field_event_type')` in validation sees the value that the widget submitted (e.g. under `field_event_type_wrapper`). Without this, validation can treat a filled-in value as empty and trigger "Event type is required."

**Form API terms:** Entity form display widgets often use `#parents = [field_name_wrapper]`; submitted values then live under `field_name_wrapper`. `normalizeFormStateForExtraction()` copies wrapper values into `field_name` so that both widget extraction and any direct `getValue('field_name')` see the same structure. Running it in validateForm aligns validation with the same paths used at submit.

**Patch (already applied):**

```php
// In EventWizardTicketsForm::validateForm(), after parent::validateForm():

// So validation sees values: widgets may submit under *_wrapper.
$form_display = EntityFormDisplay::collectRenderDisplay($this->getEvent(), 'wizard_step_4');
$this->normalizeFormStateForExtraction($form_display, $form_state);
```

---

## D) Verification Checklist

### URLs per wizard step

- Basics: `/vendor/events/{event_id}/build/basics`
- When & Where: `/vendor/events/{event_id}/build/when-where`
- Tickets: `/vendor/events/{event_id}/build/tickets`
- Details: `/vendor/events/{event_id}/build/details`
- Review: `/vendor/events/{event_id}/build/review`
- Publish: `/vendor/events/{event_id}/build/publish`

### Manual UI steps

1. **Basics:** Set title, category, upload image, alt text → Continue. Reopen Basics → image and alt still present. Go to Review → image appears in summary.
2. **When & Where:** Set start, end, venue, location → Continue. Reopen step → all values present.
3. **Tickets:** Select event type (e.g. RSVP or Paid) → Continue. Reopen step → event type still selected. Change type → Continue → value persists.
4. **Details:** Fill highlights, policies, accessibility → Continue. Reopen → values present.

### Drush validation commands

- **Event image (Basics):**  
  `ddev drush ev "echo \Drupal::entityTypeManager()->getStorage('node')->load(<event_id>)->get('field_event_image')->isEmpty() ? 'no image' : 'fid=' . \Drupal::entityTypeManager()->getStorage('node')->load(<event_id>)->get('field_event_image')->target_id;"`  
  Expect: `fid=<number>` after uploading and continuing.

- **Event type (Tickets):**  
  `ddev drush ev "echo \Drupal::entityTypeManager()->getStorage('node')->load(<event_id>)->get('field_event_type')->value ?? 'empty';"`  
  Expect: `rsvp`, `paid`, `both`, or `external` after selecting and continuing.

- **When & Where:**  
  `ddev drush ev "$n = \Drupal::entityTypeManager()->getStorage('node')->load(<event_id>); echo 'start=' . ($n->get('field_event_start')->value ?? '') . ' venue=' . ($n->get('field_venue_name')->value ?? '');"`  
  Expect non-empty after filling and continuing.

---

## E) Fields Confirmed Safe

| Field(s) | Step | Justification |
|----------|------|----------------|
| title, body, field_category | Basics | Built by EntityFormDisplay; no custom fieldset; extraction + fallback in copyFormValuesToEvent. |
| field_event_start, field_event_end, field_venue_name | When & Where | normalizeFormStateForExtraction() run before validation; applyWhenWhereFromFormState() uses multiple paths and does not clear on missing value. |
| field_location | When & Where | Same normalization; applyLocationFromFormState() only called when field empty, multiple paths, never clears. |
| field_capacity, field_waitlist_capacity, field_external_url, field_collect_per_ticket, field_ticket_types, field_product_target | Tickets | Built by EntityFormDisplay; persistence via copyFormValuesToEvent; no custom nested reads. |
| field_event_intro, field_event_highlights, field_refund_policy, field_age_policy, field_age_policy_note, field_attendee_questions, field_accessibility, field_accessibility_contact, field_accessibility_directions, field_accessibility_entry, field_accessibility_parking | Details | All via EntityFormDisplay and copyFormValuesToEvent; no custom getValue or clearing. |
| capacity_summary, capacity_warning | Tickets | Display-only (#markup / #type container); no form values. |
| Review step | Review | Read-only; no persistable form fields. |
| Publish step | Publish | Only sets published flag; no entity field mapping from form. |

---

## Theme / alter / JS

- **Theme and alter:** No hook_form_alter or theme preprocess was found that relies on flattened keys for wizard step forms in `myeventlane_event`. EventFormAlter and EventWizardForm operate on a different flow (create wizard); step forms are separate routes.
- **JavaScript:** event-wizard.js triggers `formUpdated` for stepper buttons; it does not change form names or submission structure. No evidence that JS alters value submission for the audited fields.

---

## Summary

- **field_event_image (Basics):** Already fixed with `#tree`, defensive reads, Upload-button persist, and no clear when fids missing.
- **field_event_type (Tickets):** Fixed by normalizing form state before validation so the event type value is visible to validation and consistent with save.
- **All other audited fields:** Rely on EntityFormDisplay + normalizeFormStateForExtraction (where used) + defensive fallbacks in the base form; no additional changes made. No refactors, no new services, no change to wizard flow.
