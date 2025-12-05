# Fixes Applied - MyEventLane Audit

## ✅ BLOCKER Fixes Applied

### 1. Theme Base Theme (FIXED)
**File:** `web/themes/custom/myeventlane_theme/myeventlane_theme.info.yml`
- **Changed:** `base theme: stable9` → `base theme: stable11`
- **Status:** ✅ Fixed
- **Impact:** Theme now correctly declares Drupal 11 base theme

### 2. VendorDashboardController - Time Service Injection (FIXED)
**File:** `web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php`
- **Changed:** Injected `TimeInterface` via constructor
- **Removed:** `\Drupal::time()->getRequestTime()` static call
- **Status:** ✅ Fixed
- **Impact:** Better testability and follows Drupal 11 best practices

### 3. OrderCompletedSubscriber - Logger Injection (PARTIALLY FIXED)
**File:** `web/modules/custom/myeventlane_commerce/src/EventSubscriber/OrderCompletedSubscriber.php`
- **Changed:** Replaced `\Drupal::logger()` calls with injected `LoggerChannelFactoryInterface`
- **Note:** Optional services (myeventlane_tickets.pdf, wallet services) still use static calls - this is acceptable for optional dependencies
- **Status:** ✅ Partially Fixed (logger injection complete, optional services remain static)
- **Impact:** Better testability for logging

---

## ⚠️ Remaining WARNING Issues

### High Priority (Should Fix Soon)

1. **CustomerDashboardController** - Inject `TimeInterface`
   - File: `web/modules/custom/myeventlane_dashboard/src/Controller/CustomerDashboardController.php`
   - Line: 97
   - Fix: Add `TimeInterface` to constructor

2. **TicketSelectionForm** - Inject `CurrencyFormatterInterface`
   - File: `web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`
   - Line: 96
   - Fix: Add `CurrencyFormatterInterface` to constructor

3. **RsvpBookingForm** - Inject services
   - File: `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php`
   - Lines: 110, 121, 146, 147, 148
   - Fix: Inject `EntityTypeManagerInterface`, `CartManagerInterface`, `CartProviderInterface`

4. **EventAttendeeListBuilder** - Inject `DateFormatterInterface`
   - File: `web/modules/custom/myeventlane_event_attendees/src/EventAttendeeListBuilder.php`
   - Line: 48
   - Fix: Add `DateFormatterInterface` to constructor

5. **VendorAttendeeController** - Inject `DateFormatterInterface`
   - File: `web/modules/custom/myeventlane_event_attendees/src/Controller/VendorAttendeeController.php`
   - Line: 114
   - Fix: Add `DateFormatterInterface` to constructor

### Medium Priority

6. **RsvpSubmissionSaver** - Inject `LanguageManagerInterface`
7. **VendorDigestGenerator** - Inject `EntityTypeManagerInterface` and `LanguageManagerInterface`
8. **RsvpFormBlock** - Inject `RouteMatchInterface` and `FormBuilderInterface`
9. **WaitlistPromotionWorker** - Inject `EntityTypeManagerInterface`
10. **TicketCodeGenerator** (both instances) - Inject `UuidInterface`

### Low Priority (Acceptable Patterns)

- Entity methods using `\Drupal::currentUser()` or `\Drupal::time()` - acceptable
- Theme `.theme` file using static calls - acceptable for theme hooks (but could be improved)

---

## 📋 Next Steps

1. **Immediate:**
   - Clear Drupal cache: `ddev drush cr`
   - Test theme loads correctly with new base theme
   - Verify dashboard functionality still works

2. **Short-term (This Week):**
   - Apply high-priority fixes (items 1-5 above)
   - Run code standards: `ddev exec vendor/bin/phpcs web/modules/custom`
   - Test critical user flows

3. **Medium-term (This Month):**
   - Apply medium-priority fixes
   - Set up PHPStan configuration
   - Add automated code quality checks to CI/CD

---

## 🧪 Validation Commands

After applying fixes, run:

```bash
# Clear cache
ddev drush cr

# Code standards
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom web/themes/custom/myeventlane_theme

# Theme build
cd web/themes/custom/myeventlane_theme
ddev exec npm run build

# Import config if needed
ddev drush cim -y
```

---

## 📝 Notes

- **Optional Services:** Services that may not exist (like `myeventlane_tickets.pdf`) are kept as static calls with `\Drupal::hasService()` checks. This is acceptable for optional dependencies.

- **Theme Hooks:** The `.theme` file using static calls is acceptable for theme hooks, though it could be improved by creating a theme service for complex logic.

- **Entity Methods:** Entities using `\Drupal::currentUser()` or `\Drupal::time()` in their methods is an acceptable pattern in Drupal.

---

**Last Updated:** 2025-01-27

