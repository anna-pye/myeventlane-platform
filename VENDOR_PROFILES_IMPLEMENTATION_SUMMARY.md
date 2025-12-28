# Vendor + Customer Onboarding/Profile Implementation Summary

**Branch:** `feature/vendor-profiles`  
**Date:** 2025-01-27  
**Status:** ✅ Complete

## Overview

Implemented complete Vendor + Customer onboarding/profile solution for MyEventLane v2, adapted to work with existing `myeventlane_vendor` custom entity (not node bundle).

## Implementation Details

### 1. Vendor Fields Added ✅

**New Fields on `myeventlane_vendor` entity:**
- `field_accent_colour` (string, max 7 chars) - HEX colour like #AABBCC
- `field_tagline` (string, max 255 chars) - Vendor tagline

**Existing Fields Used:**
- `field_vendor_logo` - Logo image (direct image field)
- `field_banner_image` - Banner image (direct image field)  
- `field_summary` - Summary/tagline (fallback if tagline not set)

**Config Files Created:**
- `web/modules/custom/myeventlane_vendor/config/install/field.storage.myeventlane_vendor.field_accent_colour.yml`
- `web/modules/custom/myeventlane_vendor/config/install/field.field.myeventlane_vendor.myeventlane_vendor.field_accent_colour.yml`
- `web/modules/custom/myeventlane_vendor/config/install/field.storage.myeventlane_vendor.field_tagline.yml`
- `web/modules/custom/myeventlane_vendor/config/install/field.field.myeventlane_vendor.myeventlane_vendor.field_tagline.yml`

### 2. Event Card View Mode ✅

**Created:**
- View mode: `card` (machine name: `node.card`)
- Display config: `core.entity_view_display.node.event.card`

**Config Files:**
- `web/modules/custom/myeventlane_schema/config/install/core.entity_view_mode.node.card.yml`
- `web/modules/custom/myeventlane_schema/config/install/core.entity_view_display.node.event.card.yml`

**Displays:**
- Title (linked)
- `field_event_start` (datetime, compact format)
- All other fields hidden

### 3. Vendor Upcoming Events View ✅

**View:** `vendor_upcoming_events`
- **Base:** Content (node)
- **Display:** Block only (`block_1`)
- **Filters:**
  - Content type = `event`
  - Published = Yes
  - `field_event_end` >= now
- **Contextual Filter:**
  - `field_event_vendor_target_id` (entity reference target ID)
  - Default: Entity ID from URL (`entity_id:myeventlane_vendor`)
  - Validates as `entity:myeventlane_vendor`
- **Sort:** `field_event_start` ascending
- **Row Style:** Rendered entity, view mode: `card`

**Config File:**
- `web/modules/custom/myeventlane_views/config/install/views.view.vendor_upcoming_events.yml`

### 4. Pathauto Pattern Updated ✅

**Updated Pattern:**
- From: `vendor/[myeventlane_vendor:name]`
- To: `vendors/[myeventlane_vendor:name]`

**Config File:**
- `web/modules/custom/myeventlane_vendor/config/install/pathauto.pattern.myeventlane_vendor.yml`

**Public Vendor Profile URL:** `/vendors/{vendor-name}`

### 5. Twig Templates Created ✅

**Vendor Full Profile:**
- `web/themes/custom/myeventlane_theme/templates/myeventlane-vendor--myeventlane-vendor--full.html.twig`
- Shows: banner, logo, title, tagline, body, upcoming events block
- Applies vendor accent colour via CSS variable

**Vendor Teaser:**
- `web/themes/custom/myeventlane_theme/templates/myeventlane-vendor--myeventlane-vendor--teaser.html.twig`
- Shows: logo, title, tagline (card format)

**Event Card:**
- `web/themes/custom/myeventlane_theme/templates/node--event--card.html.twig`
- Shows: title (linked), event start date

### 6. SCSS Component Created ✅

**File:**
- `web/themes/custom/myeventlane_theme/src/scss/components/_vendor-profile.scss`

**Styles:**
- `.vendor-profile` - Full profile layout with accent colour support
- `.vendor-card` - Teaser card layout
- `.event-card` - Event card layout

