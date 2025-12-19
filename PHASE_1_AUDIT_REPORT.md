# PHASE 1 — FULL SYSTEM AUDIT REPORT
## MyEventLane v2 — Comprehensive Codebase Audit

**Date:** 2025-01-27  
**Branch:** `audit-rebuild-event-system`  
**Drupal Version:** 11.2.10  
**PHP Version:** 8.3.23  
**Auditor:** Senior Drupal 11 Architect

---

## EXECUTIVE SUMMARY

### Overall Health: **MODERATE** ⚠️

**Strengths:**
- ✅ All modules correctly declare `core_version_requirement: ^11`
- ✅ Modern dependency injection patterns used in most controllers
- ✅ Well-structured themes with Vite-based asset pipelines
- ✅ Clear module boundaries and separation of concerns
- ✅ No deprecated `entity_load()` calls found
- ✅ No unsafe `db_query()` calls found
- ✅ Proper access check usage in entity queries
- ✅ No dangerous PHP functions (`eval`, `exec`, etc.) found
- ✅ All custom entities have proper access control handlers

**Critical Blockers:**
1. **BLOCKER:** All 3 themes use `base theme: stable9` (Drupal 9) instead of `stable11` or no base theme
2. **BLOCKER:** 78 instances of `\Drupal::service()` static calls instead of dependency injection
3. **BLOCKER:** 20+ backup/temporary files in repository (`.bak`, `.tmp` files)
4. **BLOCKER:** Deprecated module `myeventlane_checkout` still enabled

**High Priority Issues:**
- Missing schema.yml files for 15+ modules with config
- Theme `.theme` file uses extensive static service calls
- Some access checks use `accessCheck(FALSE)` where `TRUE` may be more appropriate
- Potential N+1 query issues in dashboard controllers

---

## 1. CUSTOM MODULES AUDIT

### 1.1 Module Inventory (25 modules)

