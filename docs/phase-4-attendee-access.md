# Phase 4: Access Control and Immutability for Attendee Paragraphs

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Enforce strict access control and immutability for attendee data stored in Paragraph entities

---

## Summary

Phase 4 implements comprehensive access control and immutability enforcement for `attendee_answer` paragraphs, ensuring that:

1. **Vendors** can only view attendee data for events they own
2. **Customers** can only view/update attendee data from their own orders
3. **Attendee data is immutable** after order placement (placed/completed/fulfilled states)
4. **CSV exports** respect entity access checks
5. **Admin users** have full access (bypass)

This prevents unauthorized access to attendee information and ensures data integrity after orders are placed.

---

## Access Control Rules (Plain English)

### Who Can View Attendee Data?

1. **Admin users** - Always allowed (full access)
2. **Customers** - Can view attendee paragraphs from orders they own
3. **Vendors** - Can view attendee paragraphs for events they own
4. **Anonymous users** - Denied (no access)
5. **Other users** - Denied (no access)

### Who Can Update/Delete Attendee Data?

1. **Admin users** - Always allowed (full access)
2. **Customers** - Can update/delete attendee paragraphs from their own orders **only if the order is not yet placed**
3. **Vendors** - Cannot update/delete attendee data (even for their own events)
4. **All users** - Cannot update/delete attendee paragraphs after order is placed (immutability)

### When Is Data Locked?

Attendee data becomes **immutable** (locked) when the order transitions to:
- `placed` - Order has been placed
- `completed` - Order is completed
- `fulfilled` - Order is fulfilled

Orders in `draft` or `validation` states allow updates.

---

## Implementation Details

### Task 1: Access Control Implementation ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.module`

**Hook:** `hook_entity_access()`

**Access Logic:**
1. **Admin check** - Users with `administer commerce_order` or `bypass node access` always allowed
2. **Anonymous check** - Anonymous users always denied
3. **Relationship resolution** - Resolves paragraph → order item → order → event → vendor
4. **Customer check** - Compares order `customer_id` with current user ID
5. **Vendor check** - Uses `VendorOwnershipResolver` to verify vendor owns event
6. **Operation-specific rules:**
   - `view` - Customers and vendors allowed (if ownership matches)
   - `update`/`delete` - Only customers allowed, and only if order is not locked

**Service:** `AttendeeParagraphAccessResolver`
- `getParentOrderItem()` - Resolves order item from paragraph
- `getParentOrder()` - Resolves order from paragraph
- `getEvent()` - Resolves event from paragraph
- `isOrderLocked()` - Checks if order is in locked state
- `isOwnedByUser()` - Checks if paragraph belongs to user's order

---

### Task 2: Immutability Enforcement ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.module`

**Implementation:**
- `isOrderLocked()` method checks order state
- Locked states: `placed`, `completed`, `fulfilled`
- Access control denies `update` and `delete` operations for locked orders
- Applies to both customers and vendors (admin override allowed)

**Enforcement Points:**
1. **Entity access hook** - Prevents UI access
2. **Entity storage** - Prevents direct entity save/delete operations
3. **Works even if UI is bypassed** - Access control is enforced at entity level

---

### Task 3: CSV Export Hardening ✅

**File:** `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`

**Changes:**
1. **Entity storage loading** - Loads paragraphs via entity storage (not Views)
2. **Access checks** - Checks entity access for each paragraph before including in export
3. **Event filtering** - Verifies paragraph belongs to requested event
4. **No Views access assumptions** - Removed reliance on Views access filtering

**Flow:**
1. Query `attendee_answer` paragraphs via entity storage
2. For each paragraph:
   - Check entity access (`view` operation)
   - Verify event ownership (if event filter provided)
   - Include in CSV only if access granted
3. Generate CSV with accessible data only

---

### Task 4: Kernel Tests ✅

**File:** `web/modules/custom/myeventlane_checkout_paragraph/tests/src/Kernel/AttendeeParagraphAccessTest.php`

**Test Cases:**

1. **`testVendorCannotAccessOtherVendorEvents()`**
   - Creates event owned by other user
   - Creates order with attendee paragraph
   - Asserts vendor user cannot view paragraph

2. **`testCustomerCannotAccessOtherCustomerOrders()`**
   - Creates order owned by customer user
   - Asserts other user cannot view paragraph

3. **`testAttendeeParagraphImmutableAfterOrderPlacement()`**
   - Creates order in draft state
   - Asserts customer can update paragraph
   - Places order
   - Asserts customer cannot update or delete paragraph

4. **`testCustomerCanViewOwnAttendeeParagraphs()`**
   - Creates order owned by customer
   - Asserts customer can view paragraph

