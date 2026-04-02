# Wizard Fixes - Autocomplete, Date/Time, File Upload

**Date:** 2025-01-27  
**Issues Fixed:** 3

> **2026-03:** References to `EventWizardForm.php` below are **historical**; that class was removed in favour of per-step wizard forms.

---

## ✅ Fix 1: Address Autocomplete Not Working

### Problem
Address autocomplete was not initializing in the vendor wizard because JavaScript selectors didn't include the new `.mel-vendor-wizard` class.

### Changes Made

1. **Updated `address-autocomplete.js`** to look for vendor wizard classes:
   - Added `.mel-vendor-wizard__card` and `.mel-vendor-wizard` to wizard content selectors
   - Added `form.mel-vendor-wizard` to form selectors

2. **Updated `event-wizard.js`** to re-initialize address autocomplete after AJAX:
   - Added `.mel-vendor-wizard` to form selectors
   - Added explicit call to `myeventlaneLocationAutocomplete` behavior after AJAX rebuilds
   - Added fallback to `initAddressAutocomplete()` if behavior not available

### Files Modified
- `web/modules/custom/myeventlane_location/js/address-autocomplete.js`
- `web/modules/custom/myeventlane_event/js/event-wizard.js`

### Testing
1. Navigate to wizard "When & Where" step
2. Type in "Search for address or venue" field
3. Verify autocomplete suggestions appear
4. Select an address
5. Verify address fields auto-populate

---

## ✅ Fix 2: Date/Time Section Display Issues

### Problem
The datetime widget was rendering date and time fields without proper labels, making it unclear which field was which.

### Changes Made

1. **Replaced single datetime widget with separate date and time fields:**
   - Start date: separate `date` and `time` inputs with clear labels
   - End date: separate `date` and `time` inputs with clear labels
   - Both wrapped in container with class `mel-datetime-wrapper`

2. **Updated save logic** to handle new format:
   - `saveWhenWhereData()` now combines date and time from separate fields
   - Format: `YYYY-MM-DDTHH:MM:00`

3. **Updated validation** to check separate fields:
   - Validates date and time separately
   - Clear error messages for each field

### Files Modified
- `web/modules/custom/myeventlane_event/src/Form/EventWizardWhenWhereForm.php` (step wizard; formerly `EventWizardForm.php`)

### Testing
1. Navigate to wizard "When & Where" step
2. Verify "Start date" and "Start time" fields are clearly labeled
3. Verify "End date" and "End time" fields are clearly labeled
4. Fill in dates and times
5. Continue to next step
6. Go back and verify values persist

---

## ✅ Fix 3: File Upload Size Limit Error

### Problem
Error message indicated 256MB limit, but validator was set to 5MB. The error suggests PHP configuration issue.

### Changes Made

1. **Increased file validator limit** from 5MB to 10MB:
   - More reasonable for hero images
   - Still prevents abuse

2. **Created documentation** for PHP configuration fixes:
   - `FILE_UPLOAD_FIX.md` with troubleshooting steps

### Files Modified
- `web/modules/custom/myeventlane_event/src/Form/EventWizardWhenWhereForm.php` (step wizard; formerly `EventWizardForm.php`)
- `web/modules/custom/myeventlane_event/FILE_UPLOAD_FIX.md` (new)

### Additional Steps Required

**Check PHP configuration:**
```bash
ddev exec php -i | grep -E "upload_max_filesize|post_max_size"
```

**If needed, update `.ddev/php/php.ini`:**
```ini
upload_max_filesize = 10M
post_max_size = 12M
```

**Restart DDEV:**
```bash
ddev restart
```

### Testing
1. Navigate to wizard "Branding" step
2. Upload a hero image (under 10MB)
3. Verify upload succeeds
4. If error persists, check PHP configuration per `FILE_UPLOAD_FIX.md`

---

## 🚀 Next Steps

1. **Clear cache:**
   ```bash
   ddev drush cr
   ```

2. **Test all three fixes:**
   - Address autocomplete in "When & Where" step
   - Date/time fields display correctly
   - File upload works (check PHP config if needed)

3. **If autocomplete still doesn't work:**
   - Check browser console for JavaScript errors
   - Verify `myeventlane_location` module is enabled
   - Verify location provider is configured
   - Check `drupalSettings.myeventlaneLocation` in browser console

---

## 📝 Notes

- All changes are backward compatible
- Date/time format change only affects wizard (not existing events)
- File upload limit is per-field, not global
- Address autocomplete requires location provider configuration

---

**Status:** ✅ **FIXES APPLIED**  
**Ready for:** Testing and verification
