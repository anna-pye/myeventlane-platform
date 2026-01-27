# Event Wizard Implementation Summary

**Date:** 2025-01-27  
**Status:** In Progress

---

## What Has Been Built

### 1. New EventWizardForm Class

**File:** `web/modules/custom/myeventlane_event/src/Form/EventWizardForm.php`

A comprehensive step-by-step wizard form with:

- ✅ **8 Steps:**
  1. Basics - Title, summary, event type, hero image
  2. Date & Time - Start/end dates
  3. Location - In-person vs online toggle with conditional fields
  4. Attendance & Pricing - Contextual fields based on event type
  5. Accessibility & Inclusion - Accessibility flags
  6. Policies - Refund policy (with default)
  7. Categories & Tags - Categories (required) and tags (optional)
  8. Review - Summary and preview

- ✅ **Progressive Saving:**
  - Saves entity after each step (as draft)
  - Prevents data loss on refresh/navigation
  - "Save draft" button available on all steps

- ✅ **Conditional Field Visibility:**
  - Location mode toggle (in-person vs online)
  - Attendance fields show/hide based on event type (RSVP/Paid/Both/External)
  - Uses `#states` API for client-side toggling

- ✅ **Validation:**
  - Step-specific validation
  - Date validation (end after start)
  - Location validation (address required for in-person, URL for online)
  - Required field validation

- ✅ **Default Values:**
  - Refund policy defaults to '7_days' if not set
  - Location mode defaults based on existing data

---

## What Still Needs to Be Done

### 1. Routing

**File:** `web/modules/custom/myeventlane_event/myeventlane_event.routing.yml`

Add route for wizard form:

```yaml
myeventlane_event.wizard_form:
  path: '/vendor/events/wizard/{node}'
  defaults:
    _form: '\Drupal\myeventlane_event\Form\EventWizardForm'
    _title: 'Create event'
  requirements:
    _custom_access: '\Drupal\myeventlane_vendor\Access\VendorConsoleAccess::access'
    node: '\d+'
  options:
    parameters:
      node:
        type: entity:node
        bundle:
          - event
```

### 2. Widget Type Refinement

The form currently uses basic form elements. Should be updated to use proper EntityFormDisplay widgets:

- `field_event_image` - Use `image_image` widget
- `field_accessibility` - Use `commerce_entity_select` widget  
- `field_category` - Use `options_select` widget
- `field_location` - Use `address_default` widget

**Option A:** Use EntityFormBuilder to get full form, then extract fields per step  
**Option B:** Manually build widgets using field widget plugins  
**Option C:** Keep basic elements for now, refine later

### 3. JavaScript Updates

**File:** `web/modules/custom/myeventlane_event/js/event-wizard.js`

May need updates for:
- Step navigation
- Focus management
- AJAX handling for location mode toggle

### 4. CSS/Styling

**File:** `web/modules/custom/myeventlane_event/css/event-wizard.css`

Need to ensure wizard matches MEL design system:
- Step navigation styling
- Form field styling
- Button styling
- Responsive layout

### 5. Review Step Preview

Currently shows link to event page. Should show:
- Embedded preview iframe, OR
- Summary of all collected data, OR
- Both

### 6. Integration with Existing System

Decide whether to:
- Replace existing `EventFormAlter` approach
- Use alongside existing wizard controller
- Migrate existing routes to new form

---

## Testing Checklist

### Manual Tests

- [ ] Create new RSVP event
- [ ] Create new Paid event
- [ ] Create new Both event
- [ ] Create new External event
- [ ] Test location mode toggle (in-person → online)
- [ ] Test location mode toggle (online → in-person)
- [ ] Test progressive saving (fill step 1, refresh, verify data saved)
- [ ] Test step navigation (Back/Next buttons)
- [ ] Test validation (try to proceed with invalid data)
- [ ] Test review step preview
- [ ] Test publish flow
- [ ] Test save draft flow
- [ ] Edit existing event via wizard

### Accessibility Tests

- [ ] Keyboard navigation works
- [ ] Screen reader announces step changes
- [ ] Focus management between steps
- [ ] Error messages are accessible
- [ ] Form labels are clear

### Browser Tests

- [ ] Chrome
- [ ] Firefox
- [ ] Safari
- [ ] Mobile browsers

---

## Known Issues

1. **Widget Types:** Form uses basic elements instead of proper field widgets
2. **Image Upload:** May need file upload handling
3. **Address Field:** Needs proper address widget integration
4. **Taxonomy Fields:** May need proper autocomplete/select widgets
5. **Review Preview:** Currently just a link, not embedded preview

---

## Next Steps

1. Add routing
2. Test basic flow
3. Refine widget types
4. Add CSS styling
5. Test all event types
6. Deploy to staging
7. User acceptance testing

---

## Files Modified/Created

### Created:
- `web/modules/custom/myeventlane_event/src/Form/EventWizardForm.php` (NEW)

### Modified:
- None yet (routing, services, etc. still needed)

### To Be Modified:
- `web/modules/custom/myeventlane_event/myeventlane_event.routing.yml`
- `web/modules/custom/myeventlane_event/js/event-wizard.js` (if needed)
- `web/modules/custom/myeventlane_event/css/event-wizard.css` (if needed)

---

**END OF IMPLEMENTATION SUMMARY**