**Updated:**
- `web/themes/custom/myeventlane_theme/src/scss/main.scss` - Added `@use 'components/vendor-profile';`

### 7. Vendor Dashboard Enhanced ✅

**Enhanced Controller:**
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`

**Added:**
- Loads vendor entity for current user
- Provides vendor edit URL
- Passes `vendor` and `vendor_edit_url` to template

**Route:** `/vendor/dashboard` (already exists)

### 8. Git Cleanup ✅

**Removed from Git:**
- `web/themes/custom/MEL_Custom Themes.zip`

**.gitignore:**
- Already includes `*.zip` pattern (lines 88-90)

## Commands to Run

### 1. Import New Configs

```bash
# Import vendor fields and pathauto pattern
ddev drush config:import --source=modules/custom/myeventlane_vendor/config/install -y

# Import event card view mode
ddev drush config:import --source=modules/custom/myeventlane_schema/config/install -y

# Import vendor upcoming events view (already done)
# ddev drush config:import --source=modules/custom/myeventlane_views/config/install --partial -y
```

### 2. Rebuild Cache

```bash
ddev drush cr
```

### 3. Compile SCSS

```bash
cd web/themes/custom/myeventlane_theme
ddev npm run build
```

### 4. Verify

1. **Visit a vendor profile:**
   - URL should be `/vendors/{vendor-name}`
   - Should show vendor hero with logo, banner, tagline
   - Should show "Upcoming events" section with events where `field_event_end >= now`

2. **Test vendor dashboard:**
   - Visit `/vendor/dashboard` as vendor user
   - Should show vendor info and edit link

3. **Test event filtering:**
   - Create a past event (end date < now) for a vendor
   - Verify it does NOT appear in vendor profile "Upcoming events"
   - Create a future event (end date >= now)
   - Verify it DOES appear

## Files Created/Modified

### Config Files (in module config/install)
- `myeventlane_vendor/config/install/field.storage.myeventlane_vendor.field_accent_colour.yml`
- `myeventlane_vendor/config/install/field.field.myeventlane_vendor.myeventlane_vendor.field_accent_colour.yml`
- `myeventlane_vendor/config/install/field.storage.myeventlane_vendor.field_tagline.yml`
- `myeventlane_vendor/config/install/field.field.myeventlane_vendor.myeventlane_vendor.field_tagline.yml`
- `myeventlane_vendor/config/install/pathauto.pattern.myeventlane_vendor.yml` (updated)
- `myeventlane_schema/config/install/core.entity_view_mode.node.card.yml`
- `myeventlane_schema/config/install/core.entity_view_display.node.event.card.yml`
- `myeventlane_views/config/install/views.view.vendor_upcoming_events.yml`

### Theme Files
- `themes/custom/myeventlane_theme/templates/myeventlane-vendor--myeventlane-vendor--full.html.twig`
- `themes/custom/myeventlane_theme/templates/myeventlane-vendor--myeventlane-vendor--teaser.html.twig`
- `themes/custom/myeventlane_theme/templates/node--event--card.html.twig`
- `themes/custom/myeventlane_theme/src/scss/components/_vendor-profile.scss`
- `themes/custom/myeventlane_theme/src/scss/main.scss` (updated)

### Module Files
- `modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` (enhanced)

## Notes

1. **Media Module:** Installed and enabled. Image media type exists (machine name: `image`). However, vendor entity uses direct image fields, not media entity references.

2. **Vendor Entity:** Uses existing `myeventlane_vendor` custom entity (not node bundle). Adapted all requirements to work with this structure.

3. **Event → Vendor Relationship:** Uses existing `field_event_vendor` field (entity reference to `myeventlane_vendor`).

4. **View Contextual Filter:** Uses `entity_id:myeventlane_vendor` to get vendor ID from route parameter `{myeventlane_vendor}`.

5. **Pathauto:** Pattern updated to `/vendors/{vendor-name}`. Existing canonical route `/vendor/{myeventlane_vendor}` still works.

## Next Steps

1. Import remaining configs if needed (some may require manual field creation)
2. Test vendor profile pages
3. Test vendor dashboard
4. Verify event filtering works correctly
5. Build SCSS assets
6. Commit changes to `feature/vendor-profiles` branch
