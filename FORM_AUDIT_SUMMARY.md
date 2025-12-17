# Event Form Audit Summary

## Issues Found

### 1. **Theme Not Switching**
- **Problem**: Vendor domain (`vendor.myeventlane.ddev.site`) is using `myeventlane_theme` instead of `myeventlane_vendor_theme`
- **Impact**: Vendor-specific form templates not being used
- **Status**: Theme negotiator exists but may not be working correctly

### 2. **Form Structure Conflict**
- **Problem**: Vendor module's form alter wraps sections in tab panes, changing structure:
  - Before: `form.visibility.content` (from EventFormAlter)
  - After: `form.visibility.content.content` (after vendor wrap)
- **Impact**: Template can't find fields because path changed
- **Fix Applied**: Updated template to handle both structures

### 3. **Visibility Section Hidden in Tabs**
- **Problem**: Vendor form alter maps `visibility` to `design` tab, which is not active by default
- **Impact**: Visibility section exists but is hidden by tab CSS
- **Status**: Template updated but tab visibility needs verification

### 4. **Field Widget Configuration**
- **Status**: ✅ Correct
  - `field_category`: `entity_reference_autocomplete_tags`
  - `field_accessibility`: `entity_reference_autocomplete_tags`
  - `field_ticket_types`: `paragraphs`

### 5. **Form Display Configuration**
- **Status**: ✅ Fields are configured in `core.entity_form_display.node.event.default.yml`
- All required fields have widgets assigned

## Files Modified

1. **`web/themes/custom/myeventlane_theme/templates/form--node--event--form.html.twig`**
   - Updated to handle both direct and vendor-wrapped visibility section structures
   - Added fallback rendering

2. **`web/modules/custom/myeventlane_event/src/Form/EventFormAlter.php`**
   - Already has visibility section building logic
   - Fields are being moved correctly (confirmed by logs)

3. **`web/themes/custom/myeventlane_vendor_theme/templates/form--node--event--form.html.twig`**
   - Created but not used (theme not active)

## Next Steps

1. **Verify Theme Switching**: Check if `VendorThemeNegotiator` is working on vendor domain
2. **Test Tab Visibility**: Ensure "Design" tab shows visibility section when clicked
3. **Check Form Rendering**: Verify fields render in both direct and tabbed modes
4. **Debug Template**: Add more debug output to see which template path is used

## Testing Checklist

- [ ] Visit `/vendor/events/add` on vendor domain
- [ ] Check browser console for errors
- [ ] Verify "Design" tab exists and is clickable
- [ ] Click "Design" tab and verify Category/Accessibility fields appear
- [ ] Set Event Type to "Paid" and verify Ticket Types appear
- [ ] Check watchdog logs for form alter execution










