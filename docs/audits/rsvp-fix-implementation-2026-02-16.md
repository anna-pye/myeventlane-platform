# RSVP Security & Integrity Fix Implementation Report

**Date:** 2026-02-16  
**Source:** `docs/MEL_RSVP_AUDIT_REPORT.md` (MUST-FIX items)  
**Scope:** P0, P1, P2 fixes with stability-first changes.

---

## 1. Summary

| Priority | Issue | Status |
|----------|-------|--------|
| P0-1 | Secure RSVP cancel route | ✅ Done |
| P0-2 | Secure QR validate endpoint | ✅ Done |
| P0-3 | Fix RsvpCapacityService incorrect storage | ✅ Done |
| P1-4 | VendorEventAccess availability | ✅ Done |
| P1-5 | Prevent duplicate RSVPs (idempotent submissions) | ✅ Done |
| P2-6 | DI cleanup | Partial (RsvpPublicForm, RsvpMailer) |
| P2-7 | Locking/transaction for capacity | ✅ Done (in RsvpSubmissionManager) |
| P2-8 | Caching hygiene | Partial (checkin_validate uncacheable) |

---

## 2. Files Changed

### P0-1: Secure RSVP cancel route

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_rsvp/src/Access/RsvpCancelAccess.php` | **Added** – Access checker: RSVP owner (uid), guest (signed token), admin override |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml` | `_permission: access content` → `_custom_access: 'myeventlane_rsvp.rsvp_cancel_access:access'` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.services.yml` | Added `myeventlane_rsvp.rsvp_cancel_access` |
| `web/modules/custom/myeventlane_rsvp/src/Form/RsvpCancelConfirmForm.php` | Soft cancel (status→cancelled), 403 on already cancelled / missing, queue vendor digest |
| `web/modules/custom/myeventlane_rsvp/src/Controller/VendorEventRsvpController.php` | Cancel link uses `cancel_confirm` route with `rsvp_id` |
| `web/modules/custom/myeventlane_rsvp/tests/src/Kernel/RsvpCancelAccessTest.php` | **Added** – Owner/admin/vendor can cancel; user A cannot cancel user B |

### P0-2: Secure QR validate endpoint

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml` | `_permission: access content` → `_permission: manage own event rsvps` |
| `web/modules/custom/myeventlane_rsvp/src/Controller/QrCheckinController.php` | Flood by IP (30/min), event access check, uncacheable response |
| `web/modules/custom/myeventlane_rsvp/tests/src/Functional/QrCheckinValidateTest.php` | **Added** – Anonymous 403, vendor 200, flood limit |
| `web/modules/custom/myeventlane_rsvp/src/Controller/QrCheckinController.php` | Fixed readonly property collision: `$currentUser` → `$account` (parent ControllerBase defines `$currentUser`) |

### P0-3: Fix RsvpCapacityService

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_rsvp/src/Service/RsvpCapacityService.php` | Switched from `myeventlane_rsvp_submission`/`event` to entity query on `rsvp_submission` with `event_id`, `status=confirmed`; injected EntityTypeManager; added `countConfirmedRsvps()` |
| `web/modules/custom/myeventlane_rsvp/tests/src/Kernel/RsvpCapacityServiceTest.php` | **Added** – Kernel test for count correctness |

### P1-4: VendorEventAccess

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_rsvp/src/Access/VendorEventAccess.php` | **Added** – `checkAccess()` for event owner, vendor users (field_event_vendor→field_vendor_users), admin override |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.services.yml` | Added `myeventlane_rsvp.vendor_event_access` |
| `web/modules/custom/myeventlane_rsvp/tests/src/Kernel/VendorEventAccessTest.php` | **Added** – Vendor/owner can access; other vendor cannot; admin can |

### P1-5: RsvpSubmissionManager (centralised RSVP submit logic)

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionManager.php` | **Added** – Flood (anon 10/hr), lock `rsvp:event:{id}`, capacity check under lock, dedupe by (event_id, uid) or (event_id, email), update-existing idempotency |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.services.yml` | Added `myeventlane_rsvp.submission_manager` |
| `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php` | Refactored to use `RsvpSubmissionManager::submitOrUpdate()`; removed duplicated submit logic; added DI (ConfigFactory, RsvpMailer) |
| `web/modules/custom/myeventlane_commerce/myeventlane_commerce.info.yml` | Added dependency `myeventlane_rsvp` |
| `web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php` | Donation branch uses `RsvpSubmissionManager`; inject submission manager |
| `web/modules/custom/myeventlane_rsvp/tests/src/Kernel/RsvpSubmissionManagerTest.php` | **Added** – Guest same-email updates; authenticated user updates; different emails create separate submissions |

### P2 partial

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php` | ConfigFactory, RsvpMailer injected; removed `\Drupal::config()` and `\Drupal::service('myeventlane_rsvp.mailer')` for mailer |
| `web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php` | `\Drupal::hasService('myeventlane_donations.rsvp')`, `myeventlane_event_attendees.manager` – still present (P2-6 remaining) |

