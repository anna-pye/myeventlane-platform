# RSVP Donation Functionality Review

## Summary

Reviewed the RSVP donation functionality and identified several potential issues that could prevent donations from appearing on RSVP forms.

## Issues Found

### 1. Store Lookup Logic
The original code only looked up stores by the event owner's UID. However, stores might also be linked via the vendor entity's `field_vendor_store` field. This could cause the Stripe Connect check to fail even when the vendor has Stripe enabled.

**Fix Applied:**
- Updated `isVendorStripeConnected()` in both `RsvpPublicForm` and `RsvpBookingForm` to:
  1. First check for store via vendor entity (`field_vendor_store`)
  2. Fallback to direct store lookup by owner UID
  3. This ensures stores are found regardless of how they're linked

### 2. Insufficient Debugging
There was minimal logging to help diagnose why donations weren't appearing.

**Fix Applied:**
- Added comprehensive debug logging at key decision points:
  - When donation config is checked
  - When Stripe Connect requirement is evaluated
  - When store lookup succeeds/fails
  - When donation section is shown/hidden and why

## How Donations Work

The donation section appears on RSVP forms when:

1. **Global Setting**: `enable_rsvp_donations` must be enabled in donation settings
   - Path: `/admin/config/myeventlane/donations`
   - Default: Enabled (`true`)

2. **Stripe Connect Requirement** (if enabled):
   - If `require_stripe_connected_for_attendee_donations` is `true` (default), the vendor must have:
     - A commerce store linked to their account
     - Stripe Connect enabled (`field_stripe_charges_enabled` or `field_stripe_connected` set to `true`)
   - If this requirement is disabled, donations will show regardless of Stripe status

## Configuration Check

To verify donation settings:

1. **Check Donation Settings:**
   ```bash
   ddev drush config:get myeventlane_donations.settings
   ```

2. **Expected values:**
   - `enable_rsvp_donations: true`
   - `require_stripe_connected_for_attendee_donations: true` (or `false` to allow without Stripe)

3. **Check Event Vendor's Stripe Status:**
   - Navigate to the event page
   - Check the event owner's store
   - Verify `field_stripe_charges_enabled` or `field_stripe_connected` is set

## Debugging

With the improved logging, you can now check the watchdog logs to see why donations aren't appearing:

```bash
# View recent RSVP donation debug logs
ddev drush watchdog-show --filter=myeventlane_rsvp --count=50 | grep -i donation
```

The logs will show:
- Whether donations are enabled globally
- Whether Stripe Connect is required
- Store lookup results
- Final decision on showing/hiding donation section

## Files Modified

1. `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php`
   - Improved `isVendorStripeConnected()` method
   - Added debug logging

2. `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php`
   - Improved `isVendorStripeConnected()` method

## Next Steps

1. **Clear cache:**
   ```bash
   ddev drush cr
   ```

2. **Check donation settings:**
   - Visit `/admin/config/myeventlane/donations`
   - Ensure "Enable RSVP attendee donations" is checked
   - Review "Require Stripe Connect for attendee donations" setting

3. **Verify vendor Stripe status:**
   - For the event in question, check if the vendor has completed Stripe Connect onboarding
   - If Stripe Connect is required but not enabled, either:
     - Complete Stripe Connect onboarding for the vendor, OR
     - Disable the "Require Stripe Connect" requirement in donation settings

4. **Test the form:**
   - Visit an RSVP event page
   - Check if the donation section appears
   - Review watchdog logs if it doesn't appear

## Common Issues

### Donations not showing even after fixes:

1. **Donations disabled globally:**
   - Check `/admin/config/myeventlane/donations`
   - Enable "Enable RSVP attendee donations"

2. **Stripe Connect required but not enabled:**
   - Vendor needs to complete Stripe Connect onboarding
   - Or disable the requirement in donation settings

3. **Store not found:**
   - Vendor may not have a commerce store created
   - Check if store exists and is linked to vendor

4. **Cache issues:**
   - Clear Drupal cache: `ddev drush cr`
   - Clear browser cache

## Testing

After applying fixes, test by:

1. Creating/editing an RSVP event
2. Ensuring the vendor has Stripe Connect enabled (if required)
3. Viewing the event page as an anonymous user
4. Verifying the donation section appears in the RSVP form
5. Testing donation submission flow













