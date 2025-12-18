# Event Wizard Fix Summary

## What Was Wrong

### 1. Duplicate Rendering
The Twig template (`form--node--event--form.html.twig`) was rendering fields **twice**:
- First, explicitly rendering step containers and individual fields (lines 60-271)
- Second, via the "remaining fields" block using `form|without(...)` (lines 274-293)

This caused fields like "About this event" (body field) to appear multiple times in the DOM.

### 2. Invalid CSS Selectors
The CSS file (`event-wizard.css`) used jQuery-specific pseudo-selectors that are **not valid CSS**:
- `:contains("text")` - jQuery only, not CSS
- `:has-text("text")` - jQuery only, not CSS

These selectors don't work in standard CSS and caused styling to fail in some browsers.

### 3. Missing Wizard Wrapper Structure
The PHP code created individual step containers and a stepper, but they weren't wrapped in a single predictable container that Twig could reliably target. This made it difficult to ensure only the wizard UI was rendered.

## What Changed

### 1. EventFormAlter.php
- **Added `wrapWizardComponents()` method**: Creates a single `mel_event_wizard` wrapper container that contains:
  - `wizard_stepper` (left navigation)
  - `steps` container with all step panels inside
- **Updated `drupalSettings`**: Added `activeStep`, `completedSteps`, and `allowForward` properties for JS
- **Preserved step data attributes**: All `data-wizard-step` and `data-mel-step` attributes are maintained

### 2. form--node--event--form.html.twig
- **Complete rewrite**: Now renders only:
  - Required hidden form elements (`form_build_id`, `form_token`, `form_id`)
  - Single wizard wrapper (`mel_event_wizard`)
  - Hidden wizard state fields
  - Hidden vendor/store fields
  - Form actions (sticky footer)
- **Removed all duplicate rendering**: No more explicit field rendering, no "remaining fields" block
- **Clean structure**: One canonical wizard wrapper only

### 3. event-wizard.css
- **Removed invalid selectors**: All `:contains()` and `:has-text()` selectors removed
- **Added class-based rules**: Uses `.mel-hidden-drupal` class for hiding Drupal UI elements
- **Preserved all valid CSS**: Layout, stepper, action bar, responsive styles all maintained

## Files Changed

1. `web/modules/custom/myeventlane_event/src/Form/EventFormAlter.php`
   - Added `wrapWizardComponents()` method
   - Updated `drupalSettings` structure
   - Added `mel-hidden-drupal` class to format selector

2. `web/themes/custom/myeventlane_theme/templates/form--node--event--form.html.twig`
   - Complete rewrite - renders only wizard wrapper

3. `web/modules/custom/myeventlane_event/css/event-wizard.css`
   - Removed invalid `:contains()` and `:has-text()` selectors
   - Added class-based hiding rules

## Test Checklist

### Pre-Testing Commands
```bash
ddev drush cr
```

### Test as Vendor User
1. Navigate to `/vendor/events/add`
2. **Verify wizard structure**:
   - Left stepper is visible with 7 steps
   - Only "Event basics" step is visible initially
   - No duplicate "About this event" fields
   - No Drupal help text ("About text formats", etc.)
3. **Test navigation**:
   - Click "Next" → should go to "Schedule" step
   - Click stepper item for "Event basics" → should go back (can go back)
   - Try clicking "Location" stepper item → should NOT jump forward (forward navigation blocked)
   - Fill required field (title) → click "Next" → should proceed
   - Leave title empty → click "Next" → should show validation error
4. **Verify no duplicates**:
   - Inspect DOM - each field should appear only once
   - No duplicate "About this event" sections
   - No duplicate field labels

### Test as Admin User
1. Navigate to `/node/add/event`
2. **Verify same behavior**:
   - Same wizard structure
   - Same navigation rules (can go back, cannot jump forward)
   - Same validation behavior
3. **Verify no console errors**:
   - Open browser console
   - No JavaScript errors
   - No CSS selector warnings

### Verification Points
- [ ] No duplicate fields in DOM
- [ ] No Drupal-looking help text visible
- [ ] Left stepper stays visible
- [ ] Can navigate back to completed steps
- [ ] Cannot jump forward to future steps
- [ ] Required field validation works
- [ ] Works for both vendor and admin users
- [ ] No JavaScript console errors
- [ ] No CSS selector errors

## Expected Outcome

- **One clean wizard UI**: Only MEL-styled wizard, no Drupal UI remnants
- **No duplicates**: Each field appears exactly once
- **Predictable structure**: Single `mel_event_wizard` wrapper that JS can reliably target
- **Valid CSS**: All selectors are standard CSS, work across all browsers
- **Consistent behavior**: Same experience for vendor and admin users