---

## 3. Tests Added / Updated

| Test File | Coverage |
|-----------|----------|
| `RsvpCancelAccessTest` | User A cannot cancel user B; owner/admin/vendor can cancel |
| `RsvpCapacityServiceTest` | Count confirmed RSVPs; waitlist/cancelled excluded |
| `VendorEventAccessTest` | Vendor can access own event; cannot access other; admin can |
| `QrCheckinValidateTest` | Anonymous 403; vendor 200; flood limit |
| `RsvpSubmissionManagerTest` | Same email updates existing; same user updates existing; different emails create separate |

---

## 4. How to Run Tests

**Prerequisites:** `SIMPLETEST_DB` must be set (Drupal requires DB for kernel/functional tests). Example:

```bash
export SIMPLETEST_DB=mysql://db:db@db:3306/db
```

**Commands:**

```bash
# Clear cache
ddev drush cr

# Run RSVP kernel tests
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db php web/core/scripts/run-tests.sh --url http://myeventlane.ddev.site --php /usr/local/bin/php --class Drupal\\Tests\\myeventlane_rsvp\\Kernel\\RsvpCapacityServiceTest"
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db php web/core/scripts/run-tests.sh --url http://myeventlane.ddev.site --php /usr/local/bin/php --class Drupal\\Tests\\myeventlane_rsvp\\Kernel\\RsvpSubmissionManagerTest"
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db php web/core/scripts/run-tests.sh --url http://myeventlane.ddev.site --php /usr/local/bin/php --class Drupal\\Tests\\myeventlane_rsvp\\Kernel\\RsvpCancelAccessTest"
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db php web/core/scripts/run-tests.sh --url http://myeventlane.ddev.site --php /usr/local/bin/php --class Drupal\\Tests\\myeventlane_rsvp\\Kernel\\VendorEventAccessTest"

# Run all myeventlane_rsvp tests
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db php web/core/scripts/run-tests.sh --url http://myeventlane.ddev.site --php /usr/local/bin/php myeventlane_rsvp"
```

**PHPUnit (if SIMPLETEST_DB configured):**

```bash
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/myeventlane_rsvp/tests/"
```

---

## 5. Validation Commands

```bash
# Cache rebuild
ddev drush cr

# Verify routes (drush 13 uses core:route)
ddev drush core:route | grep -E "cancel_confirm|checkin_validate"

# PHP lint modified files
php -l web/modules/custom/myeventlane_rsvp/src/Form/RsvpPublicForm.php
php -l web/modules/custom/myeventlane_rsvp/src/Service/RsvpSubmissionManager.php
php -l web/modules/custom/myeventlane_commerce/src/Form/RsvpBookingForm.php
```

---

## 6. Outstanding Items (P2 / P3)

| Item | Status |
|------|--------|
| P2-6 | Replace remaining `\Drupal::hasService()` / `\Drupal::service()` in RsvpPublicForm (donations, attendance) and RsvpSubmissionForm, RsvpMailer |
| P2-8 | Ensure RSVP “You are RSVPed” render arrays include cache context `user` where needed |
| P3-9 | Styling scope check – verify RSVP libraries attach only on relevant pages |

**RsvpSubmissionForm:** Contains broken/incomplete code (undefined refs, wrong field names). Not wired to RsvpSubmissionManager. Recommend separate fix.

---

## 7. P0-REPORTING: Vendor/Admin Reporting Accuracy & Auth Scoping (2026-02-16)

### 7.1 Reporting surfaces and scoping

| Surface | Route | Access | Data scoping |
|---------|-------|--------|--------------|
| **RSVP list** | `myeventlane_rsvp.vendor_event_rsvps` `/vendor/event/{event}/rsvps` | `VendorEventAccess::checkAccess` | event_id from route param |
| **RSVP CSV export** | `myeventlane_rsvp.export_csv` `/vendor/event/{event}/rsvps/export` | `VendorEventAccess::checkAccess` | event_id from route param |
| **Check-in list** | `myeventlane_rsvp.checkin_list` `/vendor/event/{event}/rsvps/checkin` | `VendorEventAccess::checkAccess` | event_id from route param |
| **Check-in PDF** | `myeventlane_rsvp.checkin_list_pdf` `/vendor/event/{event}/rsvps/checkin/pdf` | `VendorEventAccess::checkAccess` | event_id from route param |
| **Attendee list** | `myeventlane_event_attendees.vendor_list` `/vendor/event/{node}/attendees` | `VendorAttendeeController::access` | node from route param |
| **Attendee CSV export** | `myeventlane_event_attendees.vendor_export` `/vendor/event/{node}/attendees/export` | `VendorAttendeeController::access` | node from route param |
| **Waitlist list** | `myeventlane_event_attendees.waitlist_manage` `/vendor/event/{node}/waitlist` | `WaitlistManagementController::access` | node from route param |
| **Waitlist CSV export** | `myeventlane_event_attendees.waitlist_export` `/vendor/event/{node}/waitlist/export` | `WaitlistManagementController::access` | node from route param |
| **Donation CSV export** | `myeventlane_donations.admin_export` `/admin/reports/myeventlane/donations/export` | `administer myeventlane donations` | Platform-wide (admin only) |
| **Vendor dashboard RSVP counts** | `myeventlane_vendor.console.dashboard` | `VendorConsoleAccess` + `RsvpStatsService` | Scoped by vendor uid → events |

