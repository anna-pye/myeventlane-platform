# Phase 2 Testing Guide - Vendor Module

## Overview
This guide helps you test all Phase 2 functionality:
- Vendor entity fields
- Commerce Store fields for Stripe
- Auto-create store on vendor creation
- Form display with sections
- Pathauto configuration

## Pre-Testing Checklist

### 1. Verify Module Installation
```bash
ddev drush pm:list --status=enabled --type=module | grep myeventlane_vendor
```
Should show: `MyEventLane Vendor (myeventlane_vendor) Enabled`

### 2. Clear Caches
```bash
ddev drush cr
```

## Testing Steps

### Test 1: Verify Vendor Fields Exist

**Via Drush:**
```bash
# Check vendor fields
ddev drush config:list | grep "field.field.myeventlane_vendor.myeventlane_vendor.field_vendor"

# Should see:
# - field.field.myeventlane_vendor.myeventlane_vendor.field_vendor_bio
# - field.field.myeventlane_vendor.myeventlane_vendor.field_vendor_logo
# - field.field.myeventlane_vendor.myeventlane_vendor.field_vendor_users
# - field.field.myeventlane_vendor.myeventlane_vendor.field_vendor_store
# - field.field.myeventlane_vendor.myeventlane_vendor.field_public_show_email
# - field.field.myeventlane_vendor.myeventlane_vendor.field_public_show_phone
# - field.field.myeventlane_vendor.myeventlane_vendor.field_public_show_location
```

**Via UI:**
1. Go to `/admin/structure/myeventlane/vendor/settings`
2. Click "Manage fields" tab
3. Verify all fields listed above are present

### Test 2: Verify Commerce Store Fields Exist

**Via Drush:**
```bash
# Check store fields
ddev drush config:list | grep "field.field.commerce_store.online.field_stripe"

# Should see:
# - field.field.commerce_store.online.field_stripe_account_id
# - field.field.commerce_store.online.field_stripe_onboard_url
# - field.field.commerce_store.online.field_stripe_dashboard_url
# - field.field.commerce_store.online.field_stripe_connected
# - field.field.commerce_store.online.field_stripe_charges_enabled
# - field.field.commerce_store.online.field_stripe_payouts_enabled
# - field.field.commerce_store.online.field_vendor_reference
```

**Via UI:**
1. Go to `/admin/commerce/config/stores`
2. Edit any store (or create a new one)
3. Verify Stripe fields are visible (URL fields should be hidden from form)

### Test 3: Test Auto-Create Store Functionality

**Steps:**
1. Go to `/admin/structure/myeventlane/vendor/add`
2. Fill in:
   - Vendor name: "Test Vendor Auto-Store"
   - Leave store field empty
3. Save the vendor

**Expected Results:**
- Vendor is created successfully
- A Commerce Store is automatically created
- Store name should be: "Test Vendor Auto-Store Store"
- Store is linked to the vendor (bidirectional)
- Store has Australian defaults (AUD currency, Australia/Sydney timezone)

**Verify via Drush:**
```bash
# Get the vendor ID
ddev drush sqlq "SELECT id, name FROM myeventlane_vendor WHERE name LIKE 'Test Vendor%'" | cat

# Check if store was created and linked
ddev drush sqlq "SELECT s.id, s.name, s.field_vendor_reference_target_id FROM commerce_store_field_data s WHERE s.name LIKE '%Test Vendor%'" | cat
```

### Test 4: Test Form Display Sections

**Steps:**
1. Go to `/admin/structure/myeventlane/vendor/add` (or edit existing)
2. Verify form is organized into sections:
   - **Basic information** section:
     - Vendor name
     - Logo
     - Bio
   - **Contact details and visibility** section:
     - Show email publicly
     - Show phone publicly
     - Show location publicly
     - Users (multiple)
   - **Store and Stripe integration** section (admin-only, collapsible):
     - Store reference

**Expected:**
- Fields are grouped logically
- Store/Stripe section is collapsible and collapsed for new vendors
- Store/Stripe section only visible to admins

### Test 5: Test Pathauto Configuration

**Steps:**
1. Create or edit a vendor with name "Test Path Vendor"
2. Save the vendor
3. Check the URL alias

**Expected:**
- URL should be: `/vendor/test-path-vendor` (or similar, based on pathauto settings)
- Pathauto pattern: `/vendor/[myeventlane_vendor:name]`

**Verify via Drush:**
```bash
# Check pathauto pattern
ddev drush config:get pathauto.pattern.myeventlane_vendor

# Check URL aliases for vendors
ddev drush sqlq "SELECT alias FROM path_alias WHERE path LIKE '/vendor/%'" | cat
```

### Test 6: Test Store Form Display

**Steps:**
1. Go to `/admin/commerce/config/stores`
2. Edit a store that's linked to a vendor
3. Verify:
   - Stripe Account ID field is visible
   - Stripe Connected checkbox is visible
   - Charges Enabled checkbox is visible
   - Payouts Enabled checkbox is visible
   - Vendor reference field is visible
   - **Onboarding URL and Dashboard URL fields are HIDDEN** (admin-only)

### Test 7: Test Bidirectional Linking

**Steps:**
1. Create a vendor (store auto-created)
2. Edit the vendor and verify store is linked
3. Edit the store and verify vendor is linked back

**Verify via Drush:**
```bash
# Get vendor and its store
ddev drush sqlq "SELECT v.id as vendor_id, v.name as vendor_name, v.field_vendor_store_target_id as store_id FROM myeventlane_vendor_field_data v WHERE v.id = 1" | cat

# Get store and its vendor
ddev drush sqlq "SELECT s.id as store_id, s.name as store_name, s.field_vendor_reference_target_id as vendor_id FROM commerce_store_field_data s WHERE s.id = [STORE_ID]" | cat
```

## Troubleshooting

### If fields are missing:
```bash
# Re-import config
ddev drush config:import -y
ddev drush cr
```

### If auto-create store doesn't work:
1. Check logs: `/admin/reports/dblog` - filter by "myeventlane_vendor"
2. Verify event subscriber is registered:
```bash
ddev drush config:get myeventlane_vendor.services.yml
```

### If pathauto doesn't work:
1. Ensure pathauto module is enabled:
```bash
ddev drush pm:list --status=enabled | grep pathauto
```
2. If not enabled:
```bash
ddev drush en pathauto -y
ddev drush cr
```

## Success Criteria

✅ All vendor fields are present and configurable
✅ All Commerce Store Stripe fields are present
✅ Store is auto-created when vendor is created
✅ Form displays are organized into logical sections
✅ Pathauto generates URLs for vendors
✅ Bidirectional linking works between vendor and store
✅ No PHP errors in logs
✅ All fields save correctly

## Next Steps

Once all tests pass, you're ready for Phase 3: Event content type, fields, and linkage to vendor and store.




















