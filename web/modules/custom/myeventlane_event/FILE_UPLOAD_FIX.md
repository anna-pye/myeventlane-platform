# File Upload Fix Notes

## Issue 1: Image upload not saving (Basics wizard step)
**Symptom:** User uploads an image on the Event wizard Basics step; it appears in the form (thumbnail, Remove button). After navigating away (clicking "Continue" or a sidebar step) and returning, the image is gone ("No file chosen").

**Root causes:**
1. **EntityFormDisplay image_image widget + form cache:** The standard widget conflicts with disableCache (fid lost on "Upload + Continue") and form cache + sanitizer caused regressions (alt field missing, still not saving).
2. **Fix (2025-02):** Use EventInformationForm pattern: custom managed_file + always-visible alt field, manual save in submitForm. Remove field_event_image from form display, add custom field_event_image container with upload + alt. `processImageUploadIfNeeded()` handles "Select file + Continue" (same request). disableCache avoids serialization issues.
3. **Sidebar navigation:** JS warns when leaving Basics with unsaved image.

**Fixes (2025-02):**
- `EventWizardBaseForm.php`:
  - `normalizeFormStateForExtraction()`: Copy `field_event_image` from `user_input` into `values` when missing, so widget extraction finds it.
  - `applyImageFromFormState()`: Fallback that reads image from form state (direct or wrapper paths), handles `fids` as string or array, and applies to the event.
  - `copyFormValuesToEvent()`: Calls `applyImageFromFormState()` for `wizard_step_1`.
- `event-wizard.js`: Warn when leaving Basics with an unsaved image (sidebar click or page refresh). Prompts: "You have an image that has not been saved. Click 'Continue' to save before switching steps."

---

## Issue 2: File size error
Error message: "An unrecoverable error occurred. The uploaded file likely exceeded the maximum file size (256 MB) that this server supports."

## Changes Made
1. **Increased file validator limit** from 5MB to 10MB in `EventWizardForm.php`
   - This is a reasonable limit for hero images
   - Location: `buildBrandingStep()` method

## Additional Steps Required

### 1. Check PHP Configuration
The error mentions 256MB, which suggests PHP's `upload_max_filesize` or `post_max_size` might be incorrectly configured.

**Check current PHP settings:**
```bash
ddev exec php -i | grep -E "upload_max_filesize|post_max_size"
```

**Expected values:**
- `upload_max_filesize` should be at least 10M (for hero images)
- `post_max_size` should be larger than `upload_max_filesize`

### 2. Update PHP Configuration (if needed)
If using DDEV, add to `.ddev/config.php`:
```php
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '12M');
```

Or update `.ddev/php/php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 12M
```

### 3. Check Drupal File System Settings
1. Navigate to `/admin/config/media/file-system`
2. Verify upload directory permissions
3. Check "Temporary directory" is writable

### 4. Restart DDEV
After making PHP configuration changes:
```bash
ddev restart
```

## Current Wizard Settings
- **File validator limit:** 10MB (10485760 bytes)
- **Allowed extensions:** png, gif, jpg, jpeg, webp
- **Upload location:** `public://event_images/`

## Testing
After fixes, test by:
1. Navigate to wizard Branding step
2. Try uploading a hero image (under 10MB)
3. Verify upload succeeds

If issues persist, check:
- Drupal watchdog logs: `ddev drush watchdog-show`
- PHP error logs: `ddev logs web`
- File system permissions: `ddev exec ls -la web/sites/default/files/`