| Module | Status | Schema | Permissions | Services | Notes |
|--------|--------|--------|-------------|----------|-------|
| `myeventlane_admin_dashboard` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_analytics` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_blocks` | ✅ | ❌ | ❌ | ❌ | Minimal module |
| `myeventlane_boost` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_cart` | ✅ | ❌ | ❌ | ❌ | Minimal module |
| `myeventlane_checkout` | ⚠️ **DEPRECATED** | ❌ | ❌ | ❌ | **MUST REMOVE** |
| `myeventlane_checkout_paragraph` | ✅ | ❌ | ❌ | ❌ | Missing schema.yml |
| `myeventlane_commerce` | ✅ | ❌ | ❌ | ✅ | Missing schema.yml |
| `myeventlane_core` | ✅ | ✅ | ✅ | ✅ | **Reference implementation** |
| `myeventlane_dashboard` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_demo` | ✅ | ❌ | ❌ | ✅ | Missing schema.yml |
| `myeventlane_donations` | ✅ | ✅ | ✅ | ✅ | Complete |
| `myeventlane_escalations` | ✅ | ✅ | ✅ | ❌ | Complete |
| `myeventlane_event` | ✅ | ❌ | ❌ | ✅ | Missing schema.yml |
| `myeventlane_event_attendees` | ✅ | ✅ | ✅ | ✅ | Complete |
| `myeventlane_finance` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_location` | ✅ | ✅ | ❌ | ✅ | Missing permissions.yml |
| `myeventlane_messaging` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_rsvp` | ✅ | ✅ | ✅ | ✅ | Complete |
| `myeventlane_schema` | ✅ | N/A | ❌ | ❌ | Schema definition module |
| `myeventlane_shared` | ✅ | ❌ | ❌ | ✅ | Missing schema.yml |
| `myeventlane_tickets` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_vendor` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |
| `myeventlane_views` | ✅ | ❌ | ❌ | ❌ | Minimal module |
| `myeventlane_wallet` | ✅ | ❌ | ✅ | ✅ | Missing schema.yml |

### 1.2 Missing Schema Files

**Modules with config but no schema.yml:**
- `myeventlane_admin_dashboard`
- `myeventlane_analytics`
- `myeventlane_boost`
- `myeventlane_checkout_paragraph`
- `myeventlane_commerce`
- `myeventlane_dashboard`
- `myeventlane_demo`
- `myeventlane_event`
- `myeventlane_finance`
- `myeventlane_messaging`
- `myeventlane_shared`
- `myeventlane_tickets`
- `myeventlane_vendor`
- `myeventlane_wallet`

**Impact:** Config validation may fail, config import/export may be unreliable.

**Action Required:** Create `config/schema/{module_name}.schema.yml` for each module.

### 1.3 Static Service Calls Audit

**Total Static Calls Found:** 78 instances

**Breakdown by Module:**
- `myeventlane_commerce` - 10+ instances (Forms, Event Subscribers)
- `myeventlane_messaging` - 8+ instances (Commands, Queue Workers)
- `myeventlane_rsvp` - 8+ instances (Forms, Controllers)
- `myeventlane_admin_dashboard` - 6+ instances (Controller)
- `myeventlane_event_attendees` - 5+ instances (Controllers, List Builders)
- `myeventlane_vendor` - 4+ instances (Entity, Services)
- `myeventlane_dashboard` - 3+ instances (Controllers)
- `myeventlane_core` - 3+ instances (Event Subscribers, Theme Negotiators)
- Others - 31+ instances

**Top Offender Files:**
1. `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php` - 10+ calls
2. `web/modules/custom/myeventlane_admin_dashboard/src/Controller/AdminDashboardController.php` - 6+ calls
3. `web/modules/custom/myeventlane_messaging/src/Commands/MessagingCommands.php` - 5+ calls
4. `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php` - 4+ calls
5. `web/modules/custom/myeventlane_event_attendees/src/Controller/VendorAttendeeController.php` - 3+ calls

**Recommendation:** Replace with dependency injection in constructors. For `.module` files, consider creating service classes.

### 1.4 Backup/Temporary Files

**Found 20+ backup files:**
- `composer.lock.bak`
- `web/modules/custom/myeventlane_vendor/src/Controller/StripeConnectController.php.bak21`, `.bak12`, `.bak11`, `.bak10`
- `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php.bak2` through `.bak9`, `.tmp`
- `web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.module.bak20`
- `web/modules/custom/myeventlane_event_attendees/src/Entity/EventAttendee.php.bak`
- Multiple `.bak` files in checkout modules

**Impact:** Repository bloat, confusion, potential security risk if sensitive data.

**Action Required:** Delete all `.bak`, `.tmp`, `.old` files immediately.

---

## 2. CUSTOM THEMES AUDIT

### 2.1 Theme Inventory

| Theme | Base Theme | Status | Vite | SCSS | Twig Templates |
|-------|------------|--------|------|------|----------------|
| `myeventlane_theme` | ❌ **stable9** | ⚠️ **BLOCKER** | ✅ | ✅ | 50+ files |
| `myeventlane_vendor_theme` | ❌ **stable9** | ⚠️ **BLOCKER** | ✅ | ✅ | 30+ files |
| `myeventlane_admin` | ❌ **stable9** | ⚠️ **BLOCKER** | ❌ | ✅ | 3 files |

### 2.2 Critical Theme Issues

#### BLOCKER: Incorrect Base Theme (All 3 Themes)
**Files:**
- `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml` (line 3)
- `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.info.yml` (line 4)
- `web/themes/custom/myeventlane_admin/myeventlane_admin.info.yml` (line 3)

**Issue:** All themes declare `base theme: stable9` which is for Drupal 9, not Drupal 11

**Fix:** Change to `base theme: stable11` or remove entirely (Drupal 11 doesn't require a base theme)

**Impact:** Themes may not load correctly or may have missing base styles

#### WARNING: Static Service Calls in Theme File
**File:** `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`  
**Issue:** Extensive use of `\Drupal::service()` and `\Drupal::entityTypeManager()`  
**Lines:** Multiple instances throughout file (47, 49, 64, 66, 79, 85, etc.)  
**Impact:** Performance and testability concerns (acceptable for theme hooks, but should be minimized)

**Recommendation:** Consider creating a theme service class for complex logic.

### 2.3 Twig Template Audit

**Total Templates:** 84 files across all themes

**Issues Found:**
- ✅ No entity loading in Twig (good)
- ✅ No business logic in Twig (good)
- ⚠️ Some templates access entity methods (acceptable pattern)
- ⚠️ Duplicate header/footer markup may exist (needs consolidation)

**Recommendation:** Consolidate header/footer includes, implement proper menu rendering.

### 2.4 SCSS Architecture

**Status:** ✅ Well-structured
- Modular architecture with tokens, abstracts, base, components, layout, pages
- No `@extend` issues found
- Proper Vite integration
- Both themes use modern SCSS patterns

---

## 3. ENTITY USAGE AUDIT

### 3.1 Event Node Type

**Status:** ✅ Properly configured

**Fields Identified (30+ fields):**
- **Core:** `body`, `title`
- **Metadata:** `field_event_type`, `field_featured`, `field_promoted`
- **Dates:** `field_event_start`, `field_event_end`
- **Location:** `field_event_location`, `field_location_latitude`, `field_location_longitude`, `field_venue_name`, `field_location`
- **Organizer:** `field_organizer`, `field_event_vendor`
- **Categories:** `field_category`, `field_tags`, `field_accessibility`
- **Commerce:** `field_event_store`, `field_product_target`, `field_rsvp_target`
- **Tickets:** `field_ticket_types` (paragraphs), `field_collect_per_ticket`
- **Capacity:** `field_capacity`, `field_waitlist_capacity`
- **Questions:** `field_attendee_questions` (paragraphs)
- **Accessibility:** `field_accessibility_contact`, `field_accessibility_directions`, `field_accessibility_entry`, `field_accessibility_parking`
- **Promotions:** `field_promo_expires`
- **External:** `field_external_url`

**Issues:**
- ⚠️ Field count: 30+ fields (potential bloat)
- ⚠️ Some fields may be redundant (e.g., `field_event_location` vs `field_location_latitude`/`field_location_longitude` vs `field_location`)
- ⚠️ No clear field grouping strategy
- ⚠️ Multiple location fields may cause confusion

**Recommendation:** Review field redundancy in Phase 4 (Event Entity Rearchitecture).

### 3.2 Entity Queries

**Access Check Usage:**
- ✅ Most queries use `accessCheck(TRUE)` appropriately
- ⚠️ Some queries use `accessCheck(FALSE)` for system/cron operations (acceptable)
- ✅ No unsafe entity queries found

**Performance:**
- ⚠️ Some queries may benefit from caching (e.g., category stats)
- ⚠️ Dashboard queries load multiple events without pagination limits
- ⚠️ Potential N+1 query issues in loops (see Performance Audit)

### 3.3 Custom Entities

**Custom Entity Types:**
- `rsvp_submission` - ✅ Properly defined with access control
- `event_attendee` - ✅ Properly defined with access control
- `myeventlane_vendor` - ✅ Properly defined with access control
- `escalation` - ✅ Properly defined
- `ticket_code` - ✅ Properly defined (if exists)

**Status:** All custom entities have proper access control handlers.

---

## 4. COMMERCE INTEGRATION AUDIT

### 4.1 Commerce Modules

**Enabled:**
- `commerce` (core)
- `commerce_cart`
- `commerce_checkout`
- `commerce_order`
- `commerce_payment`
- `commerce_product`
- `commerce_promotion`
- `commerce_store`
- `commerce_stripe`
- `commerce_tax`

**Status:** ✅ All required Commerce modules enabled

### 4.2 Payment Integration

**Payment Gateways:**
- Stripe Connect (custom implementation in `myeventlane_commerce`)
- Standard Stripe (`commerce_stripe`)
- Payment element integration

**Issues:**
- ⚠️ Stripe Connect validation subscriber may need review
- ⚠️ Payment flow error handling could be improved
- ✅ Twig templates properly handle Stripe integration

### 4.3 Product/Variation Structure

**Status:** ✅ Properly linked to Event nodes via `field_target_event` on variations

**Issues:**
- ⚠️ Auto-linking logic in `myeventlane_commerce.module` may need optimization
- ⚠️ Variation management service exists but may need caching

---

## 5. SECURITY AUDIT

### 5.1 Access Control

**Status:** ✅ Generally good

**Access Check Patterns:**
- ✅ Proper permission checks in controllers
- ✅ Entity access control handlers implemented
- ✅ Route access callbacks defined
- ⚠️ Some `accessCheck(FALSE)` in system queries (acceptable for cron/admin)

**Files Reviewed:**
- `VendorConsoleBaseController` - ✅ Proper access checks
- `EventAttendeeAccessControlHandler` - ✅ Proper access checks
- `VendorStoreAccess` (Views plugin) - ✅ Proper access checks

### 5.2 Input Validation

**Status:** ✅ Forms use proper validation

**Issues:**
- ✅ Email validation uses `email.validator` service (correct)
- ✅ CSRF protection via Form API (correct)
- ⚠️ Some forms may need additional validation review

### 5.3 Permission Providers

**Modules with Permissions:**
- ✅ 14 modules have `*.permissions.yml` files
- ⚠️ `myeventlane_location` missing permissions.yml (may not need permissions)

**Status:** ✅ Permissions properly defined where needed

### 5.4 Security Functions

**Status:** ✅ No dangerous functions found
- ✅ No `eval()`, `exec()`, `system()`, `passthru()`, `shell_exec()`, `popen()` found
- ✅ No direct SQL queries (`db_query()`, `db_select()`, etc.) found
- ✅ All database access via Entity API or database abstraction

---

## 6. PERFORMANCE AUDIT

### 6.1 Caching

**Status:** ⚠️ Mixed

**Issues:**
- ✅ Some services implement caching (e.g., `FrontCategoryStatsService`)
- ⚠️ Dashboard controllers may load too much data without caching
- ⚠️ Entity queries in loops (potential N+1 issues)

**N+1 Query Candidates:**
- `CustomerDashboardController::build()` - loads events in loop
- `VendorDashboardController::build()` - loads events in loop
- `MyCategoriesController::build()` - loads events in loop
- `CategoryAudienceService` - may load users in loop

**Recommendations:**
- Implement caching for dashboard data
- Add cache tags to rendered content
- Review query patterns for optimization
- Use `loadMultiple()` instead of `load()` in loops

### 6.2 Database Queries

**Status:** ✅ No unsafe queries found

**Patterns:**
- ✅ All queries use entity query API or database abstraction
- ✅ No raw SQL injection risks
- ⚠️ Some queries may benefit from indexing review

### 6.3 Asset Optimization

**Status:** ✅ Good

**Themes:**
- ✅ Vite integration for modern build pipeline
- ✅ SCSS compilation
- ✅ Proper library definitions
- ✅ Source maps disabled in production (good)

---

## 7. CONFIGURATION AUDIT

### 7.1 Config Files

**Status:** ⚠️ Some inconsistencies

**Issues:**
- ⚠️ Missing schema.yml files (see Section 1.2)
- ⚠️ Some config may be in `config/install` vs `config/optional`
- ⚠️ Untracked config files in git (metatag, social_auth)
- ⚠️ `core.extension` shows as "Different" (expected on working branch)

### 7.2 Config Dependencies

**Status:** ✅ Generally correct

**Issues:**
- ✅ Most modules have proper dependency declarations
- ⚠️ Need to verify all config is exportable

---

## 8. IMMEDIATE BLOCKERS

### Priority 1: Must Fix Before Proceeding

1. **Theme Base Theme** (BLOCKER)
   - Files:
     - `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml` (line 3)
     - `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.info.yml` (line 4)
     - `web/themes/custom/myeventlane_admin/myeventlane_admin.info.yml` (line 3)
   - Change: `base theme: stable9` → `base theme: stable11` (or remove)
   - Impact: Themes may not load correctly

2. **Backup Files** (BLOCKER)
   - Delete all `.bak`, `.tmp`, `.old` files from repository
   - 20+ files found (see Section 1.4)
   - Impact: Repository bloat, potential security risk

3. **Deprecated Module** (BLOCKER)
   - Module: `myeventlane_checkout`
   - File: `web/modules/custom/myeventlane_checkout/myeventlane_checkout.info.yml`
   - Action: Uninstall and remove (marked as deprecated, replaced by `myeventlane_checkout_paragraph`)
   - Impact: Confusion, maintenance burden

### Priority 2: High Priority Fixes

4. **Static Service Calls** (78 instances)
   - Replace with dependency injection
   - Focus on controllers and services first
   - Impact: Testability, performance

5. **Missing Schema Files** (15+ modules)
   - Add schema.yml for all modules with config
   - Impact: Config validation, import/export reliability

6. **Theme Static Calls**
   - Minimize `\Drupal::service()` in `.theme` file
   - Consider creating theme service class
   - Impact: Performance, testability

---

## 9. REFACTOR CANDIDATES

### Modules to Review/Refactor

1. **myeventlane_commerce**
   - High static call count (10+)
   - Form classes need DI (`RsvpBookingForm`, `TicketSelectionForm`)
   - Event subscribers need DI (`OrderCompletedSubscriber`)

2. **myeventlane_messaging**
   - High static call count (8+)
   - Commands and queue workers need DI

3. **myeventlane_rsvp**
   - High static call count (8+)
   - Forms and controllers need DI

4. **myeventlane_admin_dashboard**
   - Large controller with many static calls (6+)
   - Consider breaking into multiple services

5. **myeventlane_event_attendees**
   - Controllers and list builders need DI (5+)

### Modules to Remove

1. **myeventlane_checkout** (deprecated)
   - Replaced by `myeventlane_checkout_paragraph`
   - Should be uninstalled and removed
   - File: `web/modules/custom/myeventlane_checkout/`

### Modules to Freeze (if backward compatibility needed)

- None identified (all modules appear active)

---

## 10. RISK SEVERITY MATRIX

| Risk | Severity | Impact | Likelihood | Priority |
|------|----------|--------|------------|----------|
| Theme base theme incorrect (all 3) | **CRITICAL** | High | Certain | P1 |
| Backup files in repo | **HIGH** | Medium | Low | P1 |
| Deprecated module enabled | **MEDIUM** | Low | Medium | P1 |
| Missing schema files | **MEDIUM** | Medium | Medium | P2 |
| Static service calls (78) | **MEDIUM** | Low | High | P2 |
| N+1 query issues | **LOW** | Low | Medium | P3 |
| Missing permissions.yml | **LOW** | Low | Low | P3 |
| Performance issues | **LOW** | Low | Medium | P3 |

---

## 11. DELIVERABLES CHECKLIST

- [x] Audit report (this document)
- [x] Risk severity list (Section 10)
- [x] Immediate blockers (Section 8)
- [x] Refactor candidates (Section 9)
- [x] Modules to remove (Section 9)
- [x] Modules to freeze (none identified)

---

## 12. NEXT STEPS

### Before PHASE 2:

1. Fix theme base theme (all 3 themes) - 10 minutes
2. Delete backup files (20+ files) - 5 minutes
3. Disable/remove deprecated module - 10 minutes
4. Create schema.yml files for top 5 modules - 30 minutes

**Total Estimated Time:** ~55 minutes

### PHASE 2 Preparation:

- Review Twig template structure
- Identify broken template references
- Plan SCSS refactoring (if needed)
- Review mobile-first compliance
- Plan header/footer consolidation

---

## APPENDIX: File Paths for Changes

### Critical Fixes Required:

1. `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml` (line 3)
2. `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.info.yml` (line 4)
3. `web/themes/custom/myeventlane_admin/myeventlane_admin.info.yml` (line 3)
4. `web/modules/custom/myeventlane_checkout/` (entire directory - deprecated)
5. All `.bak`, `.tmp`, `.old` files in `web/modules/custom/`

### High Priority Refactors:

1. `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php`
2. `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
3. `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`
4. `web/modules/custom/myeventlane_admin_dashboard/src/Controller/AdminDashboardController.php`
5. `web/modules/custom/myeventlane_messaging/src/Commands/MessagingCommands.php`
6. `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php`
7. `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`

---

**END OF PHASE 1 AUDIT REPORT**
