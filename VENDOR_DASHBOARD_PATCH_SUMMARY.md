# Vendor Dashboard Analytics + Access Patch Summary

## Overview
Stabilized the vendor dashboard by fixing analytics services, route access, and UI state logic to eliminate undefined method errors and ensure consistent behavior.

## Changes Made

### Phase 1: Analytics Service Audit ✅

**File: `web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php`**
- ✅ Fixed constructor to accept `Connection`, `TimeInterface`, and `EntityTypeManagerInterface`
- ✅ Implemented real methods:
  - `getVendorRsvpCount(int $vendor_uid): int` - Counts RSVPs across all vendor events
  - `getEventRsvpCount(int $event_nid): int` - Counts RSVPs for a specific event
  - `getRsvpSummary(int $event_nid): array` - Returns summary with 'count' key
  - `getEventRsvpSummary(int $event_nid): array` - Alias for consistency
  - `getStatsForEvent(int $event_nid): array` - Returns total and recent counts
- ✅ All methods return defensive defaults (0 or empty arrays), never null
- ✅ Handles both entity storage (rsvp_submission) and legacy table (myeventlane_rsvp)
- ✅ Updated service definition in `myeventlane_vendor.services.yml` to include EntityTypeManagerInterface

### Phase 2: Boost Status Service Alignment ✅

**File: `web/modules/custom/myeventlane_vendor/src/Service/BoostStatusService.php`**
- ✅ Implemented `getBoostStatuses(int $event_nid): array` with proper structure:
  - `eligible`: bool - Whether event is eligible for boosting
  - `active`: bool - Whether boost is currently active
  - `reason`: string|null - Reason if not eligible (e.g., 'unpublished', 'missing_event')
  - `types`: array - Available boost types
  - `expires`: string|null - Expiration date if active
- ✅ Guards against null/invalid event_nid
- ✅ Checks event publication status
- ✅ Validates boost expiration dates

### Phase 3: Metrics Aggregator Fix ✅

**File: `web/modules/custom/myeventlane_vendor/src/Service/MetricsAggregator.php`**
- ✅ All method calls verified to exist
- ✅ Gracefully handles unpublished events by returning safe defaults:
  - Zero counts for attendees, capacity, revenue
  - Empty arrays for sales, audience, tickets
  - Boost status with `eligible: false, reason: 'unpublished'`
- ✅ Wrapped all service calls in try-catch blocks
- ✅ Never throws on missing data

### Phase 4: Vendor Dashboard Controller Hardening ✅

**File: `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`**
- ✅ Fixed KPI builders with defensive checks
- ✅ No property accessed before assignment
- ✅ No method called on null
- ✅ Handles unpublished events in `getEventsTableData()`:
  - Shows "Draft" status for unpublished events
  - Displays zero/empty metrics safely
  - Boost button shows "Publish to boost" for unpublished events
- ✅ Added try-catch around RSVP stats and boost status calls
- ✅ Improved boost button logic with proper state handling

### Phase 5: Route Access Patch (Boost) ✅

**File: `web/modules/custom/myeventlane_boost/src/Access/BoostRouteAccess.php`**
- ✅ Access rules enforced:
  - Owner OR admin
  - Event published = TRUE
  - Vendor has Stripe connected
- ✅ Returns `AccessResult::forbidden()` with proper message for unpublished events
- ✅ Attaches cacheability correctly
- ✅ Added debug logging at decision points (only for denied access)
- ✅ Stripe connection check:
  - Checks `commerce_store.field_stripe_account_id`
  - Falls back to `myeventlane_vendor.field_stripe_account_id`
  - Validates `field_stripe_connected` if available

### Phase 6: User-Facing Message ✅

**File: `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`**
- ✅ Added `message` field to boost button data
- ✅ Shows "Publish this event to enable boosting." for unpublished events
- ✅ Message available in template via `boost.message`

### Phase 7: Dashboard UI Alignment ✅

**File: `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`**
- ✅ Boost button states:
  - Draft event → "Publish to boost" (no URL)
  - Published + eligible → "Boost event" (with URL)
  - Published + boosted → "Boost active" (with URL)
- ✅ Buttons do not link to inaccessible routes
- ✅ `allowed` flag properly set based on publish + eligibility

## Service Definition Updates

**File: `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml`**
- Updated `myeventlane_vendor.service.rsvp_stats` to include `@entity_type.manager` argument

## Verification

✅ Cache cleared successfully (`ddev drush cr`)
✅ No PHP linter errors
✅ Watchdog shows expected access denied messages for unpublished events (working as intended)
✅ All services properly injected
✅ All method calls verified to exist

## Key Improvements

1. **Defensive Programming**: All methods return safe defaults, never null
2. **Error Handling**: Try-catch blocks around all service calls
3. **Access Control**: Proper Stripe connection check added to boost route
4. **User Experience**: Clear messages for unpublished events
5. **State Management**: Boost button reflects actual publish/boost state correctly

## Testing Recommendations

1. Test vendor dashboard with:
   - Draft events (should show zero metrics, "Publish to boost" button)
   - Published events (should show real metrics, "Boost event" button)
   - Published + boosted events (should show "Boost active" button)
   - Events with zero RSVPs (should show 0, not error)

2. Test boost route access:
   - Unpublished event → Should deny with message
   - Published event without Stripe → Should deny with message
   - Published event with Stripe → Should allow

3. Verify analytics:
   - All KPI cards render correctly
   - Event table shows correct metrics
   - No undefined method errors in logs
