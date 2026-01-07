# Phase 8: Vendor Attendee & Sales Overview

**Date:** 2024-12-27  
**Status:** ✅ Complete  
**Goal:** Implement secure, vendor-only attendee and order overview experience

---

## Summary

Phase 8 implements a comprehensive vendor dashboard for managing attendees and viewing sales:

1. **Vendor Attendee Dashboard** - Lists all events owned by vendor with stats
2. **Per-Event Attendee List** - Shows all attendees for a specific event
3. **CSV Export Integration** - Reuses existing CSV export with access control
4. **Sales Summary** - Shows tickets sold, attendee count, and revenue per event

All access control relies on entity access - vendors can only see data for their own events.

---

## Implementation Details

### Task 1: Vendor Attendee Dashboard ✅

**File:** `web/modules/custom/myeventlane_checkout_flow/src/Controller/VendorAttendeesController.php`

**Route:** `/vendor/attendees`

**Features:**
- Lists all events owned by the vendor
- For each event shows:
  - Event title (linked to event page)
  - Event date
  - Tickets sold count
  - Attendee count
  - Gross revenue (ticket revenue only, excludes donations)
  - "View Attendees" CTA
  - "Export CSV" button

**Access Control:**
- Custom access callback checks if user is a vendor
- Admin users always allowed
- Uses `VendorOwnershipResolver` to verify vendor status
- Denies access to non-vendors

**Template:** `myeventlane-vendor-attendees-dashboard.html.twig`

---

### Task 2: Per-Event Attendee List ✅

**File:** `web/modules/custom/myeventlane_checkout_flow/src/Controller/VendorAttendeesController.php`

**Route:** `/vendor/events/{node}/attendees`

**Features:**
- Displays all attendees for a specific event
- Read-only table showing:
  - Attendee name
  - Email
  - Ticket type
  - Order number (linked to order)
- Sales summary at top:
  - Tickets sold
  - Total attendees
  - Gross revenue
- CSV export button

**Access Control:**
- Verifies vendor owns the event using `VendorOwnershipResolver`
- Uses entity access to filter attendee paragraphs
- Only paragraphs vendor can view are included
- Relies on Phase 4 access control

**Template:** `myeventlane-vendor-event-attendees.html.twig`

---

### Task 3: CSV Export Integration ✅

**Implementation:**
- Reuses existing `AttendeeCsvController` from `myeventlane_views`
- Route: `/dashboard/attendees/export?download_csv={event_id}`
- CSV export respects paragraph access checks (Phase 4)
- Only attendees vendor can view are included in export

**CSV Format:**
- Columns: First name, Last name, Email, Phone, Question, Answer
- One row per attendee (or per question if extra questions exist)

---

### Task 4: Sales Summary ✅

**Implementation:**
- Calculated per event in `calculateEventStats()` method
- Metrics:
  - **Tickets Sold** - Total quantity of ticket order items (excludes donations)
  - **Attendee Count** - Count of attendee paragraphs vendor can access
  - **Gross Revenue** - Sum of ticket order item totals (excludes donations)

**Data Source:**
- Queries order items with `field_target_event` matching event ID
- Filters to completed orders only
- Excludes donation order items
- Uses entity access to count accessible attendees

**Display:**
- Shown on dashboard (per event)
- Shown on attendee list page (for specific event)

---

## Files Created/Modified

### New Files:
1. `src/Controller/VendorAttendeesController.php` - Controller for vendor attendee pages
2. `templates/myeventlane-vendor-attendees-dashboard.html.twig` - Dashboard template
3. `templates/myeventlane-vendor-event-attendees.html.twig` - Attendee list template
4. `docs/phase-8-vendor-attendees.md` - Documentation

### Modified Files:
1. `myeventlane_checkout_flow.routing.yml` - Added vendor routes
2. `myeventlane_checkout_flow.module` - Added theme hooks

---

## Route Configuration

```yaml
myeventlane_checkout_flow.vendor_attendees:
  path: '/vendor/attendees'
  defaults:
    _controller: '\Drupal\myeventlane_checkout_flow\Controller\VendorAttendeesController::dashboard'
    _title: 'Attendees & Sales'
  requirements:
    _permission: 'access content'
    _custom_access: '\Drupal\myeventlane_checkout_flow\Controller\VendorAttendeesController::checkAccess'

myeventlane_checkout_flow.vendor_event_attendees:
  path: '/vendor/events/{node}/attendees'
  defaults:
    _controller: '\Drupal\myeventlane_checkout_flow\Controller\VendorAttendeesController::eventAttendees'
    _title_callback: '\Drupal\myeventlane_checkout_flow\Controller\VendorAttendeesController::eventAttendeesTitle'
  requirements:
    _entity_access: 'node.view'
    node: \d+
  options:
    parameters:
      node:
        type: entity:node
```

---

## Access Control

