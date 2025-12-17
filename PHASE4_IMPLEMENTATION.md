# Phase 4: RSVP and Ticketing Pipelines - Implementation

## Implementation Status

### Task 1: Stock Enforcement for Tickets ⚠️
**Status**: Needs Commerce Stock module or custom stock tracking
**Action**: For now, we'll add validation that checks if variations have stock fields and validate accordingly. Full stock enforcement requires Commerce Stock module installation.

### Task 2: RSVP Donation Field ✅
**Status**: Ready to implement
**Action**: Add optional donation field to RSVP form

### Task 3: Waitlist Auto-Invite ⚠️
**Status**: Partially implemented (sendWaitlistPromotion exists)
**Action**: Create queue worker or cron hook to check for available spots

### Task 4: My Tickets ICS Links ✅
**Status**: Ready to implement
**Action**: Add ICS download links to customer dashboard

### Task 5: Verify Attendee Data Storage ✅
**Status**: Already implemented
**Action**: Verify AttendeeInfoPerTicket saves to field_attendee_data

## Implementation Order

1. **RSVP Donation Field** (Quick win)
2. **My Tickets ICS Links** (Quick win)
3. **Stock Enforcement** (Requires Commerce Stock or custom implementation)
4. **Waitlist Auto-Invite** (Requires queue/cron setup)




















