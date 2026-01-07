# Phase 2: Attendee Data Normalisation

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Eliminate dual attendee storage and enforce single, secure, paragraph-based attendee data model

---

## Summary

Phase 2 successfully eliminated the dual attendee storage system (JSON vs Paragraphs) and established the paragraph-based system (`field_ticket_holder` → `attendee_answer` paragraphs) as the single source of truth for attendee data.

---

## Changes Implemented

### Task 1: Deprecate JSON Attendee System ✅

**File:** `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/CheckoutPane/AttendeeInfoPerTicket.php`

- Added `@deprecated` annotation to class docblock
- Updated `isVisible()` to always return `FALSE`
- Updated `buildPaneForm()` and `submitPaneForm()` to log warnings and prevent data writes
- Pane is already disabled in checkout flow configuration (Phase 1)

**New File:** `web/modules/custom/myeventlane_commerce/src/EventSubscriber/FieldAttendeeDataWriteSubscriber.php`

- Monitors writes to `field_attendee_data` on order items
- Logs warnings when attendee data (not metadata) is written
- Allows metadata-only writes from donation services (donation_type, rsvp_submission_id, etc.)
- Allows accessibility_needs from RSVP forms (temporary exception)

**Service Registration:** Added to `myeventlane_commerce.services.yml`

---

### Task 2: Normalize Paragraph Attendee Schema ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php`

**Schema Verification:**
- ✅ `field_first_name` (required, string)
- ✅ `field_last_name` (required, string)
- ✅ `field_email` (required, email)
- ✅ `field_phone` (optional, tel)
- ✅ `field_attendee_questions` (entity reference to nested `attendee_extra_field` paragraphs)

**Normalization:**
- Extra question answers are always read from `field_attendee_extra_field`
- Extra question answers are always written to `field_attendee_extra_field`
- Arrays (e.g., checkbox values) are JSON-encoded before storage
- Validation ensures required fields are present and email format is valid

---

### Task 3: Order Item Attachment Integrity ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.module`

**Implemented Hooks:**

1. **`hook_entity_delete()`** - Cascade delete attendee paragraphs
   - When an order item is deleted, all referenced `attendee_answer` paragraphs are deleted
   - Nested `attendee_extra_field` paragraphs are deleted first (cascade)
   - Prevents orphan paragraphs

2. **`hook_paragraph_presave()`** - Integrity check
   - Verifies that `attendee_answer` paragraphs are referenced by an order item
   - Logs warning if paragraph is saved without a parent order item reference
   - Allows new paragraphs during creation (before reference is set)

**File:** `TicketHolderParagraphPane.php`

- Added `verifyParagraphAttachment()` method
- Checks that saved paragraphs are still referenced by their order item
- Logs errors if integrity check fails

---

### Task 4: Lock JSON System After Order Placed ✅

**File:** `web/modules/custom/myeventlane_commerce/src/EventSubscriber/LockAttendeeOnOrderPlaced.php`

**Enhanced Functionality:**
- Detects deprecated `field_attendee_data` with attendee information on placed orders
- Logs warnings for legacy JSON attendee data
- Allows metadata-only data (donation services)
- Marks attendee paragraphs as "locked" (immutability enforced by access control in Phase 4)
- Logs info messages when paragraphs are locked

---

### Task 5: CSV Export Verification ✅

**File:** `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`

**Status:** ✅ Already uses paragraph system only

- Reads from `attendee_answer` paragraphs via Views
- Uses `field_first_name`, `field_last_name`, `field_email` from paragraphs
- Reads extra question answers from nested `attendee_extra_field` paragraphs via `field_attendee_questions`
- No fallback to JSON `field_attendee_data`
- Export output remains unchanged for vendors

---

## Field Usage Summary

### Canonical Attendee Storage (Active)
- **Field:** `commerce_order_item.field_ticket_holder`
- **Type:** Entity reference to Paragraph entities
- **Paragraph Type:** `attendee_answer`
- **Fields on Paragraph:**
  - `field_first_name` (required)
  - `field_last_name` (required)
  - `field_email` (required)
  - `field_phone` (optional)
  - `field_attendee_questions` (nested paragraphs for extra questions)

### Deprecated Storage (Monitored)
- **Field:** `commerce_order_item.field_attendee_data`
- **Status:** Deprecated, monitored for writes
- **Allowed Uses:**
  - Metadata from donation services (donation_type, rsvp_submission_id, etc.)
  - Accessibility needs from RSVP forms (temporary)
- **Blocked Uses:**
  - Attendee name/email data (writes logged as warnings)

---

## Testing Checklist

✅ **Create paid event with per-ticket attendee collection**
- Event created with ticket types
- Per-ticket attendee collection enabled

✅ **Complete checkout with multiple tickets**
- Checkout flow uses `ticket_holder_paragraph` pane
- Multiple tickets added to cart
- Attendee details entered for each ticket

✅ **Verify paragraph creation**
- `attendee_answer` paragraphs created
- One paragraph per ticket
- Paragraphs referenced via `field_ticket_holder`

✅ **Verify no JSON writes**
- No data written to `field_attendee_data` for attendee info
- Watchdog warnings logged if deprecated writes occur

✅ **Verify CSV export**
- CSV export works correctly
- Exports attendee data from paragraphs
- Vendor can download attendee list

✅ **Verify order placement locks data**
- Order placed successfully
- Attendee paragraphs marked as locked
- Warnings logged if legacy JSON data exists

---

## Migration Notes

### Legacy Data
- Existing orders with `field_attendee_data` containing attendee information will log warnings
- No automatic migration - legacy data remains but is not used
- Future migration script could convert JSON to paragraphs if needed

### RSVP Forms
- `RsvpBookingForm` still writes `accessibility_needs` to `field_attendee_data`
- This is a temporary exception and should be migrated to paragraphs in a future phase

### Donation Services
- `RsvpDonationService` and `PlatformDonationService` write metadata to `field_attendee_data`
- This is allowed and not deprecated (metadata only, not attendee data)

---

## Files Modified

1. `web/modules/custom/myeventlane_commerce/src/Plugin/Commerce/CheckoutPane/AttendeeInfoPerTicket.php` - Deprecated
2. `web/modules/custom/myeventlane_commerce/src/EventSubscriber/LockAttendeeOnOrderPlaced.php` - Enhanced
3. `web/modules/custom/myeventlane_commerce/src/EventSubscriber/FieldAttendeeDataWriteSubscriber.php` - New
4. `web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml` - Updated
5. `web/modules/custom/myeventlane_checkout_paragraph/src/Plugin/Commerce/CheckoutPane/TicketHolderParagraphPane.php` - Enhanced
6. `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.module` - New hooks

---

## Next Steps (Phase 4)

- Implement access control for `attendee_answer` paragraphs
- Ensure vendors can only access attendee data for their own events
- Enforce immutability of paragraphs after order placement

---

**END OF PHASE 2 DOCUMENTATION**

