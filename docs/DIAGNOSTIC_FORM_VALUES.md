# Form Value Extraction Diagnostic

## Issue
Date/time and address fields are not saving, and suburb/state/postcode are not populating from address lookup.

## Root Cause Analysis

### 1. Form Structure Issue
When fields are moved to the wizard, they're copied (not referenced) because PHP arrays are copied by value. This means:
- JavaScript updates the DOM elements in the wizard
- But Drupal's form state might not recognize these updates
- Form values are extracted using `#parents` array, which should work regardless of form structure location

### 2. JavaScript Field Finding
The JavaScript might not be finding suburb/state/postcode fields because:
- Fields might be in a different DOM structure than expected
- Field names/selectors might not match
- Fields might be hidden or not rendered

### 3. Form Value Extraction
Even if JavaScript populates fields, Drupal's form system needs to:
- Recognize the values during form submission
- Extract them using `#parents` array
- Map them to entity fields correctly

## Diagnostic Steps

1. **Check Browser Console** - Look for JavaScript errors and field detection logs
2. **Check Server Logs** - Run `ddev drush ws --count=50 | grep myeventlane_event` to see diagnostic output
3. **Verify Form Values** - The diagnostic method logs form values before/after extraction

## Expected Diagnostic Output

When you try to save, you should see logs like:
```
=== FORM DIAGNOSTIC: Before extractFormValues ===
Field field_event_start: top=NO, wizard=YES, path=step_basics.section.field_event_start, parents=field_event_start > 0 > value, has_value=YES/NO
Field field_location: top=NO, wizard=YES, path=step_basics.section.field_location, parents=field_location > 0 > address, has_value=YES/NO
```

This will show:
- Whether fields are in wizard or top level
- What their #parents array is
- Whether form values exist at the #parents path

## Next Steps

After running diagnostics, we'll know:
1. If fields are being found by JavaScript
2. If form values are being set correctly
3. If #parents are correct
4. If extractFormValues is working

Then we can fix the specific issue.
