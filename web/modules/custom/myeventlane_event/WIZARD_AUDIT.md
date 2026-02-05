# Event Wizard — Full Functionality Audit

**Date:** 2025-02-02  
**Scope:** Phased wizard (EventWizardBasicsForm, EventWizardWhenWhereForm, etc.) at `/vendor/events/{event}/build/*`

---

## Reported Issues (Summary)

| # | Issue | Status |
|---|-------|--------|
| 1 | Event name (title) not being saved | **Investigate** |
| 2 | Image not saving; no alt text; image comes from Category term | **Investigate** |
| 3 | External URL not being saved | **Investigate** |
| 4 | Venue and address changes not saving | **Investigate** |
| 5 | Event is paid but no Ticket options showing | **Investigate** |
| 6 | Don't need URL alias (remove from wizard) | **Config** |
| 7 | Review page should show event card preview (like /event/node) | **Enhancement** |

---

## Architecture Overview

### Save Flow

1. Each step form calls `copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_X')`
2. `copyFormValuesToEvent` uses `EntityFormDisplay::extractFormValues()` — only fields **in the form display** are extracted
3. `$event->save()` persists the entity

### Form Display → Fields Mapped

| Step | Form Mode | Fields in Display |
|------|-----------|--------------------|
| Basics | wizard_step_1 | title, field_event_intro, field_category, field_event_image |
| When & Where | wizard_step_2 | field_event_start, field_event_end, field_location, field_venue_name |
| Tickets | wizard_step_4 | field_event_type, field_capacity, field_waitlist_capacity, field_external_url, field_collect_per_ticket, field_ticket_types, field_product_target |
| Details | wizard_step_details | field_event_highlights, field_refund_policy, field_age_policy, etc. |

---

## Issue Analysis

### 1. Event name (title) not being saved

**Expected:** `title` is in wizard_step_1 content; `extractFormValues` should extract it.

**Possible causes:**
- Node `title` is a base field; `getFieldDefinition('title')` may behave differently for base vs configurable fields
- Form structure: `title` might use `title[0][value]` — widget must match
- `EntityFormDisplay::getComponent('title')` — verify wizard_step_1 includes it (it does in config)

**Recommendation:** Add debug logging in `EventWizardBasicsForm::submitForm` to log `$form_state->getValue('title')` and `$event->label()` before/after `copyFormValuesToEvent`. If title is in form_state but not on entity after extract, the issue is in widget extraction.

---

### 2. Image not saving; no alt text; image from Category

**Current behaviour:**
- `field_event_image` is in wizard_step_1
- Category fallback: `myeventlane_theme_preprocess_node__event` uses `field_category_image` from the referenced term when `field_event_image` is empty
- Alt text: Image field has `alt` subfield; wizard uses `image_image` widget

**Possible causes:**
- **Not saving:** File upload widget may need `#parents` or form structure that `extractFormValues` expects; temporary file might not be promoted
- **No alt:** Alt is a subfield of the image field; ensure the widget exposes and saves it
- **Image from Category:** That's display logic (preprocess), not wizard save. If event has no image, category image is used. User may mean: (a) they want to *remove* the image field and always use category, or (b) they upload an image but it doesn't save

**Recommendation:** Confirm whether user wants (a) category-only image, or (b) event image to save when uploaded. For (b), verify file_managed temp files are promoted and `field_event_image` receives `target_id`.

---

### 3. External URL not being saved

**Current:** `field_external_url` is in wizard_step_4, visible via `#states` when event_type is paid/both/external.

**Possible causes:**
- **#states + extraction:** When `#states` hides elements with CSS, values can still submit. However, if the field is `#access = FALSE` or removed from DOM by JS, it won't submit
- **Link field structure:** `field_external_url` uses `link_default`; value shape is `[0 => ['uri' => ..., 'title' => ...]]`
- **Conditional visibility:** Ensure the field is in the form and visible when event_type is external — if user selects "external" but the field is hidden by wrong #states, they might not see it

**Recommendation:** Check `#states` selector: `:input[name="field_event_type[0][value]"]` — ensure it matches the actual form element name. Verify `field_external_url` value in `$form_state` after submit when event_type is external.

---

### 4. Venue and address not saving

**Current:** `field_location` and `field_venue_name` are in wizard_step_2. `myeventlane_location` adds address autocomplete and `_myeventlane_location_save_coordinates` submit handler.

