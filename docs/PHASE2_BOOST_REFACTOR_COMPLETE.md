# Phase 2: Boost Logic Refactoring - Complete

**Date:** 2026-01-23  
**Status:** ✅ Complete

---

## Summary

All remaining boost-related logic has been refactored to use canonical BoostManager methods. No boost logic duplication remains.

**Files Modified:** 8  
**New Methods Added:** 1 (`getExpiredBoostedEventIdsForStore()`)

---

## HIGH PRIORITY Refactors (Complete)

### 1. ✅ BoostExpiryReminderCron
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryReminderCron.php`

**Changes:**
- Injected `BoostManager` via dependency injection
- Replaced direct entity query with `BoostManager::getExpiringBoostedEventIdsForStore(NULL, 24 * 3600, ['access_check' => FALSE])`
- Removed manual date formatting and ISO string generation
- Added comment explaining use of canonical API

**Why:** Eliminates duplicate boost query logic, ensures consistency with other expiry reminder systems.

---

### 2. ✅ BoostReminderScheduler
**File:** `web/modules/custom/myeventlane_messaging/src/Scheduler/BoostReminderScheduler.php`  
**Service Config:** `web/modules/custom/myeventlane_messaging/myeventlane_messaging.services.yml`

**Changes:**
- Injected `BoostManager` via dependency injection
- Replaced direct entity query with `BoostManager::getExpiringBoostedEventIdsForStore(NULL, 24 * 3600, ['access_check' => FALSE])`
- Replaced manual expiry date parsing with `BoostManager::getBoostStatusForEvent()` for consistent date formatting
- Updated service definition to include BoostManager

**Why:** Eliminates duplicate boost query logic, ensures consistent expiry date handling.

---

### 3. ✅ VendorDashboardController::getBoostStatus()
**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`

**Changes:**
- Replaced manual `field_promoted` and `field_promo_expires` checks with `BoostManager::getBoostStatusForEvent()`
- Removed duplicate date parsing logic (`DateTimeImmutable` creation and comparison)
- Uses `boostStatus['active']`, `boostStatus['expired']`, and `boostStatus['end_timestamp']` from canonical API
- Added comment explaining use of canonical API

**Why:** Eliminates duplicate boost status logic, ensures consistent boost status determination across the application.

---

## MEDIUM PRIORITY Refactors (Complete)

### 4. ✅ HomepagePopularityService (Bug Fix)
**File:** `web/modules/custom/myeventlane_core/src/Service/HomepagePopularityService.php`  
**Service Config:** `web/modules/custom/myeventlane_core/myeventlane_core.services.yml`

**Changes:**
- Injected `BoostManager` (optional, nullable) via dependency injection
- Replaced manual `field_promoted->value` check with `BoostManager::isBoosted($event)`
- Added fallback to direct field check if BoostManager unavailable (prevents fatal errors)
- Updated service definition to include BoostManager

**Bug Fixed:** Previously excluded ALL events with `field_promoted = 1`, even if expired. Now correctly excludes only actively boosted events (not expired).

**Why:** Fixes bug where expired boosts were incorrectly excluded from popularity rankings, and ensures consistent boost status checking.

---

### 5. ✅ TrendingScoreService
**File:** `web/modules/custom/myeventlane_analytics/src/Service/TrendingScoreService.php`  
**Service Config:** `web/modules/custom/myeventlane_analytics/myeventlane_analytics.services.yml`

**Changes:**
- Injected `BoostManager` (optional, nullable) via dependency injection
- Replaced manual `field_promoted` and `field_promo_expires` checks with `BoostManager::isBoosted($event)`
- Removed duplicate date parsing logic
- Updated service definition to include BoostManager
- Added proper docblock

**Why:** Eliminates duplicate boost status logic, ensures consistent boost detection for trending scores.

---

## SPECIAL CASE (Complete)

