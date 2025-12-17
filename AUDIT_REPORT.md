# MyEventLane Full Code Audit Report
## Drupal 11 Compatibility & Architecture Review

**Date:** 2025-01-27  
**Auditor:** MyEventLane Studios – Full Audit Mode  
**Scope:** All custom modules (`myeventlane_*`) and custom theme (`myeventlane_theme`)

---

## Executive Summary

### Overall Health: **MODERATE** ⚠️

**Strengths:**
- All modules correctly declare `core_version_requirement: ^11`
- Modern dependency injection patterns used in most controllers
- Well-structured theme with Vite-based asset pipeline
- Clear module boundaries and separation of concerns

**Critical Issues:**
1. **BLOCKER:** Theme uses `base theme: stable9` (Drupal 9) instead of `stable11` or no base theme
2. **BLOCKER:** Extensive use of `\Drupal::service()` static calls instead of dependency injection (71 instances)
3. **WARNING:** Theme `.theme` file uses `\Drupal::service()` instead of dependency injection
4. **WARNING:** Some controllers access services via static calls instead of injection
5. **NICE-TO-HAVE:** SCSS import order is correct (no @extend issues found)

### Top 10 Priority Fixes

1. **Theme base theme** - Change `stable9` → `stable11` or remove
2. **Theme .theme file** - Convert to service-based approach or use proper DI
3. **VendorDashboardController** - Inject `TimeInterface` instead of `\Drupal::time()`
4. **OrderCompletedSubscriber** - Replace all `\Drupal::service()` calls with DI
5. **RsvpSubmissionSaver** - Inject `LanguageManagerInterface` instead of static calls
6. **EventAttendeeListBuilder** - Inject `DateFormatter` instead of static call
7. **TicketSelectionForm** - Inject `CurrencyFormatter` instead of static call
8. **VendorAttendeeController** - Inject `DateFormatter` instead of static call
9. **RsvpSubmissionForm** - Inject services instead of static calls
10. **VendorDigestGenerator** - Inject `LanguageManagerInterface` and use entity query services

---

## Module Inventory

### Core Infrastructure

| Module | Description | Status |
|--------|-------------|--------|
| `myeventlane_core` | Foundation services, helpers, shared utilities | ✅ Stable |
| `myeventlane_schema` | Field definitions, entity configs, content types | ✅ Stable |

### Event & Ticketing

| Module | Description | Status |
|--------|-------------|--------|
| `myeventlane_event` | Event orchestration, mode detection, CTA building | ✅ Stable |
| `myeventlane_tickets` | Ticket code generation, PDF ticket creation | ✅ Stable |
| `myeventlane_commerce` | Commerce integration, booking, ticket products | ⚠️ Needs DI fixes |
| `myeventlane_cart` | Cart form plugins for ticket holders | ✅ Stable |
| `myeventlane_checkout` | Checkout flow customizations | ✅ Stable |
| `myeventlane_checkout_paragraph` | Paragraph-based attendee info in checkout | ⚠️ Needs DI fixes |

### RSVP & Attendees

| Module | Description | Status |
|--------|-------------|--------|
| `myeventlane_rsvp` | RSVP forms, waitlist, cancellations, ICS exports | ⚠️ Needs DI fixes |
| `myeventlane_event_attendees` | Attendee entity, check-in, vendor attendee views | ⚠️ Needs DI fixes |

### Vendor & Dashboard

| Module | Description | Status |
|--------|-------------|--------|
| `myeventlane_vendor` | Vendor entity (event organisers) | ✅ Stable |
| `myeventlane_dashboard` | Vendor, customer, admin dashboards | ⚠️ Needs DI fixes |
| `myeventlane_admin_dashboard` | Admin-specific dashboard features | ✅ Stable |
| `myeventlane_views` | Custom Views plugins, CSV exports | ⚠️ Needs DI fixes |

### Additional Features

