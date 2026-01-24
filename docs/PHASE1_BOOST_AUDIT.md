# Phase 1: Boost Event Fetching Logic Audit

**Date:** 2026-01-23  
**Goal:** Map all call sites that fetch/query boosted events to identify inconsistencies and define canonical API.

---

## Summary of Issues Found

### Critical Issue: VendorBoostController Shows "No events yet"
- **Location:** `web/modules/custom/myeventlane_vendor/src/Controller/VendorBoostController.php`
- **Problem:** Line 50-51 has `// getBoostableEvents() doesn't exist yet - return empty array.`
- **Impact:** The Boost page always shows empty `$events = []`, causing "No events yet" even when vendor has events.
- **Root Cause:** No method exists to fetch vendor's events filtered by store ownership.

### Inconsistencies Found

1. **Store vs Author Ownership**
   - Some queries filter by `uid` (author)
   - Some should filter by `field_event_store` (store ownership)
   - VendorBoostController needs store-based filtering

2. **Time Window Logic**
   - Some use `field_promo_expires > now` (correct)
   - Some check `field_promoted = 1` only (ignores expiry)
   - Date format inconsistencies: ISO strings vs timestamps

3. **Access Checks**
   - Some use `accessCheck(TRUE)` (respects node access)
   - Some use `accessCheck(FALSE)` (bypasses access - used in blocks/cron)

4. **Published Status**
   - Most check `status = 1` (published)
   - Some don't check at all (cron jobs)

---

## Call Sites Inventory

### 1. VendorBoostController (CRITICAL - BROKEN)
**File:** `web/modules/custom/myeventlane_vendor/src/Controller/VendorBoostController.php`  
**Method:** `boost()`  
**Lines:** 28-60

**Current Implementation:**
- Returns empty `$events = []` array
- Only populates `$campaigns` if route has `node` parameter (single event)
- No method to fetch all vendor events for Boost page

**Inputs Used:**
- Route parameter `node` (optional)
- `BoostStatusService::getBoostStatuses()` for single event

**Issues:**
- ❌ No store-based event fetching
- ❌ Always returns empty events list
- ❌ Template expects `events` array but gets empty

**What It Should Do:**
- Fetch all events for vendor's store (via `field_event_store`)
- Filter by published status
- Include boost status for each event
- Show active boosts in `campaigns`, all events in `events`

---

### 2. BoostManager::isBoosted()
**File:** `web/modules/custom/myeventlane_boost/src/BoostManager.php`  
**Method:** `isBoosted(NodeInterface $event)`  
**Lines:** 106-129

**Current Implementation:**
- Checks `field_promoted = 1`
- Checks `field_promo_expires > now` (UTC DateTimeImmutable)
- Returns boolean

**Inputs Used:**
- Event node (already loaded)
- Time service

**Issues:**
- ✅ Correct logic (checks expiry)
- ✅ Uses proper DateTime handling
- ⚠️ Only works on already-loaded nodes (not for queries)

**Status:** ✅ GOOD - This is the canonical single-event check

---

### 3. BoostCtaBlock
**File:** `web/modules/custom/myeventlane_boost/src/Plugin/Block/BoostCtaBlock.php`  
**Method:** `build()`  
**Lines:** 77-128

**Current Implementation:**
- Gets event from route parameter
- Checks `field_promoted` and `field_promo_expires` manually
- Compares expiry timestamp to request time

**Inputs Used:**
- Route match (node parameter)
- Current user
- Time service

**Issues:**
- ❌ Duplicates boost check logic (should use BoostManager)
- ⚠️ Manual date parsing (works but inconsistent)
- ✅ Correct cache metadata (user, route, node tags)

**Should Use:**
- `BoostManager::isBoosted($node)` instead of manual check

---

### 4. BoostStatsBlock
**File:** `web/modules/custom/myeventlane_boost/src/Plugin/Block/BoostStatsBlock.php`  
**Method:** `build()`  
**Lines:** 69-107

**Current Implementation:**
- Direct entity query:
  - `type = 'event'`
  - `status = 1`
  - `field_promoted = 1`
  - `field_promo_expires > now` (ISO string)
- Counts active and expiring boosts

**Inputs Used:**
- Entity type manager
- Time service
- No store/user filtering (global stats)

**Issues:**
- ❌ Direct query (should use canonical API)
- ⚠️ `accessCheck(FALSE)` - bypasses node access (acceptable for stats block)
- ⚠️ Date format: ISO string comparison (works but should be consistent)
- ✅ Cache metadata present (max-age 300, tags)

