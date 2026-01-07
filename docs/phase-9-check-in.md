# Phase 9: Event Check-In System

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Implement Humanitix-level event check-in with QR codes and manual search

---

## Summary

Phase 9 implements a comprehensive check-in system that allows vendors to:

1. **Check in attendees** - Manual check-in via UI
2. **QR code check-in** - Scan QR codes for fast check-in
3. **Search attendees** - Search by name or email
4. **Track check-in status** - Persistent check-in state with timestamp and user
5. **Undo check-in** - Ability to undo check-in if needed

All access control relies on Phase 4 paragraph entity access - vendors can only check in attendees for their own events.

---

## Implementation Details

### Task 1: Data Model ✅

**Files:**
- `config/install/field.storage.paragraph.field_checked_in.yml`
- `config/install/field.storage.paragraph.field_checked_in_timestamp.yml`
- `config/install/field.storage.paragraph.field_checked_in_by.yml`
- `config/install/field.field.paragraph.attendee_answer.field_checked_in.yml`
- `config/install/field.field.paragraph.attendee_answer.field_checked_in_timestamp.yml`
- `config/install/field.field.paragraph.attendee_answer.field_checked_in_by.yml`
- `myeventlane_checkout_paragraph.install` - `hook_update_9002()`

**Fields Added:**
1. **`field_checked_in`** (boolean)
   - Default: `FALSE`
   - Label: "Checked in"
   - Description: "Whether this attendee has been checked in to the event."

2. **`field_checked_in_timestamp`** (timestamp)
   - Label: "Checked in timestamp"
   - Description: "When this attendee was checked in."
   - Set when check-in occurs, cleared when undone.

3. **`field_checked_in_by`** (entity_reference: user)
   - Label: "Checked in by"
   - Description: "The user who checked in this attendee."
   - References the vendor user who performed check-in.

**Install Path:**
- Fresh installs: Fields created via `config/install/` files
- Existing sites: Fields created via `hook_update_9002()`

---

### Task 2: Vendor Check-In UI ✅

**File:** `web/modules/custom/myeventlane_checkout_flow/src/Controller/VendorCheckInController.php`

**Route:** `/vendor/events/{node}/check-in`

**Features:**
- Search box (name/email filtering)
- Attendee list table showing:
  - Name
  - Email
  - Ticket type
  - Check-in status (with timestamp)
  - QR code link
  - Check-in/Undo button
- Check-in stats (total, checked in, remaining)
- Real-time status updates

**Access Control:**
- Custom access callback verifies vendor owns event
- Uses `VendorOwnershipResolver::vendorOwnsEvent()`
- Only attendees vendor can access (via entity access) are shown

**Template:** `myeventlane-vendor-checkin.html.twig`

**Form:** `CheckInForm.php` - Handles check-in/undo actions

---

### Task 3: QR Codes ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/src/Service/CheckInTokenService.php`

**Token Format:**
- `base64(paragraph_id:timestamp:hmac_base64)`
- HMAC computed over: `paragraph_id:timestamp` using site private key
- Token expires after 24 hours

**Security:**
- Uses Drupal's `hash_salt` (site private key) for HMAC signing
- Prevents token forgery
- Time-based expiration (24 hours)

**QR Code Generation:**
- Token generated per attendee paragraph
- URL: `/vendor/check-in/scan/{token}`
- QR code can be generated client-side or server-side

**Scan Endpoint:**
- Route: `/vendor/check-in/scan/{token}`
- Validates token HMAC
- Resolves paragraph and event
- Enforces vendor ownership
- Marks checked-in
- Redirects with status message

---

### Task 4: CSV Update ✅

**File:** `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`

**Changes:**
- Added columns: "Checked in" (yes/no), "Checked in time"
- CSV still filters by entity access (Phase 4)
- Only accessible attendees included

**CSV Format:**
```
First name, Last name, Email, Phone, Question, Answer, Checked in, Checked in time
John, Doe, john@example.com, , , , Yes, 2024-12-27 14:30:00
```

---

### Task 5: Tests ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/tests/src/Kernel/CheckInTest.php`

**Test Cases:**

1. **`testVendorCanCheckInAttendeeForOwnedEvent()`**
   - Creates event owned by vendor
   - Creates order with attendee paragraph
   - Verifies vendor can check in attendee
   - Verifies check-in status is persisted