### Dashboard Access
- **Vendor Check:** Uses `VendorOwnershipResolver::getStoreForUser()` to verify vendor status
- **Admin Override:** Admins always allowed
- **Result:** Only vendors and admins can access

### Event Attendee List Access
- **Ownership Check:** Verifies vendor owns event using `VendorOwnershipResolver::vendorOwnsEvent()`
- **Entity Access:** Uses paragraph entity access (Phase 4) to filter attendees
- **Result:** Vendors can only view attendees for their own events

### Attendee Data Access
- **Entity Access:** Relies on Phase 4 access control for attendee paragraphs
- **No Manual Checks:** All filtering via entity access system
- **Result:** Only paragraphs vendor can view are included

---

## Data Flow

### Getting Vendor Events

1. Get vendor store via `VendorOwnershipResolver::getStoreForUser()`
2. Query events by:
   - Method 1: `field_event_vendor` → vendor entity → `field_vendor_store` → store
   - Method 2: Fallback to event owner matching store owner
3. Filter to published events only
4. Sort by event start date (descending)

### Getting Event Attendees

1. Query order items with `field_target_event` = event ID
2. Filter to completed orders
3. For each order item:
   - Get `field_ticket_holder` paragraphs
   - Check entity access for each paragraph
   - Only include paragraphs vendor can view
4. Extract attendee data (name, email, ticket type, order number)

### Calculating Stats

1. Query order items for event
2. Filter to completed orders
3. Exclude donation items
4. Count tickets: sum of quantities
5. Count attendees: count accessible paragraphs
6. Calculate revenue: sum of ticket item totals

---

## UI Components

### Dashboard Card
```
┌─────────────────────────────────────┐
│  Event Name (link)                  │
│  📅 Date                            │
│                                     │
│  Tickets Sold: 50                   │
│  Attendees: 45                      │
│  Revenue: $5,000.00                 │
│                                     │
│  [View Attendees] [📥 Export CSV]   │
└─────────────────────────────────────┘
```

### Attendee List Page
```
┌─────────────────────────────────────┐
│  ← Back to Attendees & Sales         │
│  Attendees for Event Name           │
│  📅 Date | 📍 Location               │
├─────────────────────────────────────┤
│  Sales Summary                      │
│  Tickets: 50 | Attendees: 45        │
│  Revenue: $5,000.00                  │
├─────────────────────────────────────┤
│  Attendees (45) [📥 Export CSV]      │
│  ┌─────────────────────────────┐   │
│  │ Name | Email | Type | Order │   │
│  │ ...  | ...   | ...  | ...   │   │
│  └─────────────────────────────┘   │
└─────────────────────────────────────┘
```

---

## Edge Cases Handled

✅ **No events** - Shows empty state message

✅ **No attendees** - Shows empty state in attendee list

✅ **Multiple events** - All events displayed on dashboard

✅ **Donation items** - Excluded from ticket counts and revenue

✅ **Incomplete orders** - Only completed orders included

✅ **Access denied paragraphs** - Filtered out via entity access

✅ **Non-vendor access** - Denied with clear message

✅ **Other vendor's events** - Access denied

---

## Security Considerations

1. **Vendor verification** - Uses `VendorOwnershipResolver` to verify vendor status
2. **Event ownership** - Verifies vendor owns event before showing attendees
3. **Entity access** - All attendee data filtered via paragraph entity access
4. **No manual checks** - Relies entirely on Drupal's access system
5. **Read-only** - No edit actions available

---

## Integration Points

- **VendorOwnershipResolver** - Verifies vendor status and event ownership
- **Attendee Paragraphs** - Reads from `field_ticket_holder` paragraphs
- **Entity Access** - Uses Phase 4 access control for paragraphs
- **CSV Export** - Reuses `AttendeeCsvController` from `myeventlane_views`
- **Commerce Orders** - Queries order items for event stats

---

## Manual Test Steps

1. **Dashboard Access:**
   - Log in as vendor
   - Navigate to `/vendor/attendees`
   - Verify events owned by vendor are listed
   - Verify stats are correct (tickets, attendees, revenue)
   - Test as non-vendor (should be denied)

2. **Attendee List:**
   - Click "View Attendees" on an event
   - Verify attendee table displays correctly
   - Verify all attendees are for this event
   - Verify order numbers are linked
   - Try to access another vendor's event (should be denied)

3. **CSV Export:**
   - Click "Export CSV" button
   - Verify CSV downloads
   - Verify CSV contains only accessible attendees
   - Verify CSV format is correct

4. **Sales Summary:**
   - Verify ticket counts match order items
   - Verify attendee counts match accessible paragraphs
   - Verify revenue excludes donations
   - Verify only completed orders included

---

## Next Steps

- **Future:** Add filtering/search for attendees
- **Future:** Add pagination for large attendee lists
- **Future:** Add date range filtering for events
- **Future:** Add revenue breakdown by ticket type

---

**END OF PHASE 8 DOCUMENTATION**

