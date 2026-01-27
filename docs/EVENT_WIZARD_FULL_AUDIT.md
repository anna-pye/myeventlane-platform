# Event Creation Wizard - Full Audit Report

**Date:** 2025-01-27  
**Architect:** Senior Drupal 11 Architect  
**Repository:** MyEventLane v2 (Drupal 11)

---

## EXECUTIVE SUMMARY

The Event Creation Wizard is a form alter-based wizard that reorganizes the standard Drupal node form into a multi-step interface for vendors. The current implementation has **critical data persistence issues**, **field visibility problems**, and **validation gaps** that prevent it from reliably producing complete, trustworthy event listings.

**Recommendation:** **REBUILD** the wizard with a proper step-by-step form class that saves data progressively and enforces validation at each step.

---

## PHASE 0 — FULL WIZARD AUDIT

### A) STRUCTURE & FLOW

#### Current Implementation

**File:** `web/modules/custom/myeventlane_event/src/Form/EventFormAlter.php`

**Architecture:**
- **Type:** Form alter service (not a standalone form class)
- **Hook:** `hook_form_node_event_form_alter()` via `myeventlane_event.module:234`
- **Service:** `myeventlane_event.event_form_alter` (registered in `myeventlane_event.services.yml`)
- **Activation:** Only in vendor context (checks route/domain)

**Step Definition:**
- Steps are **hardcoded** in `buildStepsFromForm()` (lines 426-512)
- Steps are **dynamically discovered** by checking which fields exist in the form
- Current steps:
  1. `basics` - Title, body, image, event type, category
  2. `schedule` - Start/end dates
  3. `location` - Address, venue, online URL
  4. `tickets` - Event mode, ticket types, capacity, external URL
  5. `design` - Theme, colors, accessibility, tags
  6. `questions` - Event questions, attendee questions
  7. `review` - Empty (no fields)

**Step Navigation:**
- **Linear:** Steps are sequential (Back/Next buttons)
- **Non-linear:** Users can click stepper buttons to jump (via AJAX `submitGoto()`)
- **State Management:** Uses `FormStateInterface::set()` with key `mel_wizard_step`
- **AJAX:** All step changes use AJAX rebuilds (wrapper: `mel-event-wizard-wrapper`)

**Critical Issues:**
1. ❌ **No progressive saving** - Data is only saved on final "Publish" or "Save draft" click
2. ❌ **No step validation** - Users can navigate between steps without validating current step
3. ❌ **Data loss risk** - If user refreshes or navigates away, unsaved data is lost
4. ❌ **Review step is empty** - No summary of collected data before publish

**Editing Existing Events:**
- ✅ Wizard supports editing (checks if entity is new via `$form_state->getFormObject()->getEntity()`)
- ⚠️ **Issue:** No distinction between "create" and "edit" flows - same wizard applies to both

---

### B) FIELD VISIBILITY & RELEVANCE

#### Step 1: Basics

**Fields Currently Shown:**
- `title` (required by Drupal)
- `body` (description)
- `field_event_image` (hero image)
- `field_event_type` (RSVP/Paid/Both/External)
- `field_category` (taxonomy reference)

**Issues:**
- ❌ **Missing:** `field_event_summary` (short tagline) - exists in schema but not in wizard
- ❌ **Too early:** `field_category` should be in final step (Categories & Tags)
- ⚠️ **Event type helper text:** No explanation of differences between RSVP/Paid/Both/External
- ⚠️ **Body field:** Full WYSIWYG editor may be overwhelming for first step

**Should Show:**
- Title (required)
- Short summary/tagline (optional)
- Event type (required, with helper text)
- Hero image (optional)

**Should NOT Show:**
- Body/description (move to later step)
- Category (move to Categories step)

---

#### Step 2: Schedule

**Fields Currently Shown:**
- `field_event_start` (datetime)
- `field_event_end` (datetime)
- Also checks for: `field_event_date`, `field_event_end_date`, `field_event_recurring` (not found in form)

