# Debug Instructions - Event Form Fields

## Steps to Diagnose the Issue

1. **Clear cache and check logs:**
```bash
ddev drush cr
ddev drush watchdog-show --tail=100 | grep -E "(myeventlane_event|myeventlane_vendor)" | tail -50
```

2. **Visit the form:**
- Navigate to `/vendor/events/add`
- Let the page fully load

3. **Check watchdog logs immediately:**
```bash
ddev drush watchdog-show --tail=200 | grep -E "(EventFormAlter|Vendor form alter)" 
```

4. **Look for these key messages:**

**EventFormAlter logs:**
- `EventFormAlter START - Top-level form keys:` - Shows what fields exist in form
- `Field @field found in form at alterForm start` - Confirms field exists
- `Field @field NOT found in form at alterForm start` - Field missing!
- `Found field_ticket_types, moving to booking_config.content.paid_fields` - Moving field
- `Moved field_ticket_types to booking_config.content.paid_fields` - Move successful
- `Moving field @field to visibility section` - Moving visibility fields

**Vendor form alter logs:**
- `Vendor form alter START - Top-level form keys:` - What exists before wrapping
- `Has booking_config: YES/NO` - Section exists?
- `Has visibility: YES/NO` - Section exists?
- `Wrapping section: @section` - Which sections are being wrapped
- `Preserved existing content structure for @section` - Structure preserved
- `booking_config.content.paid_fields keys:` - What's in paid_fields after wrapping
- `visibility.content keys:` - What's in visibility.content after wrapping

## Common Issues to Check

1. **Fields not in form at all:**
   - If logs show "Field @field NOT found in form at alterForm start"
   - Check form display config: `ddev drush config:get core.entity_form_display.node.event.default content.field_ticket_types`
   - Field might be hidden in form display

2. **Fields exist but not moved:**
   - If "Field @field found" but NOT "Moved field @field"
   - Check if conditions are met (e.g., paid_fields container exists)

3. **Sections not created:**
   - If "Has booking_config: NO" - EventFormAlter didn't create sections
   - Check if EventFormAlter is running at all

4. **Double-nesting still exists:**
   - Check "booking_config.content.paid_fields keys:" log
   - If it shows fields, structure is correct
   - If empty or shows "content" key, double-nesting exists

5. **Fields lost during wrapping:**
   - Compare "booking_config.content.paid_fields keys:" before and after wrapping
   - If keys disappear, wrapping logic is breaking structure

## Next Steps Based on Logs

Please run the debug steps above and share:
1. All log messages from EventFormAlter
2. All log messages from Vendor form alter
3. Any errors or warnings

This will help identify exactly where the fields are being lost.