**Should Use:**
- Canonical API method (e.g., `getActiveBoostedEventIdsForStore()` with no store = all stores)

---

### 5. BoostExpiryCron
**File:** `web/modules/custom/myeventlane_boost/src/Cron/BoostExpiryCron.php`  
**Method:** `process()`  
**Lines:** 54-91

**Current Implementation:**
- Direct entity query:
  - `type = 'event'`
  - `field_promoted = 1`
  - `field_promo_expires <= now` (ISO string)
- Updates nodes: sets `field_promoted = 0`, `field_promo_expires = NULL`

**Inputs Used:**
- Entity type manager
- Time service
- No filtering (processes all expired)

**Issues:**
- ⚠️ Direct query (acceptable for cron, but should use service method for consistency)
- ⚠️ `accessCheck(FALSE)` - correct for cron
- ✅ Correct logic (finds expired, revokes boost)

**Status:** ⚠️ ACCEPTABLE - Cron can use direct query, but should call service method

---

### 6. BoostReminderScheduler
**File:** `web/modules/custom/myeventlane_messaging/src/Scheduler/BoostReminderScheduler.php`  
**Method:** `scan()`  
**Lines:** 33-84

**Current Implementation:**
- Direct entity query:
  - `type = 'event'`
  - `status = 1`
  - `field_promoted = 1`
  - `field_promo_expires > now` (ISO string)
  - `field_promo_expires <= now + 24h` (ISO string)
- Loads nodes and queues reminder emails

**Inputs Used:**
- Entity type manager
- Time service
- No store/user filtering

**Issues:**
- ❌ Direct query (should use canonical API)
- ⚠️ `accessCheck(FALSE)` - correct for cron
- ⚠️ Date format: ISO string comparison

**Should Use:**
- Canonical API with time window option

---

### 7. VendorDashboardController::getBoostStatus()
**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`  
**Method:** `getBoostStatus(NodeInterface $event)`  
**Lines:** ~596-642 (not fully read, inferred from search)

**Current Implementation:**
- Likely checks `field_promoted` and `field_promo_expires` manually
- Used in dashboard event listing

**Inputs Used:**
- Event node (already loaded)
- Possibly time service

**Issues:**
- ❌ Likely duplicates boost check logic
- Should use `BoostManager::isBoosted()` or canonical API

---

### 8. BoostStatusService::getBoostStatuses()
**File:** `web/modules/custom/myeventlane_vendor/src/Service/BoostStatusService.php`  
**Method:** `getBoostStatuses(int $event_nid)`  
**Lines:** 40-116

**Current Implementation:**
- Loads event by ID
- Checks published status
- Checks `field_promoted` and `field_promo_expires` manually
- Returns structured array: `eligible`, `active`, `reason`, `types`, `expires`

**Inputs Used:**
- Event node ID
- Entity type manager
- Time service

**Issues:**
- ❌ Duplicates boost check logic (should use BoostManager)
- ✅ Returns structured data (good for API)
- ⚠️ Only works for single event (not for lists)

**Should Use:**
- `BoostManager::isBoosted()` for active check
- Or canonical API `getBoostStatusForEvent()`

---

### 9. CategoryDigestGenerator
**File:** `web/modules/custom/myeventlane_core/src/Service/CategoryDigestGenerator.php`  
**Method:** (in event processing loop)  
**Lines:** 138-149

**Current Implementation:**
- Manual check: `field_promoted = 1` and `field_promo_expires > now` (strtotime)
- Sets `is_boosted` flag in event data array

**Inputs Used:**
- Event node (already loaded)
- Current time (now)

**Issues:**
- ❌ Duplicates boost check logic
- ⚠️ Uses `strtotime()` instead of DateTimeImmutable
- Should use `BoostManager::isBoosted()`

---

### 10. MyCategoriesController
**File:** `web/modules/custom/myeventlane_core/src/Controller/MyCategoriesController.php`  
**Method:** (in event query)  
**Lines:** 96, 125

**Current Implementation:**
- Query sorts by `field_promoted DESC` (boosted first)
- Sets `is_boosted` flag: `field_promoted = 1` (no expiry check!)

**Inputs Used:**
- Entity query
- Event nodes (loaded)

**Issues:**
- ❌ **CRITICAL:** Only checks `field_promoted`, ignores `field_promo_expires`
- ❌ Shows expired boosts as "boosted"
- Should use canonical API or `BoostManager::isBoosted()`

---

### 11. Views: featured_events
**File:** `_myeventlane_audit/config-sync/views.view.featured_events.yml`  
**Status:** Config file (not code)

**Current Implementation:**
- Likely filters by `field_promoted = 1`
- May or may not check expiry date

**Issues:**
- ⚠️ Views config - need to verify filter criteria
- Should use canonical query or expose service method to Views

---

### 12. VendorDashboardController (dashboard listing)
**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`  
**Method:** `dashboard()`  
**Lines:** 287-321