2. **`testVendorCannotCheckInAttendeeForOtherVendorEvent()`**
   - Creates event owned by other vendor
   - Verifies vendor cannot update attendee paragraph
   - Verifies access is denied

3. **`testTokenCannotBeForged()`**
   - Generates valid token
   - Verifies token validation works
   - Attempts to forge token with wrong HMAC
   - Verifies forged token is rejected

---

## Files Created/Modified

### New Files:
1. `config/install/field.storage.paragraph.field_checked_in.yml`
2. `config/install/field.storage.paragraph.field_checked_in_timestamp.yml`
3. `config/install/field.storage.paragraph.field_checked_in_by.yml`
4. `config/install/field.field.paragraph.attendee_answer.field_checked_in.yml`
5. `config/install/field.field.paragraph.attendee_answer.field_checked_in_timestamp.yml`
6. `config/install/field.field.paragraph.attendee_answer.field_checked_in_by.yml`
7. `src/Service/CheckInTokenService.php`
8. `src/Controller/VendorCheckInController.php`
9. `src/Form/CheckInForm.php`
10. `templates/myeventlane-vendor-checkin.html.twig`
11. `tests/src/Kernel/CheckInTest.php`
12. `docs/phase-9-check-in.md`

### Modified Files:
1. `myeventlane_checkout_paragraph.install` - Added `hook_update_9002()`
2. `myeventlane_checkout_paragraph.services.yml` - Added token service
3. `myeventlane_checkout_flow.routing.yml` - Added check-in routes
4. `myeventlane_checkout_flow.module` - Added theme hook
5. `AttendeeCsvController.php` - Added check-in columns

---

## Route Configuration

```yaml
myeventlane_checkout_flow.vendor_checkin:
  path: '/vendor/events/{node}/check-in'
  defaults:
    _controller: '\Drupal\myeventlane_checkout_flow\Controller\VendorCheckInController::checkInPage'
    _title: 'Check In'
  requirements:
    _entity_access: 'node.view'
    _custom_access: '\Drupal\myeventlane_checkout_flow\Controller\VendorCheckInController::checkAccess'
    node: \d+

myeventlane_checkout_flow.vendor_checkin_action:
  path: '/vendor/check-in/paragraph/{paragraph}'
  defaults:
    _form: '\Drupal\myeventlane_checkout_flow\Form\CheckInForm'
  requirements:
    _entity_access: 'paragraph.update'
    paragraph: \d+

myeventlane_checkout_flow.vendor_checkin_scan:
  path: '/vendor/check-in/scan/{token}'
  defaults:
    _controller: '\Drupal\myeventlane_checkout_flow\Controller\VendorCheckInController::scanCheckIn'
    _title: 'Check In via QR Code'
  requirements:
    _permission: 'access content'
    _custom_access: '\Drupal\myeventlane_checkout_flow\Controller\VendorCheckInController::checkAccess'
    token: '[A-Za-z0-9+/=_-]+'
```

---

## Access Control

### Check-In Page Access
- **Vendor Verification:** Uses `VendorOwnershipResolver::getStoreForUser()`
- **Event Ownership:** Verifies vendor owns event
- **Admin Override:** Admins always allowed
- **Result:** Only vendors who own the event can access

### Check-In Action Access
- **Entity Access:** Uses `paragraph.update` access check
- **Phase 4 Rules:** Relies on paragraph entity access (Phase 4)
- **Result:** Only paragraphs vendor can update are checkable

### QR Scan Access
- **Token Validation:** HMAC signature verified
- **Vendor Verification:** Verifies vendor owns event
- **Entity Access:** Checks paragraph update access
- **Result:** Secure, vendor-only check-in

---

## Token Security

### Token Generation
```php
$message = $paragraph_id . ':' . $timestamp;
$hmac = hash_hmac('sha256', $message, $secret_key, TRUE);
$token = base64_encode($paragraph_id . ':' . $timestamp . ':' . base64_encode($hmac));
```

### Token Validation
1. Decode base64 token
2. Split into: `paragraph_id:timestamp:hmac_base64`
3. Verify timestamp (not expired, not future)
4. Recompute HMAC and compare
5. Return paragraph ID if valid

### Security Features
- **HMAC Signing:** Prevents token forgery
- **Time Expiration:** Tokens expire after 24 hours
- **Site Private Key:** Uses Drupal's `hash_salt`
- **Constant-Time Comparison:** Uses `hash_equals()` to prevent timing attacks