**Issues:**
- ✅ **Correct fields** - Start and end dates are appropriate
- ⚠️ **Timezone handling:** No explicit timezone field - relies on system default
- ⚠️ **Validation:** End date must be after start date (validation exists in Drupal core datetime widget, but not enforced at wizard level)

**Should Show:**
- Start date & time (required)
- End date & time (required)
- Timezone (implicit or explicit)

---

#### Step 3: Location

**Fields Currently Shown:**
- `field_event_location_mode` (not found in form - field doesn't exist)
- `field_event_location` (not found)
- `field_location` (address field - exists)
- `field_event_online_url` (not found)
- `field_venue_name` (hidden via `hideAdminFields()`)

**Issues:**
- ❌ **Missing toggle:** No in-person vs online toggle
- ❌ **Field name mismatch:** Wizard looks for `field_event_location_mode` but actual field is `field_location`
- ❌ **Online URL:** No field for online-only events (should be `field_external_url` or new field)
- ⚠️ **Address field:** Full address widget may be complex for first-time users

**Should Show:**
- Toggle: In-person / Online (required)
- If in-person:
  - Venue name (optional)
  - Full address (required)
- If online:
  - Online URL (required)

**Current State:**
- Address field exists but no mode toggle
- No way to specify online-only events in location step

---

#### Step 4: Tickets

**Fields Currently Shown:**
- `field_event_mode` (not found - doesn't exist)
- `field_event_ticket_types` (not found)
- `field_ticket_types` (paragraphs - exists)
- `field_event_capacity` (not found)
- `field_capacity` (number - exists)
- `field_waitlist_capacity` (number - exists)
- `field_event_external_url` (not found)
- `field_external_url` (link - exists)
- `field_product_target` (entity reference - exists)
- `field_collect_per_ticket` (boolean - exists)

**Issues:**
- ❌ **Field name confusion:** Many fields have wrong names in step definition
- ❌ **No branching:** All ticket-related fields shown regardless of event type
- ❌ **Missing logic:** Should show different fields based on `field_event_type`:
  - RSVP: Capacity, waitlist capacity
  - Paid: Ticket types, product target
  - Both: All of the above
  - External: External URL only
- ⚠️ **Complexity:** Ticket types paragraph widget is complex and may confuse users

**Should Show (by event type):**

**RSVP:**
- Enable RSVP (implicit from event type)
- Capacity (optional, unlimited if empty)
- Waitlist capacity (optional)

**Paid/Ticketed:**
- Ticket types (paragraphs - required)
- Product target (auto-linked or manual)
- Capacity enforced via tickets

**Both:**
- All RSVP fields
- All Paid fields

**External:**
- External URL (required)
- No internal CTA

---

#### Step 5: Design

**Fields Currently Shown:**
- `field_event_theme` (not found - doesn't exist)
- `field_event_primary_color` (not found)
- `field_event_secondary_color` (not found)
- `field_accessibility` (taxonomy reference - exists)
- `field_tags` (taxonomy reference - exists, but hidden in form display)

**Issues:**
- ❌ **Theme/color fields don't exist** - Wizard looks for non-existent fields
- ⚠️ **Accessibility:** Should be in dedicated "Accessibility & Inclusion" step
- ⚠️ **Tags:** Should be in "Categories & Tags" step
- ❌ **Missing:** Refund policy field (`field_refund_policy` exists but not in wizard)

**Should Show:**
- Accessibility & inclusion statement (required, with default)
- Optional accessibility flags (taxonomy terms)

---

#### Step 6: Questions

**Fields Currently Shown:**
- `field_event_questions` (not found - doesn't exist)
- `field_attendee_questions` (paragraphs - exists)

**Issues:**
- ✅ **Correct field** - `field_attendee_questions` exists and is appropriate
- ⚠️ **Optional step:** Questions are optional, but step is always shown

**Should Show:**
- Attendee questions (paragraphs, optional)

---

#### Step 7: Review

**Fields Currently Shown:**
- None (empty step)

**Issues:**
- ❌ **No summary** - Review step shows nothing
- ❌ **No preview** - Can't see how event will appear
- ❌ **No warnings** - Doesn't highlight missing recommended fields

**Should Show:**
- Read-only summary of all collected data
- Preview of CTA state
- Warnings for missing optional but recommended fields
- Publish/Save draft buttons

---

### C) DATA PERSISTENCE (CRITICAL)

#### Current Save Behavior

**File:** `web/modules/custom/myeventlane_event/src/Form/EventFormAlter.php`

**Save Triggers:**
1. **"Publish event" button** (lines 823-884):
   - Calls `submitPublish()` → sets entity published
   - Calls entity form's `save()` → saves entity
   - Calls `submitPublishPostSave()` → syncs products
2. **"Save draft" button** (lines 886-937):
   - Calls `submitSaveDraft()` → sets entity unpublished
   - Calls entity form's `save()` → saves entity
3. **Step navigation** (Back/Next/Goto):
   - ❌ **NO SAVE** - Only rebuilds form via AJAX
   - Data remains in form state only

**Critical Issues:**

1. ❌ **No progressive saving**
   - Data is **only saved** when user clicks "Publish" or "Save draft"
   - If user fills 6 steps then refreshes page, **all data is lost**
   - If user navigates away, **all data is lost**
   - If browser crashes, **all data is lost**

2. ❌ **Form state only**
   - Data exists only in `FormStateInterface` until final submit
   - Entity is not saved until final button click
   - No draft entity exists until user explicitly saves

3. ⚠️ **Draft creation**
   - Controller (`VendorEventCreateController`) has `getOrCreateDraftEvent()` method
   - This creates an unpublished entity on first load
   - But if user never clicks "Save draft", entity may not be created
   - If entity is created but user changes steps, changes aren't saved

**Evidence:**
```php
// EventFormAlter.php:996-1008
public static function submitNext(array &$form, FormStateInterface $form_state): void {
  // ... changes step ...
  $form_state->setRebuild();  // ❌ NO SAVE - just rebuilds form
}

// EventFormAlter.php:1074-1085
public static function submitSaveDraft(array &$form, FormStateInterface $form_state): void {
  $entity = $form_state->getFormObject()->getEntity();
  $entity->setUnpublished();
  $form_state->setRebuild();  // ⚠️ Rebuilds instead of redirecting
  // Entity save happens in entity form's save() handler
}
```

**Required Behavior:**
- ✅ Save entity after each step (as draft)
- ✅ Load existing draft on page load
- ✅ Prevent data loss on refresh/navigation
- ✅ Show "unsaved changes" warning if user tries to leave

---

### D) VALIDATION & LOGIC

#### Current Validation

**Step-level Validation:**
- ⚠️ **Limited validation** - Only validates fields in current step when clicking "Next"
- Uses `#limit_validation_errors` to restrict validation to current step (line 805)
- ❌ **No cross-step validation** - Can't validate that event type matches ticket configuration

**Missing Validation:**

1. ❌ **Event type + ticket configuration mismatch**
   - Can select "RSVP" but configure paid tickets
   - Can select "Paid" but not link product
   - Can select "External" but not provide URL

2. ❌ **Date validation**
   - End date must be after start date (core datetime widget may handle this, but not enforced at wizard level)
   - No validation that event is in the future (for new events)

3. ❌ **Location validation**
   - Can leave address empty for in-person event
   - Can leave online URL empty for online event
   - No validation that location mode matches address/URL

4. ❌ **Required fields**
   - Title is required (Drupal core)
   - Event type is required (field config)
   - But other required fields (dates, location) may not be validated until final submit

5. ❌ **Capacity validation**
   - Can set capacity to 0 or negative
   - Can set waitlist capacity greater than main capacity

**Logic Issues:**

1. ❌ **Logic in wrong place**
   - Field visibility controlled by CSS classes (`.is-active`, `.is-hidden`)
   - Should be controlled by PHP `#access` based on event type
   - JavaScript handles step navigation, but validation is server-side only

2. ❌ **Duplicated logic**
   - Event type checking happens in multiple places
   - CTA logic in `EventModeManager` but wizard doesn't use it

3. ⚠️ **Conditional fields**
   - No `#states` API usage for conditional field visibility
   - All fields always in form, just hidden via CSS

---

### E) UX, LANGUAGE & ACCESSIBILITY

#### Language Audit

**Current Labels:**
- Step labels: "Basics", "Schedule", "Location", "Tickets", "Design", "Questions", "Review"
- Button labels: "Back", "Next", "Finish", "Publish event", "Save draft"

**Issues:**
- ⚠️ **"Basics" is vague** - Doesn't explain what's being collected
- ⚠️ **"Design" is misleading** - Contains accessibility and tags, not just design
- ⚠️ **"Tickets" is too narrow** - Step also handles RSVP and external events
- ❌ **No helper text** - Fields lack explanations of why they're needed
- ❌ **No gender-neutral language check** - Need to audit all user-facing strings

**Accessibility Issues:**

1. ❌ **Focus management**
   - JavaScript focuses step title after AJAX (line 45-52 of `event-wizard.js`)
   - But focus may not be announced to screen readers properly
   - No `aria-live` regions for step changes

2. ⚠️ **Keyboard navigation**
   - Stepper buttons are `<button>` elements (good)
   - But navigation via stepper may skip validation
   - No keyboard shortcuts documented

3. ❌ **Screen reader labels**
   - Step titles are `<h2>` (good)
   - But step numbers are visual only (not announced)
   - No `aria-current="step"` on active step

4. ⚠️ **Error messaging**
   - Errors appear via Drupal's standard form error system
   - But errors may not be clearly associated with steps
   - No summary of all errors at top of form

**Required Improvements:**
- ✅ Gender-neutral language throughout
- ✅ Clear helper text for each field
- ✅ Plain-language labels (not technical)
- ✅ Required fields clearly indicated
- ✅ Readable, non-punitive error messages
- ✅ Keyboard navigation works
- ✅ Focus management between steps
- ✅ Screen reader labels make sense
- ✅ ARIA attributes for step navigation

---

### F) MEL DESIGN CONSISTENCY

#### Visual Consistency

**Current Implementation:**
- Uses vendor theme classes: `mel-event-form`, `mel-event-form--wizard`
- Stepper uses: `mel-event-form__step`, `mel-event-form__step-number`
- Buttons use: `button`, `button--primary` (Drupal core classes)

**Issues:**
- ⚠️ **Mixed class systems** - Vendor theme classes + Drupal core classes
- ⚠️ **No MEL frontend consistency** - Wizard doesn't match public-facing event pages
- ❌ **Admin-looking UI** - Wizard feels like Drupal admin, not part of MEL

**Required:**
- ✅ Match MEL frontend design system
- ✅ Use MEL button styles
- ✅ Use MEL spacing/typography
- ✅ Feel like "part of MEL", not Drupal admin

---

## CRITICAL ISSUES SUMMARY

### 🔴 CRITICAL (Must Fix)

1. **No Progressive Saving**
   - Data lost on refresh/navigation
   - No draft entity until explicit save
   - **Impact:** High - users lose work

2. **Missing Required Fields in Wizard**
   - `field_refund_policy` exists but not collected
   - `field_event_summary` exists but not collected
   - **Impact:** High - incomplete events published

3. **No Step Validation**
   - Can progress with invalid data
   - Can create invalid event type + ticket combinations
   - **Impact:** High - invalid events published

4. **Review Step is Empty**
   - No summary before publish
   - No preview of final event
   - **Impact:** Medium - users can't verify before publishing

### 🟡 HIGH PRIORITY (Should Fix)

5. **Field Visibility Not Conditional**
   - All fields always in form (hidden via CSS)
   - Should use `#access` based on event type
   - **Impact:** Medium - confusing UX

6. **Wrong Field Names in Step Definitions**
   - Many fields have incorrect names
   - Some fields don't exist
   - **Impact:** Medium - fields not appearing in correct steps

7. **No Location Mode Toggle**
   - Can't specify online vs in-person
   - **Impact:** Medium - can't create online-only events properly

8. **Missing Helper Text**
   - Fields lack explanations
   - Event type differences not explained
   - **Impact:** Medium - user confusion

### 🟢 NICE TO HAVE (Improvements)

9. **Accessibility Improvements**
   - Better focus management
   - ARIA attributes
   - Screen reader announcements

10. **Design Consistency**
    - Match MEL frontend
    - Better visual hierarchy

11. **Better Error Messaging**
    - Step-specific errors
    - Error summary

---

## RECOMMENDATIONS

### Option 1: REFACTOR (Incremental Fixes)

**Pros:**
- Lower risk
- Faster to implement
- Preserves existing architecture

**Cons:**
- Technical debt remains
- Form alter approach is fragile
- Hard to add progressive saving

**Changes Required:**
1. Add progressive save on each step
2. Fix field names in step definitions
3. Add conditional field visibility
4. Add location mode toggle
5. Populate review step
6. Add validation

**Estimated Time:** 2-3 days

### Option 2: REBUILD (Recommended)

**Pros:**
- Clean architecture
- Proper step-by-step form class
- Progressive saving built-in
- Better validation
- Easier to maintain

**Cons:**
- Higher risk
- More time to implement
- Requires testing all flows

**Changes Required:**
1. Create new `EventWizardForm` class extending `FormBase`
2. Implement proper step management
3. Add progressive saving
4. Add conditional field visibility
5. Add comprehensive validation
6. Match MEL design system

**Estimated Time:** 5-7 days

---

## BLOCKING QUESTIONS

Before proceeding with implementation, please confirm:

1. **Progressive Saving:**
   - Should wizard save after each step automatically?
   - Or only when user clicks "Save draft"?
   - Should there be auto-save (debounced)?

2. **Event Type Values:**
   - Current values: `rsvp`, `paid`, `both`, `external`
   - Should "Free" be separate from "RSVP"?
   - Is "both" (RSVP + Paid) still supported?

3. **Location Mode:**
   - Should there be a dedicated "online" location mode?
   - Or use `field_external_url` for online events?
   - Can events be both in-person AND online?

4. **Required vs Optional:**
   - Which fields are truly required?
   - Should accessibility statement have a default?
   - Should refund policy have a default?

5. **Review Step:**
   - Should review show a preview of the event page?
   - Or just a summary of collected data?
   - Should warnings be shown for missing optional fields?

---

## NEXT STEPS

1. **Answer blocking questions** (above)
2. **Decide: Refactor vs Rebuild**
3. **Create implementation plan**
4. **Implement step-by-step**
5. **Test thoroughly**
6. **Deploy**

---

## APPENDIX: EVENT PAGE REQUIREMENTS

Based on `node--event--full.html.twig`, the Event page requires:

### Required Fields:
- ✅ `title` - Event title
- ✅ `field_event_image` - Hero image
- ✅ `field_event_type` - Event type (RSVP/Paid/Both/External)
- ✅ `field_event_start` - Start date/time
- ✅ `field_event_end` - End date/time
- ✅ `field_location` - Address (if in-person)
- ✅ `field_external_url` - External URL (if external)
- ✅ `field_category` - Categories
- ✅ `field_accessibility` - Accessibility flags
- ✅ `field_refund_policy` - Refund policy (exists, must have default if empty)

### Optional but Recommended:
- `body` - Description
- `field_event_summary` - Short tagline
- `field_tags` - Tags/keywords
- `field_venue_name` - Venue name
- `field_capacity` - Capacity
- `field_waitlist_capacity` - Waitlist capacity

### Derived/Computed:
- Price summary (from ticket types)
- Capacity/availability (from capacity + attendees)
- CTA state (from event type + availability)
- Organiser info (from vendor profile)

---

**END OF AUDIT**