### 7.2 Access enforcement map

| Access mechanism | Used by | Policy |
|------------------|---------|--------|
| **VendorEventAccess::checkAccess** | RSVP list, export, check-in, PDF, scan | administer rsvps, administer nodes, OR (manage own event rsvps + (event owner OR field_event_vendor→field_vendor_users)) |
| **VendorAttendeeController::access** | Attendee list, export, check-in | administer event attendees OR (view own event attendees + (event owner OR field_event_vendor→field_vendor_users)) |
| **WaitlistManagementController::access** | Waitlist list, export | administer nodes, uid=1, OR event owner, OR field_event_vendor→field_vendor_users |
| **VendorConsoleAccess** | Vendor dashboard | Administrator or `access vendor console` |
| **administer myeventlane donations** | Donation export | Admin-only platform report |

**Alignment:** VendorAttendeeController and WaitlistManagementController now include `field_event_vendor` → `field_vendor_users` check, matching VendorEventAccess policy so vendor users (non-owners) can access event reporting.

### 7.3 Count correctness verification

| Count source | Location | Status filter | Used by |
|--------------|----------|---------------|---------|
| **RsvpCapacityService::countConfirmedRsvps** | Canonical | `status = 'confirmed'` | VendorEventRsvpController, RsvpSubmissionManager |
| **UserRsvpRepository::getEventRsvpCount** | Reporting | Per-status (confirmed, waitlist) | VendorEventRsvpController (waitlist), RsvpCheckinController |
| **RsvpStatsService::getEventRsvpCount** | Vendor dashboard | `status = 'confirmed'` (entity) or `active` (legacy) | VendorDashboardController, MetricsAggregator |

**Note:** RsvpStatsService duplicates count logic; recommend refactoring to inject RsvpCapacityService when myeventlane_vendor adds myeventlane_rsvp dependency. Status values are consistent: `confirmed`, `waitlist`, `cancelled`.

### 7.4 Exports hardened

| Export | Uncacheable headers | Access | PII in logs |
|--------|---------------------|--------|-------------|
| RSVP CSV | ✅ Cache-Control, Pragma, Expires | VendorEventAccess | No |
| Attendee CSV | ✅ Cache-Control, Pragma, Expires | VendorAttendeeController::access | No |
| Waitlist CSV | ✅ Cache-Control, Pragma, Expires | WaitlistManagementController::access | No |
| Donation CSV | ✅ Cache-Control, Pragma, Expires | administer myeventlane donations | uid only |

### 7.5 P0-REPORTING code changes

| File | Change |
|------|--------|
| `UserRsvpRepository.php` | Added `getEventRsvps()`, `getEventRsvpCount()` – reporting format (first_name, last_name from attendee_name), excludes cancelled |
| `RsvpCheckinController.php` | Fixed `getEventRsvpsByStatus` to call `$this->repo->getEventRsvps()` |
| `VendorEventRsvpController.php` | Use `RsvpCapacityService::countConfirmedRsvps()` for confirmed count; inject capacity service |
| `VendorRsvpExportController.php` | Uncacheable headers; filename includes event id |
| `VendorAttendeeController.php` | Access: add field_event_vendor→field_vendor_users; accessAttendee delegates to access(); uncacheable on export |
| `WaitlistManagementController.php` | Uncacheable on export |
| `DonationReportController.php` | Uncacheable on export |
| `VendorReportingAccessTest.php` | **Added** – Vendor A cannot access Vendor B RSVP/export; admin can access all |

### 7.6 Run P0-REPORTING tests

```bash
ddev exec "cd /var/www/html && SIMPLETEST_DB=mysql://db:db@db:3306/db php web/core/scripts/run-tests.sh --url http://myeventlane.ddev.site --php /usr/local/bin/php --class Drupal\\Tests\\myeventlane_rsvp\\Functional\\VendorReportingAccessTest"
```
