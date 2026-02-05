# Image Upload Deep Dive — Event Wizard Basics

## Summary

**Original state (working display):** The `image_image` widget from `wizard_step_1` form display loaded and displayed correctly — upload, preview, alt field, remove button.

**Problem:** Image was not persisting to the event entity after "Continue".

**What broke:** Fix attempts replaced or altered the widget, which caused the "image widget has disappeared" and other regressions.

---

## Timeline

| Phase | Change | Result |
|-------|--------|--------|
| **Original** | EntityFormDisplay with `field_event_image` (image_image), `disableCache()` | Widget displayed correctly. Image did not save (fids empty at submit). |
| **Attempt 1** | `applyImageFromFormState`, `normalizeFormStateForExtraction`, JS warning | fids still empty in logs. |
| **Attempt 2** | Removed `disableCache()`, enabled form cache, added `_myeventlane_event_sanitize_form_state_for_cache` | "age isn't. event loading properly now" — ambiguous; possible loading/serialization issues. |
| **Attempt 3** | Sanitize only form values, not storage | "still not loading and saving, Alt text field not showing". |
| **Attempt 4** | Reverted to `disableCache()`, added `processImageUploadIfNeeded()` in validateForm | "its simply not saving", "image widget has disappeared". |
| **Attempt 5** | `removeComponent('field_event_image')`, custom `field_event_image_widget` (managed_file + alt) | "image widget has disappeared" — familiar widget replaced by basic file input. |

---

## Root Cause

1. **disableCache()**  
   Form cache is disabled because of non-serializable objects in form state. With cache disabled, form state is not stored between requests.

2. **AJAX Upload + Continue flow**  
   If the user selects a file and clicks "Upload" (AJAX), the file is processed in that request and the fid is in form state. On the next request (Continue), the cached form is unavailable, so a fresh form is built. The fid from the previous request is never available in this new request.

3. **Single-request flow**  
   If the user selects a file and clicks "Continue" without using the Upload button, the file is in `$_FILES` in the same request. `ManagedFile::valueCallback` calls `file_managed_file_save_upload()` and should receive the fid in the same request and persist it correctly.

---

## Working Reference: EventInformationForm

`EventInformationForm` uses a **custom** `managed_file` element (`field_event_image_upload`) instead of the EntityFormDisplay image widget:

- Custom element at `form['basics']['field_event_image_upload']`
- No `disableCache()`
- Manual save in submitForm: reads fids, calls `setPermanent()`, `$event->set('field_event_image', ...)`

This pattern works because the form does not disable cache and handles the image manually.

---

## Minimal Fix (Recommended)

**Restore the original `image_image` widget** and adjust the Basics form to follow the EventInformationForm pattern where necessary.

### Step 1: Restore the widget

- Remove `removeComponent('field_event_image')`.
- Remove custom `field_event_image_widget` (managed_file, alt, preview).
- Let EntityFormDisplay build `field_event_image` again via `wizard_step_1`.

### Step 2: Keep `disableCache()` and fix the save path

Because `disableCache()` remains, the "Upload (AJAX) then Continue" flow will not persist the fid. Ensure the save path works for the single-request flow:

- User selects file and clicks "Continue" (no Upload).
- File is in `$_FILES`.
- `ManagedFile::valueCallback` processes the file and populates the fid in the same request.
- `copyFormValuesToEvent` → `applyImageFromFormState` or widget extraction should save it.

### Step 3: Update UI instructions

Add short helper text so users are directed to the working flow, e.g.:

> "Select an image and click Continue to save. Use the Upload button only if you need to change the file before continuing."

### Step 4: Verify value path

If the image still does not save, add temporary logging to inspect:

- `$form_state->getValue('field_event_image')` at submit
- `$form_state->getUserInput()['field_event_image']` at submit

The image_image widget structure is typically `['field_event_image', 0]` with `fids`, `alt`, `title`. `applyImageFromFormState` already checks several paths; confirm which one receives the fid in your setup.

---

## Files to Modify

1. **EventWizardBasicsForm.php**
   - Remove `$form_display->removeComponent('field_event_image')`.
   - Remove custom `field_event_image_widget` block (container, upload, alt, preview).
   - Restore section suffix so it targets `field_event_image` again.
   - Remove `saveEventImage()` and its call in `submitForm`.
   - Remove custom validation for `field_event_image_widget`.
   - Keep `disableCache()`.

2. **EventWizardBaseForm.php**
   - Keep `applyImageFromFormState` and `normalizeFormStateForExtraction` (they act as fallbacks when widget extraction fails).

3. **event-wizard.js**
   - Update unsaved-changes logic to use `field_event_image` (and its fids) instead of `field_event_image_widget`.

---

## Do Not Change

- `wizard_step_1` form display config (keep `field_event_image` with `image_image`).
- `disableCache()` — required to avoid serialization issues.
- `applyImageFromFormState` / `normalizeFormStateForExtraction` in EventWizardBaseForm.

---

## Test Flow

1. Go to Basics.
2. Select an image file.
3. Enter alt text (after the preview/alt field appears, if shown).
4. Click "Continue to When & When" (without clicking Upload).
5. Go to Review and confirm the image is present.
6. Return to Basics and confirm the image and alt text persist.

---

## Fix Applied (2025-02)

- Restored original `image_image` widget from wizard_step_1 (removed custom widget).
- Kept `processImageUploadIfNeeded()` in validateForm to handle "Select file + Continue" when the value callback does not persist the fid.
- Reverted `event-wizard.js` to check only `field_event_image` for unsaved changes.