**Current Implementation:**
- Calls `getBoostStatus($event)` for each event
- Used in dashboard event table

**Inputs Used:**
- Events loaded via `eventLoader->loadEvents()`
- Per-event boost status check

**Issues:**
- ⚠️ Calls per-event method (could batch via canonical API)
- Should verify `getBoostStatus()` uses canonical logic

---

## Field Usage Summary

### Fields Used
- `field_promoted` (boolean): Whether event is promoted/boosted
- `field_promo_expires` (datetime): Expiration timestamp (ISO format: `Y-m-d\TH:i:s`)

### Field Access Patterns
1. **Direct field access:** `$event->get('field_promoted')->value`
2. **Entity query:** `->condition('field_promoted', 1)`
3. **Date comparison:** Mixed (ISO strings vs timestamps vs DateTimeImmutable)

---

## Store Ownership Patterns

### Current Store Resolution
- **VendorDashboardController:** Uses `eventLoader->loadEvents()` (likely filters by user/owner)
- **VendorBoostController:** ❌ **NO STORE RESOLUTION** - this is the bug!
- **BoostManager:** No store awareness (works on single events)

### Expected Store Resolution
- Events should be filtered by `field_event_store` matching vendor's store
- Store can be resolved from:
  - User's store (via `commerce_store` with `uid = user_id`)
  - Or via vendor entity → store relationship

---

## Time Window Logic Inconsistencies

| Call Site | Checks `field_promoted` | Checks `field_promo_expires` | Date Format | Status |
|-----------|------------------------|------------------------------|-------------|--------|
| BoostManager::isBoosted() | ✅ | ✅ | DateTimeImmutable | ✅ CORRECT |
| BoostCtaBlock | ✅ | ✅ | Timestamp | ⚠️ Works but inconsistent |
| BoostStatsBlock | ✅ | ✅ | ISO string | ⚠️ Works but inconsistent |
| BoostExpiryCron | ✅ | ✅ (<=) | ISO string | ✅ CORRECT |
| BoostReminderScheduler | ✅ | ✅ | ISO string | ✅ CORRECT |
| BoostStatusService | ✅ | ✅ | DateTimeImmutable | ✅ CORRECT |
| CategoryDigestGenerator | ✅ | ✅ | strtotime | ⚠️ Works but inconsistent |
| MyCategoriesController | ✅ | ❌ **MISSING** | N/A | ❌ **BUG** |
| VendorBoostController | N/A | N/A | N/A | ❌ **NO QUERY** |

---

## Access Check Patterns

| Call Site | accessCheck() | Reason |
|-----------|---------------|--------|
| BoostStatsBlock | `FALSE` | Global stats block (acceptable) |
| BoostExpiryCron | `FALSE` | Cron job (correct) |
| BoostReminderScheduler | `FALSE` | Cron job (correct) |
| MyCategoriesController | `TRUE` | User-facing (correct) |
| VendorBoostController | N/A | No query yet |

---

## Recommendations for Phase 2

1. **Extend BoostManager** (don't create new service) with:
   - `getActiveBoostedEventIdsForStore(StoreInterface $store, array $options = []): array`
   - `getActiveBoostedEventsForStore(StoreInterface $store, array $options = []): array`
   - `getBoostStatusForEvent(NodeInterface $event): BoostStatus` (value object)

2. **Store Resolution Helper:**
   - Add method to resolve store from user/vendor
   - Reuse existing vendor/store resolution logic

3. **Options Support:**
   - `include_scheduled` (default false)
   - `include_expired` (default false)
   - `limit` (default null)
   - `now` (injectable for tests)

4. **Ordering:**
   - Default: `field_promo_expires ASC` (expiring soon first)
   - Option: `field_promo_expires DESC` or `field_promo_start DESC` (if field exists)

---

## Next Steps

1. ✅ Phase 1 Complete: Audit documented
2. → Phase 2: Implement canonical API in BoostManager
3. → Phase 3: Refactor all call sites
4. → Phase 4: Add caching
5. → Phase 5: Tests
6. → Phase 6: Deliverables
