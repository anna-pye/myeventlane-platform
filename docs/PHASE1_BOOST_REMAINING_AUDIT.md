# Phase 1: Remaining Boost Logic Audit

**Date:** 2026-01-23  
**Purpose:** Identify all remaining boost-related logic that does not use canonical BoostManager methods.

---

## Summary

Found **7 call sites** that need refactoring to use BoostManager canonical methods.  
**1 Views configuration** documented (no refactor needed - Views config only).

---

## Call Sites Requiring Refactoring

### 1. BoostExpiryCron::process()
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryCron.php`  
**Method:** `process()` (lines 54-91)  
**Current Logic:**
- Directly queries `field_promoted = 1` and `field_promo_expires <= now`
- Manually clears boost fields (`field_promoted = 0`, `field_promo_expires = NULL`)
- Sends expiry notifications

**Should Use:**
- `BoostManager::getActiveBoostedEventIdsForStore(NULL, ['include_expired' => TRUE])` - but this doesn't exist yet
- OR: Keep direct query for expiry cron (acceptable for system operations), but consider adding `getExpiredBoostedEventIds()` method to BoostManager for consistency

**Recommendation:** 
- Option A: Add `getExpiredBoostedEventIds(?StoreInterface $store = NULL, array $options = [])` to BoostManager, use it here
- Option B: Keep direct query (acceptable for cron/system operations), but document why

**Priority:** Medium (cron job, acceptable to have direct query, but should be consistent)

---

### 2. BoostExpiryReminderCron::process()
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryReminderCron.php`  
**Method:** `process()` (lines 57-124)  
**Current Logic:**
- Directly queries `field_promoted = 1`, `field_promo_expires > now`, `field_promo_expires <= now+24h`
- Sends reminder emails for events expiring within 24 hours

**Should Use:**
- `BoostManager::getExpiringBoostedEventIdsForStore(NULL, 24 * 3600, ['access_check' => FALSE])`

**Recommendation:** Inject BoostManager, replace query with canonical method.

**Priority:** High (already has canonical method available)

---

### 3. BoostReminderScheduler::scan()
**File:** `web/modules/custom/myeventlane_messaging/src/Scheduler/BoostReminderScheduler.php`  
**Method:** `scan()` (lines 33-84)  
**Current Logic:**
- Directly queries `field_promoted = 1`, `field_promo_expires > now`, `field_promo_expires <= now+24h`
- Queues reminder emails

**Should Use:**
- `BoostManager::getExpiringBoostedEventIdsForStore(NULL, 24 * 3600, ['access_check' => FALSE])`
- Then load events and queue reminders

**Recommendation:** Inject BoostManager, replace query with canonical method.

**Priority:** High (already has canonical method available)

---

### 4. VendorDashboardController::getBoostStatus()
**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`  
**Method:** `getBoostStatus()` (lines 659-716)  
**Current Logic:**
- Uses `BoostManager::isBoosted()` ✅ (good)
- BUT then manually checks `field_promoted` and `field_promo_expires` to determine expiry date and expired status
- Duplicates date parsing logic

**Should Use:**
- `BoostManager::getBoostStatusForEvent($event)` - returns full status including `expired`, `end_timestamp`, etc.

**Recommendation:** Replace manual field checks with `getBoostStatusForEvent()` result.

**Priority:** High (duplicates logic, should use canonical API)

---

### 5. HomepagePopularityService::isValidEvent()
**File:** `web/modules/custom/myeventlane_core/src/Service/HomepagePopularityService.php`  
**Method:** `isValidEvent()` (lines 180-211)  
**Current Logic:**
- Directly checks `field_promoted->value` to exclude boosted events from popularity rankings
- Only checks if field exists and value is truthy, doesn't check expiry

**Should Use:**
- `BoostManager::isBoosted($event)` - properly checks both promoted flag AND expiry

**Recommendation:** Inject BoostManager, replace manual check with `isBoosted()`.

**Priority:** Medium (bug: doesn't check expiry, could include expired boosts)

---

### 6. TrendingScoreService::score()
**File:** `web/modules/custom/myeventlane_analytics/src/Service/TrendingScoreService.php`  
**Method:** `score()` (lines 25-60)  
**Current Logic:**
- Manually loads event, checks `field_promoted->value` and `field_promo_expires->value`
- Manually parses expiry date and compares with current time
- Adds +10 to score if boosted

**Should Use:**
- `BoostManager::isBoosted($event)` or `BoostManager::getBoostStatusForEvent($event)`

**Recommendation:** Inject BoostManager, replace manual checks with canonical method.

**Priority:** Medium (duplicates logic, but low impact - analytics scoring)

---

### 7. MyCategoriesController::build()
**File:** `web/modules/custom/myeventlane_core/src/Controller/MyCategoriesController.php`  
**Method:** `build()` (lines 84-160)  
**Current Logic:**
- Queries events, sorts by `field_promoted DESC` (line 99)
- Uses `BoostManager::isBoosted()` to set `is_boosted` flag in output ✅ (good)

**Should Use:**
- Sorting by `field_promoted` is acceptable (database-level sort)
- Already uses `isBoosted()` for output flag ✅

**Recommendation:** No change needed - sorting by field is fine, boost status check already uses canonical method.

**Priority:** Low (acceptable - database sort is fine, status check already canonical)

---

## Views Configuration (Document Only)

### 8. Featured Events View
**File:** `_myeventlane_audit/config-sync/views.view.featured_events.yml`  
**Type:** Views configuration (YAML)  
**Current Logic:**
- Filters by `field_promoted_value = 1` (boolean filter)
- Filters by `field_promo_expires_value > now` (datetime filter)

**Recommendation:** 
- Document only - Views configuration uses field filters, not PHP code
- No refactoring needed unless View is converted to custom PHP handler
- If custom PHP handler is added later, use BoostManager methods

**Priority:** N/A (Views config, no code changes)

---

## Summary by Priority

### High Priority (Must Refactor)
1. ✅ **BoostExpiryReminderCron** - Use `getExpiringBoostedEventIdsForStore()`
2. ✅ **BoostReminderScheduler** - Use `getExpiringBoostedEventIdsForStore()`
3. ✅ **VendorDashboardController::getBoostStatus()** - Use `getBoostStatusForEvent()`

### Medium Priority (Should Refactor)
4. ⚠️ **BoostExpiryCron** - Consider adding `getExpiredBoostedEventIds()` or document why direct query is acceptable
5. ⚠️ **HomepagePopularityService** - Use `isBoosted()` (also fixes bug: doesn't check expiry)
6. ⚠️ **TrendingScoreService** - Use `isBoosted()` or `getBoostStatusForEvent()`

### Low Priority / No Change
7. ✅ **MyCategoriesController** - Already uses canonical method, sorting is acceptable
8. 📄 **Featured Events View** - Views config only, document only

---

## Notes

- **BoostExpiryCron** is a special case: it needs to find expired boosts AND clear them. Consider if this should be a BoostManager method `revokeExpiredBoosts()` or keep as-is for system operations.
- **HomepagePopularityService** has a bug: it excludes boosted events but doesn't check expiry, so expired boosts are incorrectly excluded from popularity rankings.
- All other call sites should be straightforward refactors to use existing BoostManager methods.

---

## Next Steps

1. Refactor High Priority items (3 files)
2. Refactor Medium Priority items (3 files)
3. Review BoostExpiryCron approach (add method or document)
4. Verify no regressions in behavior