**Possible causes:**
- **Submit order:** Wizard's `submitForm` runs first (via Form API), then `_myeventlane_location_save_coordinates`. Our submit copies form values and saves. The location handler saves lat/lng from hidden fields. The address itself should come from `field_location` via `extractFormValues`
- **Address widget:** `myeventlane_location_address_autocomplete` extends AddressDefaultWidget; may alter structure. `extractFormValues` for address field might expect standard address structure
- **Venue name:** `field_venue_name` is a separate field; should extract normally

**Recommendation:** Verify `field_location` and `field_venue_name` in `$form_state` at submit. Check if `extractFormValues` for the address widget correctly maps nested address components. Ensure `myeventlane_location` submit handler is attached to `event_wizard_when_where_form` (it is via `hook_form_FORM_ID_alter`).

---

### 5. Event is paid but no Ticket options

**Current:** `field_ticket_types` is visible when `field_event_type` is paid/both/external (via `#states`).

**Possible causes:**
- **#states not triggering:** If event_type is "paid" but the selector or value doesn't match, the field stays hidden
- **Default value:** On first load of Tickets step, `field_event_type` might be empty; user selects "paid" but #states runs on page load — fields may stay hidden until JS runs
- **Form build order:** `applyTicketConditionalLogic` runs after `buildForm`; #states should work. But if the select uses a different value format (e.g. `1` vs `paid`), the condition fails

**Recommendation:** Inspect `field_event_type` options in wizard_step_4 — ensure values are `rsvp`, `paid`, `both`, `external`. Verify #states selector matches. Add `#states` for `invisible` → `visible` so fields show when paid/both/external.

---

### 6. Don't need URL alias

**Current:** `path` is in wizard_step_1 `hidden` section. It should not be visible.

**Recommendation:** Confirm `path` is in `hidden` in all wizard form displays. If it still appears, a form_alter may be adding it — search for `path` or `revision_information` in wizard form alters.

---

### 7. Review page — event card preview

**Current:** Review page shows a text summary (basics, when/where, tickets, details). No visual card preview.

**Requested:** Show an event card preview matching `/event/{node}` (canonical event card / event listing card).

**Implementation:**
- Use existing view mode `event_card` or `event_card_compact` or `teaser`
- In `EventWizardReviewForm::buildForm`, add a `#card_preview` variable
- Render the event in that view mode: `$view_builder->view($event, 'event_card')` (or appropriate mode)
- Pass to template; add a section "Your event will look like this" with the rendered card

**Existing templates:** `node--event--event-card.html.twig`, `node--event--event-card-compact.html.twig`, `event-card.html.twig` (Views). Use the same view mode as event listing cards for consistency.

---

## Full Wizard Flow — Thought Check

### Step 1: Basics
- **Fields:** title, field_event_intro, field_category, field_event_image
- **Save:** extractFormValues → entity save
- **Risk:** Title (base field), image (file upload) — verify extraction

### Step 2: When & Where
- **Fields:** field_event_start, field_event_end, field_location, field_venue_name
- **Save:** extractFormValues + _myeventlane_location_save_coordinates (lat/lng)
- **Risk:** Address widget structure; coordinate submit handler order

### Step 3: (Skipped in current flow?)
- wizard_step_3 exists (Design: field_event_image, field_event_intro, field_event_highlights) but the STEPS constant goes basics → when_where → tickets → details → review → publish. So wizard_step_3 may be legacy/unused.

### Step 4: Tickets
- **Fields:** field_event_type, field_capacity, field_waitlist_capacity, field_external_url, field_collect_per_ticket, field_ticket_types, field_product_target
- **#states:** RSVP vs Paid visibility
- **Risk:** #states, external URL extraction, field_ticket_types (paragraphs)

### Step 5: Details
- **Fields:** field_event_highlights, field_refund_policy, field_age_policy, field_attendee_questions, field_accessibility, etc.
- **Save:** extractFormValues
- **Risk:** Paragraphs fields; accessibility sub-fields

### Step 6: Review
- Read-only summary; no save
- **Enhancement:** Add event card preview

### Step 7: Publish
- Publishes the event (status, etc.)

---

## Recommended Next Steps

1. **Debug logging:** Add temporary logging in each step's `submitForm` to capture `$form_state->getValues()` for the step's fields before `copyFormValuesToEvent`, and `$event->get($field)->getValue()` after.
2. **#states verification:** Confirm `field_event_type` values and #states selectors; test with browser DevTools.
3. **URL alias:** Ensure `path` is hidden in all wizard displays; add form_alter to hide `revision_information` if needed.
4. **Review card preview:** Implement event card preview using existing view mode.
5. **Image:** Clarify category vs event image; verify file upload and alt extraction.