---

## UI Components

### Check-In Page
```
┌─────────────────────────────────────┐
│  ← Back to Attendees & Sales         │
│  Check In - Event Name               │
│  📅 Date | 📍 Location               │
├─────────────────────────────────────┤
│  [Search by name or email...] [Search] │
├─────────────────────────────────────┤
│  Total: 50 | Checked In: 30 | Remaining: 20 │
├─────────────────────────────────────┤
│  Attendees (50)                      │
│  ┌─────────────────────────────┐   │
│  │ Name | Email | Type | Status │   │
│  │      |       |      | QR | Act│   │
│  │ John | ...   | GA  | ✓ | [CI]│   │
│  │ Jane | ...   | VIP |   | [CI]│   │
│  └─────────────────────────────┘   │
└─────────────────────────────────────┘
```

### Check-In Status
- **Not Checked In:** Gray badge, "Check In" button
- **Checked In:** Green badge with ✓, timestamp, "Undo" button

---

## Manual Test Steps

1. **Field Installation:**
   - Run `ddev drush updb` or install module
   - Verify fields exist on `attendee_answer` paragraphs
   - Check field storage configs

2. **Check-In Page:**
   - Log in as vendor
   - Navigate to `/vendor/events/{event_id}/check-in`
   - Verify attendee list displays
   - Test search (name/email)
   - Click "Check In" on an attendee
   - Verify status updates to "Checked In"
   - Verify timestamp appears
   - Click "Undo" to reverse check-in

3. **QR Code Check-In:**
   - Generate QR code for an attendee
   - Scan QR code (or open URL directly)
   - Verify attendee is checked in
   - Verify redirect to check-in page
   - Verify status message appears

4. **Access Control:**
   - Try to access another vendor's event check-in page (should be denied)
   - Try to check in attendee from other vendor's event (should be denied)
   - Verify only accessible attendees appear in list

5. **CSV Export:**
   - Export CSV for event with checked-in attendees
   - Verify "Checked in" column shows "Yes"/"No"
   - Verify "Checked in time" column shows timestamp
   - Verify only accessible attendees included

6. **Token Security:**
   - Generate valid token
   - Verify token validates correctly
   - Try to modify token (should be rejected)
   - Try expired token (should be rejected)

---

## Edge Cases Handled

✅ **No attendees** - Shows empty state

✅ **Search no results** - Shows "No attendees found" message

✅ **Already checked in** - Shows warning, allows undo

✅ **Token expired** - Rejects token after 24 hours

✅ **Invalid token** - Rejects forged or malformed tokens

✅ **Missing fields** - Gracefully handles if fields don't exist

✅ **Access denied** - Clear error messages

---

## Security Considerations

1. **Token Security:**
   - HMAC signing prevents forgery
   - Time expiration limits token lifetime
   - Site private key ensures uniqueness

2. **Access Control:**
   - Entity access enforced at all levels
   - Vendor ownership verified
   - No manual access checks (relies on system)

3. **Data Integrity:**
   - Check-in status persisted on paragraph
   - Timestamp and user tracked
   - Immutable after order placement (Phase 4)

4. **CSRF Protection:**
   - Form API handles CSRF tokens
   - Token validation for QR scan

---

## Integration Points

- **Paragraph Entity Access** - Phase 4 access control applies
- **VendorOwnershipResolver** - Verifies vendor owns event
- **AttendeeParagraphAccessResolver** - Resolves paragraph → event
- **CSV Export** - Includes check-in data
- **Entity Storage** - Persists check-in state

---

## QR Code Implementation Notes

### Client-Side QR Generation
QR codes can be generated using JavaScript libraries like:
- `qrcode.js`
- `qrcode-generator`
- `qrcode.react` (if using React)

Example:
```javascript
const qrCode = qrcode(0, 'M');
qrCode.addData(attendee.qr_url);
qrCode.make();
const qrSvg = qrCode.createSvgTag(4);
```

### Server-Side QR Generation
Can use PHP libraries like:
- `endroid/qr-code`
- `bacon/bacon-qr-code`

---

## Next Steps

- **Future:** Add bulk check-in
- **Future:** Add check-in history/audit log
- **Future:** Add check-in notifications
- **Future:** Add mobile app integration

---

**END OF PHASE 9 DOCUMENTATION**

