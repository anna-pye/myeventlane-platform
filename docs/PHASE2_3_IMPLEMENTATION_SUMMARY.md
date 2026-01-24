# Phase 2 & 3: Canonical Boost API Implementation Summary

**Date:** 2026-01-23  
**Status:** ✅ Phase 2 Complete, Phase 3 In Progress

---

## Phase 2: Canonical API Implementation ✅

### BoostManager Extensions

**File:** `web/modules/custom/myeventlane_boost/src/BoostManager.php`

**New Methods Added:**

1. **`getActiveBoostedEventIdsForStore(?StoreInterface $store, array $options = []): array`**
   - Canonical method for querying boosted event IDs by store
   - Options: `include_scheduled`, `include_expired`, `limit`, `now`, `access_check`
   - Filters: `type = 'event'`, `status = 1`, `field_promoted = 1`, `field_promo_expires > now`
   - Orders by `field_promo_expires ASC` (expiring soon first)
   - Returns array of integer node IDs

2. **`getActiveBoostedEventsForStore(?StoreInterface $store, array $options = []): array`**
   - Loads event nodes using `getActiveBoostedEventIdsForStore()`
   - Returns `NodeInterface[]` keyed by node ID
   - Filters to ensure only event nodes are returned

3. **`getBoostStatusForEvent(NodeInterface $event, ?int $now = NULL): array`**
   - Canonical method for single-event boost status
   - Returns structured array: `active`, `scheduled`, `expired`, `start_timestamp`, `end_timestamp`, `boost_product_id`, `source_order_id`
   - Uses proper DateTimeImmutable handling
   - Handles invalid dates gracefully

4. **`getEventsForStore(StoreInterface $store, array $options = []): array`**
   - Helper to fetch all events for a store (boosted or not)
   - Used by VendorBoostController to show all vendor events
   - Options: `published_only`, `limit`, `access_check`
   - Orders by `created DESC` (newest first)

5. **`getExpiringBoostedEventIdsForStore(?StoreInterface $store, int $seconds, array $options = []): array`**
   - Gets boosts expiring within a time window (e.g., 48 hours)
   - Used by BoostStatsBlock for "expiring soon" count

**Key Features:**
- ✅ Store-based filtering via `field_event_store`
- ✅ Time window filtering (active, expired, scheduled)
- ✅ Access check control (for blocks/cron vs user-facing)
- ✅ Consistent DateTimeImmutable usage
- ✅ Proper error handling and logging

---

## Phase 3: Call Site Refactoring (In Progress)

### ✅ Completed Refactors

#### 1. VendorBoostController (CRITICAL FIX)
**File:** `web/modules/custom/myeventlane_vendor/src/Controller/VendorBoostController.php`

**Changes:**
- ✅ Injects `BoostManager` and `EntityTypeManager`
- ✅ Resolves store via `getCurrentUserStore()` (vendor → store → fallback to uid)
- ✅ Uses `getEventsForStore()` to fetch all vendor events
- ✅ Uses `getBoostStatusForEvent()` for each event
- ✅ Uses `getActiveBoostedEventIdsForStore()` for active boosts
- ✅ Builds `$campaigns` (active boosts) and `$events` (all events with status)
- ✅ Handles `no_store` case (shows empty state)
- ✅ Debug logging when events list is empty (non-prod only)

**Services Updated:**
- ✅ `myeventlane_vendor.services.yml`: Updated VendorBoostController arguments

**Result:** Fixes "No events yet" bug - now correctly shows vendor events.

---

#### 2. BoostCtaBlock
**File:** `web/modules/custom/myeventlane_boost/src/Plugin/Block/BoostCtaBlock.php`

**Changes:**
- ✅ Removed manual boost check logic
- ✅ Uses `BoostManager::getBoostStatusForEvent()` instead
- ✅ Injects `BoostManager` service
- ✅ Cache metadata unchanged (correct)

**Result:** Uses canonical API, no duplicate logic.

---

#### 3. BoostStatsBlock
**File:** `web/modules/custom/myeventlane_boost/src/Plugin/Block/BoostStatsBlock.php`

**Changes:**
- ✅ Removed direct entity query
- ✅ Uses `BoostManager::getActiveBoostedEventIdsForStore()` for active count
- ✅ Uses `BoostManager::getExpiringBoostedEventIdsForStore()` for expiring count
- ✅ Injects `BoostManager` service
- ✅ Cache metadata updated: added `myeventlane_boost:stats` tag

**Result:** Uses canonical API, proper cache tags.

---

### ⏳ Remaining Refactors (Phase 3 Continued)