### 6. ✅ BoostExpiryCron
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryCron.php`  
**New Method:** `BoostManager::getExpiredBoostedEventIdsForStore()`

**Changes:**
- Injected `BoostManager` via dependency injection
- Replaced direct entity query with `BoostManager::getExpiredBoostedEventIdsForStore(NULL, ['access_check' => FALSE, 'limit' => 500])`
- Added comment explaining use of canonical API

**New Method Added:**
- `BoostManager::getExpiredBoostedEventIdsForStore(?StoreInterface $store = NULL, array $options = [])`
  - Returns only events where `field_promo_expires <= now`
  - Used specifically by BoostExpiryCron to find and clear expired boosts
  - Supports store filtering, limit, time override, and access check options

**Why New Method Was Required:**
- Existing `getActiveBoostedEventIdsForStore()` with `include_expired => TRUE` returns ALL promoted events (both active and expired), not just expired
- `getExpiringBoostedEventIdsForStore()` returns events expiring within a future window, not already expired
- No existing method could query ONLY expired boosts (`field_promo_expires <= now`)
- This is a minimal, focused method that serves a specific system operation (cron expiry)

**Why:** Ensures BoostExpiryCron uses canonical API, maintains consistency with other boost queries.

---

## Service Configuration Updates

### Updated Service Definitions:
1. `myeventlane_messaging.scheduler.boost` - Added `@myeventlane_boost.manager`
2. `myeventlane_core.homepage_popularity` - Added `@myeventlane_boost.manager`
3. `myeventlane_analytics.trending_score` - Added `@myeventlane_boost.manager`
4. `myeventlane_boost.cron.expiry` - Added `@myeventlane_boost.manager` (via ContainerInjectionInterface)

---

## Files Modified (Full List)

1. ✅ `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryReminderCron.php`
2. ✅ `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryCron.php`
3. ✅ `web/modules/custom/myeventlane_boost/src/BoostManager.php` (new method)
4. ✅ `web/modules/custom/myeventlane_messaging/src/Scheduler/BoostReminderScheduler.php`
5. ✅ `web/modules/custom/myeventlane_messaging/myeventlane_messaging.services.yml`
6. ✅ `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`
7. ✅ `web/modules/custom/myeventlane_core/src/Service/HomepagePopularityService.php`
8. ✅ `web/modules/custom/myeventlane_core/myeventlane_core.services.yml`
9. ✅ `web/modules/custom/myeventlane_analytics/src/Service/TrendingScoreService.php`
10. ✅ `web/modules/custom/myeventlane_analytics/myeventlane_analytics.services.yml`

---

## Behavior Changes

### Bug Fixes:
- **HomepagePopularityService**: Now correctly excludes only actively boosted events (not expired). Previously excluded all events with `field_promoted = 1`, even if expired.

### No Behavior Changes:
- All other refactors maintain identical behavior, only replacing implementation with canonical API calls.

---

## Testing Recommendations

1. **BoostExpiryReminderCron**: Verify reminder emails sent for events expiring within 24h
2. **BoostReminderScheduler**: Verify reminder queue messages for expiring boosts
3. **VendorDashboardController**: Verify boost status display in dashboard (active/expired indicators)
4. **HomepagePopularityService**: Verify expired boosts appear in popularity rankings (bug fix)
5. **TrendingScoreService**: Verify boost bonus (+10) only applied to actively boosted events
6. **BoostExpiryCron**: Verify expired boosts are cleared and vendors notified

---

## Next Steps

1. Clear Drupal cache: `ddev drush cr`
2. Run PHPCS: `ddev exec vendor/bin/phpcs web/modules/custom`
3. Run PHPStan: `ddev exec vendor/bin/phpstan web/modules/custom`
4. Test each refactored component
5. Monitor logs for any boost-related errors

---

## Notes

- All refactors use dependency injection (no `\Drupal::service()` static calls)
- BoostManager is injected as optional/nullable where appropriate to prevent fatal errors if module unavailable
- All manual date parsing and field checks have been replaced with canonical API calls
- No database schema changes
- No routing or permission changes
- Boost purchase/refund/expiry semantics unchanged