| Module | Description | Status |
|--------|-------------|--------|
| `myeventlane_location` | Location/address fields, MapKit integration | ✅ Stable |
| `myeventlane_wallet` | Apple Wallet & Google Wallet pass generation | ✅ Stable |
| `myeventlane_messaging` | Email/SMS messaging, scheduled reminders | ⚠️ Needs DI fixes |
| `myeventlane_boost` | Event promotion/boost products | ✅ Stable |
| `myeventlane_demo` | Demo data generation commands | ✅ Stable |

---

## Theme Overview

**Theme:** `myeventlane_theme`  
**Base Theme:** `stable9` ❌ **MUST CHANGE TO `stable11` OR REMOVE**  
**Asset Pipeline:** Vite (SCSS + JS)  
**Build Output:** `dist/main.css`, `dist/main.js`

### Theme Structure
- **SCSS:** Modular structure with tokens, abstracts, base, components, layout, pages, utilities
- **JS:** Drupal behaviors, header navigation, event forms
- **Templates:** Comprehensive Twig overrides for nodes, users, commerce, pages
- **Libraries:** Global styling, event-form enhancements, account dropdown

### Theme Issues
1. **BLOCKER:** `base theme: stable9` must be `stable11` or removed
2. **WARNING:** `.theme` file uses `\Drupal::service()` extensively
3. **INFO:** SCSS structure is correct, no @extend issues found

---

## Detailed Findings by Module

### myeventlane_theme

#### BLOCKER Issues