#### 4. BoostStatusService
**File:** `web/modules/custom/myeventlane_vendor/src/Service/BoostStatusService.php`

**Current:** Duplicates boost check logic  
**Should:** Use `BoostManager::getBoostStatusForEvent()` or `isBoosted()`

**Action Required:**
- Refactor `getBoostStatuses()` to call `BoostManager::getBoostStatusForEvent()`
- Map BoostManager return array to BoostStatusService format (for backward compatibility)

---

#### 5. VendorDashboardController::getBoostStatus()
**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`

**Current:** Likely duplicates boost check  
**Should:** Use `BoostManager::isBoosted()` or `getBoostStatusForEvent()`

**Action Required:**
- Find `getBoostStatus()` method
- Replace with `BoostManager::isBoosted($event)` or `getBoostStatusForEvent($event)`
- Inject `BoostManager` service

---

#### 6. CategoryDigestGenerator
**File:** `web/modules/custom/myeventlane_core/src/Service/CategoryDigestGenerator.php`

**Current:** Manual boost check with `strtotime()`  
**Should:** Use `BoostManager::isBoosted()`

**Action Required:**
- Inject `BoostManager`
- Replace manual check with `$this->boostManager->isBoosted($event)`

---

#### 7. MyCategoriesController
**File:** `web/modules/custom/myeventlane_core/src/Controller/MyCategoriesController.php`

**Current:** ❌ **CRITICAL BUG** - Only checks `field_promoted`, ignores `field_promo_expires`  
**Should:** Use `BoostManager::isBoosted()` or filter query properly

**Action Required:**
- Inject `BoostManager`
- After loading events, filter `is_boosted` using `BoostManager::isBoosted()`
- OR: Update query to include expiry check (but BoostManager is preferred)

---

#### 8. BoostReminderScheduler
**File:** `web/modules/custom/myeventlane_messaging/src/Scheduler/BoostReminderScheduler.php`

**Current:** Direct entity query  
**Should:** Use `BoostManager::getExpiringBoostedEventIdsForStore()`

**Action Required:**
- Inject `BoostManager`
- Replace query with `getExpiringBoostedEventIdsForStore(NULL, 24 * 3600)`
- Load nodes and process

---

#### 9. BoostExpiryCron
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryCron.php`

**Current:** Direct entity query (acceptable for cron, but should use service for consistency)  
**Should:** Use `BoostManager` method (or keep as-is if performance critical)

**Action Required:**
- Option A: Keep direct query (cron can bypass service layer for performance)
- Option B: Add `getExpiredBoostedEventIds()` method to BoostManager and use it

**Recommendation:** Option B for consistency, but low priority.

---

#### 10. BoostExpiryReminderCron
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryReminderCron.php`

**Current:** Direct entity query  
**Should:** Use `BoostManager::getExpiringBoostedEventIdsForStore()`

**Action Required:**
- Inject `BoostManager`
- Replace query with `getExpiringBoostedEventIdsForStore(NULL, 24 * 3600)`

---

## Next Steps

1. ✅ Phase 2: Canonical API implemented
2. ✅ Phase 3: VendorBoostController, BoostCtaBlock, BoostStatsBlock refactored
3. ⏳ Phase 3: Continue refactoring remaining call sites (items 4-10 above)
4. ⏳ Phase 4: Add caching (max-age 300s or tag-based invalidation)
5. ⏳ Phase 5: Add/update tests
6. ⏳ Phase 6: Final deliverables and checklist

---

## Testing Checklist (After Phase 3 Complete)

- [ ] Vendor Console → Boost page shows vendor events (not "No events yet")
- [ ] Active boosts appear in "Active boosts" section
- [ ] Non-boosted events appear in "Your events" table
- [ ] Boost CTA block shows/hides correctly on event pages
- [ ] Boost stats block shows correct counts
- [ ] Category digest includes correct `is_boosted` flags
- [ ] My Categories page shows boosted events first
- [ ] Boost expiry cron still works
- [ ] Boost reminder emails still sent

---

## Cache Tags to Add (Phase 4)

- `myeventlane_boost:store:{store_id}` - Invalidated when boost expires/applied for store
- `myeventlane_boost:event:{nid}` - Invalidated when boost changes for event
- `myeventlane_boost:stats` - Invalidated when any boost changes (for stats block)

**Invalidation Points:**
- `BoostManager::applyBoost()` - Invalidate event + store tags
- `BoostManager::revokeBoost()` - Invalidate event + store tags
- `BoostExpiryCron::process()` - Invalidate event + store tags
