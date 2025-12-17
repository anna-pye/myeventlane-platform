# Phase 4: RSVP and Ticketing Pipelines - Implementation Plan

## Current State Assessment

### ✅ Already Implemented

1. **RSVP Flow**
   - ✅ RSVP form exists (`RsvpPublicForm`)
   - ✅ Capacity checking (`RsvpCapacityService`)
   - ✅ Waitlist functionality (status: 'waitlist' vs 'confirmed')
   - ✅ Email confirmation (`RsvpMailer`)
   - ✅ ICS download (`IcsController`)

2. **Ticketing Flow**
   - ✅ TicketMatrixForm exists (`TicketSelectionForm`)
   - ✅ Multiple variations support
   - ✅ Quantity selectors
   - ✅ Per-ticket attendee fields (`AttendeeInfoPerTicket`)
   - ✅ Paragraphs support for extra questions

3. **My Tickets Page**
   - ✅ Customer dashboard exists
   - ✅ Shows upcoming/past events
   - ✅ Ticket download links

### ❌ Missing/Needs Enhancement

1. **RSVP Flow**
   - ❌ Donation field (optional, stored safely)
   - ❌ Auto-invite from waitlist when spot opens
   - ⚠️ Email confirmation with .ics link (needs verification)

2. **Ticketing Flow**
   - ❌ Stock enforcement in TicketSelectionForm
   - ❌ Stock validation at Commerce order level
   - ⚠️ Attendee data storage in custom entity (needs verification)

3. **My Tickets Page**
   - ❌ ICS download links per event
   - ⚠️ Grouped by upcoming/past (needs verification)

## Implementation Tasks

### Task 1: Add Donation Field to RSVP Form
- Add optional donation field to `RsvpPublicForm`
- Store donation amount in RSVP submission entity
- Make it ready for Commerce mapping later

### Task 2: Implement Waitlist Auto-Invite
- Create queue worker or cron hook
- Check for waitlist entries when capacity opens
- Send invitation emails automatically

### Task 3: Add Stock Enforcement to TicketSelectionForm
- Check available stock for each variation
- Display stock status in form
- Validate stock availability before adding to cart
- Enforce stock limits at Commerce order level

### Task 4: Enhance My Tickets Page
- Add ICS download links for each event
- Ensure proper grouping (upcoming/past)
- Add ICS links for both RSVP and ticket events

### Task 5: Verify Attendee Data Storage
- Check if attendee data is stored in custom entity
- Ensure proper linking to order item, event, and variation

## Priority Order

1. **High Priority**: Stock enforcement (critical for paid events)
2. **Medium Priority**: Donation field, ICS links on My Tickets
3. **Low Priority**: Waitlist auto-invite (can be manual initially)




