**1. Incorrect Base Theme**
- **File:** `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml`
- **Line:** 3
- **Issue:** `base theme: stable9` is for Drupal 9, not Drupal 11
- **Fix:** Change to `base theme: stable11` or remove entirely (Drupal 11 doesn't require a base theme)
- **Impact:** Theme may not load correctly or may have missing base styles

**2. Static Service Calls in Theme File**
- **File:** `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`
- **Lines:** 19, 21, 34, 36, 70, 81, 117, 147, 245, 342, 359
- **Issue:** Extensive use of `\Drupal::service()` and `\Drupal::entityTypeManager()` instead of dependency injection
- **Fix:** For theme hooks, static calls are acceptable, but should be minimized. Consider creating a theme service for complex logic.
- **Impact:** Performance and testability concerns, but not blocking

#### WARNING Issues

**3. Theme Library Paths**
- **File:** `web/themes/custom/myeventlane_theme/myeventlane_theme.libraries.yml`
- **Issue:** Library references `dist/account-dropdown.js` but Vite config outputs to `dist/account-dropdown.js` - verify this file exists
- **Impact:** Library may fail to load if file doesn't exist

---

### myeventlane_commerce

#### WARNING Issues

**1. Static Service Calls in OrderCompletedSubscriber**
- **File:** `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`
- **Lines:** 250, 255, 258, 263, 283, 289, 290, 291, 297, 298, 299, 305
- **Issue:** Multiple `\Drupal::service()` and `\Drupal::logger()` calls
- **Fix:** Inject services via constructor
- **Impact:** Testability and performance

**2. Static Service Call in TicketSelectionForm**
- **File:** `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
- **Line:** 96
- **Issue:** `\Drupal::service('commerce_price.currency_formatter')`
- **Fix:** Inject `CurrencyFormatterInterface` via constructor
- **Impact:** Testability

**3. Static Service Calls in RsvpBookingForm**
- **File:** `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php`
- **Lines:** 110, 121, 146, 147, 148
- **Issue:** Multiple `\Drupal::entityTypeManager()` and `\Drupal::service()` calls
- **Fix:** Inject services via constructor
- **Impact:** Testability

**4. Static Service Call in EventProductManager**
- **File:** `web/modules/custom/myeventlane_event/src/Service/EventProductManager.php`
- **Line:** 120
- **Issue:** `\Drupal::currentUser()->id()`
- **Fix:** Inject `AccountProxyInterface` via constructor
- **Impact:** Testability

---

### myeventlane_dashboard

#### WARNING Issues

**1. Static Service Calls in VendorDashboardController**
- **File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`
- **Lines:** 85, 86, 111
- **Issue:** `\Drupal::hasService()`, `\Drupal::service()`, `\Drupal::time()`
- **Fix:** Inject `TimeInterface` and optional services via constructor
- **Impact:** Testability

**2. Static Service Call in CustomerDashboardController**
- **File:** `web/modules/custom/myeventlane_dashboard/src/Controller/CustomerDashboardController.php`
- **Line:** 97
- **Issue:** `\Drupal::time()->getRequestTime()`
- **Fix:** Inject `TimeInterface` via constructor
- **Impact:** Testability

---

### myeventlane_rsvp

#### WARNING Issues

**1. Static Service Calls in RsvpSubmissionForm**
- **File:** `web/modules/custom/myeventlane_rsvp/src/Form/RsvpSubmissionForm.php`
- **Lines:** 90, 93, 109
- **Issue:** `\Drupal::service()` calls
- **Fix:** Inject services via constructor
- **Impact:** Testability

**2. Static Service Calls in RsvpSubmissionSaver**
- **File:** `web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionSaver.php`
- **Lines:** 69, 93
- **Issue:** `\Drupal::languageManager()->getDefaultLanguage()`
- **Fix:** Inject `LanguageManagerInterface` via constructor
- **Impact:** Testability

**3. Static Service Calls in VendorDigestGenerator**
- **File:** `web/modules/custom/myeventlane_rsvp/src/Service/VendorDigestGenerator.php`
- **Lines:** 61, 70, 87, 95, 103
- **Issue:** Multiple `\Drupal::entityQuery()` and `\Drupal::languageManager()` calls
- **Fix:** Inject `EntityTypeManagerInterface` and `LanguageManagerInterface`
- **Impact:** Testability

**4. Static Service Call in RsvpFormBlock**
- **File:** `web/modules/custom/myeventlane_rsvp/src/Plugin/Block/RsvpFormBlock.php`
- **Lines:** 17, 19
- **Issue:** `\Drupal::routeMatch()` and `\Drupal::formBuilder()`
- **Fix:** Inject `RouteMatchInterface` and `FormBuilderInterface` via constructor
- **Impact:** Testability

---

### myeventlane_event_attendees

#### WARNING Issues

**1. Static Service Call in EventAttendeeListBuilder**
- **File:** `web/modules/custom/myeventlane_event_attendees/src/EventAttendeeListBuilder.php`
- **Line:** 48
- **Issue:** `\Drupal::service('date.formatter')->format()`
- **Fix:** Inject `DateFormatterInterface` via constructor
- **Impact:** Testability

**2. Static Service Call in VendorAttendeeController**
- **File:** `web/modules/custom/myeventlane_event_attendees/src/Controller/VendorAttendeeController.php`
- **Line:** 114
- **Issue:** `\Drupal::service('date.formatter')->format()`
- **Fix:** Inject `DateFormatterInterface` via constructor
- **Impact:** Testability

**3. Static Service Call in EventAttendee Entity**
- **File:** `web/modules/custom/myeventlane_event_attendees/src/Entity/EventAttendee.php`
- **Line:** 401
- **Issue:** `\Drupal::time()->getRequestTime()`
- **Fix:** Inject `TimeInterface` via constructor (if used in service context) or keep if in entity method
- **Impact:** Minor - entities can use static calls for time

**4. Static Service Calls in WaitlistPromotionWorker**
- **File:** `web/modules/custom/myeventlane_event_attendees/src/Plugin/QueueWorker/WaitlistPromotionWorker.php`
- **Lines:** 45, 50
- **Issue:** `\Drupal::entityTypeManager()`
- **Fix:** Inject `EntityTypeManagerInterface` via constructor
- **Impact:** Testability

---

### myeventlane_messaging

#### WARNING Issues

**1. Static Service Calls Throughout**
- **Files:** Multiple files in `myeventlane_messaging/src/`
- **Issue:** Extensive use of `\Drupal::service()`, `\Drupal::logger()`, `\Drupal::time()`, `\Drupal::queue()`
- **Fix:** Inject all services via constructor
- **Impact:** Testability and performance

---

### myeventlane_views

#### WARNING Issues

**1. Static Service Call in AttendeeCsvController**
- **File:** `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`
- **Line:** 16
- **Issue:** `\Drupal::logger()`
- **Fix:** Inject `LoggerChannelFactoryInterface` via constructor
- **Impact:** Testability

**2. Static Service Calls in VendorStoreAccess**
- **File:** `web/modules/custom/myeventlane_views/src/Plugin/views/access/VendorStoreAccess.php`
- **Lines:** 18, 24, 29, 33, 40
- **Issue:** Multiple `\Drupal::logger()` and `\Drupal::entityTypeManager()` calls
- **Fix:** Inject services via constructor
- **Impact:** Testability

---

### myeventlane_checkout_paragraph

#### WARNING Issues

**1. Static Service Calls in TicketHolderParagraphPane**
- **File:** `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php`
- **Lines:** 25, 225
- **Issue:** `\Drupal::logger()`
- **Fix:** Inject `LoggerChannelFactoryInterface` via constructor
- **Impact:** Testability

**2. Static Service Call in AttendeeExportController**
- **File:** `web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php`
- **Line:** 46
- **Issue:** `\Drupal::entityTypeManager()`
- **Fix:** Inject `EntityTypeManagerInterface` via constructor
- **Impact:** Testability

---

### myeventlane_tickets

#### WARNING Issues

**1. Static Service Call in TicketCodeGenerator**
- **File:** `web/modules/custom/myeventlane_tickets/src/Ticket/TicketCodeGenerator.php`
- **Line:** 17
- **Issue:** `\Drupal::service('uuid')->generate()`
- **Fix:** Inject `UuidInterface` via constructor
- **Impact:** Testability

**2. Static Service Call in Commerce TicketCodeGenerator**
- **File:** `web/modules/custom/myeventlane_commerce/src/Service/TicketCodeGenerator.php`
- **Line:** 48
- **Issue:** `\Drupal::service('uuid')->generate()`
- **Fix:** Inject `UuidInterface` via constructor
- **Impact:** Testability

---

### myeventlane_vendor

#### WARNING Issues

**1. Static Service Call in Vendor Entity**
- **File:** `web/modules/custom/myeventlane_vendor/src/Entity/Vendor.php`
- **Line:** 79
- **Issue:** `\Drupal::currentUser()->id()`
- **Fix:** This is acceptable in entity methods, but consider passing user ID as parameter
- **Impact:** Minor - acceptable pattern for entities

---

## Critical Fixes

### Fix 1: Theme Base Theme (BLOCKER)

**File:** `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml`

**Current:**
```yaml
base theme: stable9
```

**Fixed:**
```yaml
base theme: stable11
```

OR remove the line entirely (Drupal 11 doesn't require a base theme).

---

### Fix 2: VendorDashboardController - Inject Time Service

**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`

See full file replacement below.

---

### Fix 3: OrderCompletedSubscriber - Inject All Services

**File:** `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`

See full file replacement below.

---

## Validation Commands

After applying fixes, run:

```bash
# PHP Code Standards
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom web/themes/custom/myeventlane_theme

# PHPStan (if configured)
ddev exec vendor/bin/phpstan analyse web/modules/custom

# Drupal Check (if installed)
ddev exec vendor/bin/drupal-check web/modules/custom

# Theme Build
cd web/themes/custom/myeventlane_theme
ddev exec npm run build

# Clear Drupal Cache
ddev drush cr

# Import Config (if needed)
ddev drush cim -y
```

---

## Next Steps

1. Apply BLOCKER fixes first (theme base theme)
2. Apply WARNING fixes in order of impact (controllers, then services, then forms)
3. Run validation commands
4. Test critical user flows (event creation, ticket purchase, RSVP)
5. Consider setting up PHPStan configuration for ongoing code quality

---

**End of Audit Report**






















