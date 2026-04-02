# Wizard Fixes - Final Round

**Date:** 2025-01-27  
**Issues Fixed:** 3

> **2026-03:** References to `EventWizardForm.php` below are **historical**; that class was removed in favour of per-step wizard forms.

---

## ✅ Fix 1: Time Fields Not Showing

### Problem
Start and end time fields were not visible - only date fields were showing.

### Changes Made

1. **Added inline styles** to ensure time fields are visible:
   - Added `style: 'display: block;'` to both start and end time fields
   - This ensures they render even if CSS is hiding them

2. **Created CSS file** (`wizard-fixes.css`) with explicit visibility rules:
   - `.mel-datetime-wrapper input[type="time"]` - ensures time inputs are visible
   - Added rules for form item wrappers

### Files Modified
- `web/modules/custom/myeventlane_event/src/Form/EventWizardWhenWhereForm.php` (and related step forms; formerly `EventWizardForm.php`)
- `web/modules/custom/myeventlane_event/css/wizard-fixes.css` (new)
- `web/modules/custom/myeventlane_event/myeventlane_event.libraries.yml`

### Testing
1. Navigate to wizard "When & Where" step
2. Verify "Start date" and "Start time" fields are both visible
3. Verify "End date" and "End time" fields are both visible
4. Fill in dates and times
5. Continue to next step and verify values save

---

## ✅ Fix 2: Location and Category Lookup Not Functioning

### Problem
Address autocomplete and category entity autocomplete were not working after AJAX rebuilds.

### Changes Made

1. **Enhanced autocomplete initialization** in `event-wizard.js`:
   - Now handles both regular autocomplete and entity autocomplete
   - Removes multiple once markers (`autocomplete`, `drupal.autocomplete`)
   - Calls `Drupal.attachBehaviors()` to ensure all behaviors are re-initialized
   - Better handling of entity autocomplete fields (category, tags)

2. **Updated address autocomplete selectors** to include vendor wizard classes:
   - Added `.mel-vendor-wizard` and `.mel-vendor-wizard__card` to selectors
   - Ensures search field is found after AJAX rebuilds

3. **Improved behavior attachment** after AJAX:
   - Explicitly calls `Drupal.attachBehaviors()` before autocomplete initialization
   - Ensures entity autocomplete behavior is attached

### Files Modified
- `web/modules/custom/myeventlane_event/js/event-wizard.js`
- `web/modules/custom/myeventlane_location/js/address-autocomplete.js`

### Testing
1. Navigate to wizard "Basics" step
2. Type in "Category" field - verify autocomplete suggestions appear
3. Navigate to "When & Where" step
4. Type in "Search for address or venue" field - verify autocomplete suggestions appear
5. Select an address - verify address fields auto-populate
6. Go back and forward between steps - verify autocomplete still works

---

## ✅ Fix 3: Remove Organization, First Name, Last Name from Address

### Problem
Address field was showing unnecessary fields (Organization, First name, Last name) that aren't needed for venue addresses.

### Changes Made

1. **Updated `processAddressElementHideFields()` method**:
   - Now hides: `organization`, `given_name`, `family_name`, `additional_name`
   - Uses `#access => FALSE` to completely remove from form
   - Hides fields in both direct element structure and widget structure

2. **Updated address field description**:
   - Changed title from "Full address" to "Address details"
   - Updated description to clarify fields are auto-filled

3. **Added CSS rules** to hide fields as fallback:
   - Multiple selectors to catch all variations
   - Ensures fields are hidden even if PHP hiding fails

### Files Modified
- `web/modules/custom/myeventlane_event/src/Form/EventWizardWhenWhereForm.php` (and related step forms; formerly `EventWizardForm.php`)
- `web/modules/custom/myeventlane_event/css/wizard-fixes.css`

### Testing
1. Navigate to wizard "When & Where" step
2. Select "In-person event"
3. Verify "Search for address or venue" field is visible
4. Verify address fields show only:
   - Street address (address_line1)
   - Address line 2 (optional)
   - Suburb (locality)
   - State (administrative_area)
   - Postcode (postal_code)
   - Country (country_code)
5. Verify Organization, First name, Last name fields are NOT visible
6. Use autocomplete to fill address - verify only relevant fields populate

---

## 🚀 Next Steps

1. **Clear cache:**
   ```bash
   ddev drush cr
   ```

2. **Test all three fixes:**
   - Time fields visible and functional
   - Category and address autocomplete working
   - Address fields simplified (no name/organization fields)

3. **If autocomplete still doesn't work:**
   - Check browser console for JavaScript errors
   - Verify `myeventlane_location` module is enabled
   - Verify location provider is configured in module settings
   - Check `drupalSettings.myeventlaneLocation` in browser console
   - Verify taxonomy terms exist for categories

4. **If time fields still hidden:**
   - Check browser developer tools to see if CSS is overriding
   - Verify `wizard_fixes` library is attached
   - Check for conflicting CSS rules

---

## 📝 Notes

- All address fields are now populated from the autocomplete search field
- Time fields use native HTML5 time inputs for better UX
- Entity autocomplete (category) should work after AJAX rebuilds
- Address autocomplete requires location provider configuration

---

**Status:** ✅ **ALL FIXES APPLIED**  
**Ready for:** Testing and verification