---

## Access Control Flow

### View Access Flow
```
User requests to view attendee paragraph
  ↓
hook_entity_access() called
  ↓
Is user admin? → YES → Allow
  ↓ NO
Is user anonymous? → YES → Deny
  ↓ NO
Resolve paragraph → order item → order → event
  ↓
Is user customer? → Check order.customer_id === user.id
  ↓
Is user vendor? → Check VendorOwnershipResolver.vendorOwnsEvent()
  ↓
If customer OR vendor match → Allow view
  ↓
Otherwise → Deny
```

### Update/Delete Access Flow
```
User requests to update/delete attendee paragraph
  ↓
hook_entity_access() called
  ↓
Is user admin? → YES → Allow
  ↓ NO
Resolve paragraph → order item → order
  ↓
Is order locked? (placed/completed/fulfilled) → YES → Deny
  ↓ NO
Is user customer? → Check order.customer_id === user.id
  ↓ YES → Allow
  ↓ NO → Deny
```

---

## Service Registration

**File:** `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.services.yml`

```yaml
services:
  myeventlane_checkout_paragraph.access_resolver:
    class: Drupal\myeventlane_checkout_paragraph\Service\AttendeeParagraphAccessResolver
    arguments: ['@entity_type.manager']
```

---

## Files Created/Modified

### New Files:
1. `src/Service/AttendeeParagraphAccessResolver.php` - Relationship resolution service
2. `tests/src/Kernel/AttendeeParagraphAccessTest.php` - Kernel tests
3. `myeventlane_checkout_paragraph.services.yml` - Service registration

### Modified Files:
1. `myeventlane_checkout_paragraph.module` - Added `hook_entity_access()`
2. `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php` - Hardened CSV export

---

## Testing Guide

### Manual Test 1: Customer Access

**Steps:**
1. Log in as Customer A
2. Create an order with attendee data
3. Try to view attendee paragraph → Should succeed
4. Try to edit attendee paragraph (draft order) → Should succeed
5. Place the order
6. Try to edit attendee paragraph → Should fail (immutable)

**Expected:**
- Customer can view and edit their own data before order placement
- Customer cannot edit after order placement

---

### Manual Test 2: Vendor Access

**Steps:**
1. Log in as Vendor A
2. Create an event owned by Vendor A
3. Customer B purchases tickets for Vendor A's event
4. Log in as Vendor A
5. Try to view attendee paragraphs for Vendor A's event → Should succeed
6. Try to edit attendee paragraphs → Should fail (vendors cannot edit)

**Expected:**
- Vendor can view attendee data for their own events
- Vendor cannot edit attendee data

---

### Manual Test 3: Cross-Vendor Access

**Steps:**
1. Log in as Vendor A
2. Create an event owned by Vendor A
3. Customer B purchases tickets
4. Log in as Vendor B
5. Try to view attendee paragraphs for Vendor A's event → Should fail

**Expected:**
- Vendor cannot view attendee data for events they don't own

---

### Manual Test 4: CSV Export Access

**Steps:**
1. Log in as Vendor A
2. Navigate to vendor dashboard
3. Export CSV for Vendor A's event → Should include attendee data
4. Log in as Vendor B
5. Try to export CSV for Vendor A's event → Should be empty (no access)

**Expected:**
- CSV export respects entity access checks
- Only accessible paragraphs are included in export

---

## Edge Cases Handled

✅ **Orphan paragraphs** - Paragraphs without parent order items are denied access (safety first)

✅ **Missing relationships** - If order/event cannot be resolved, access is denied

✅ **State machine errors** - If order state cannot be determined, assumes unlocked (safer for creation)

✅ **Multiple order items** - Each paragraph is checked independently

✅ **Nested questions** - Access control applies to parent `attendee_answer` paragraph

---

## Security Considerations

1. **Defense in depth** - Access control enforced at entity level, not just UI
2. **Fail secure** - If relationships cannot be resolved, access is denied
3. **Admin override** - Admins can always access (for support/debugging)
4. **Immutability** - Data cannot be modified after order placement (prevents fraud)
5. **Vendor isolation** - Vendors cannot access data from other vendors' events

---

## Integration Points

- **VendorOwnershipResolver** - Used to verify vendor ownership of events
- **Commerce Order States** - Uses state machine to determine locked state
- **Entity Access System** - Integrates with Drupal's entity access system
- **CSV Export** - Uses entity access checks for export filtering

---

## Next Steps

- **Phase 5:** Donation pane implementation
- **Future:** Consider adding audit logging for access attempts

---

**END OF PHASE 4 DOCUMENTATION**

