# Phase 4: RSVP and Ticketing Pipelines - Status

## Current Assessment

### ✅ Already Working
- RSVP form with capacity checking
- Waitlist functionality (status: 'waitlist' vs 'confirmed')
- Email confirmation
- ICS download for events
- TicketMatrixForm (TicketSelectionForm)
- Per-ticket attendee fields with Paragraphs support
- My Tickets page (Customer Dashboard)

### 🔧 Needs Enhancement

1. **RSVP Donation Field** - Add optional donation amount
2. **My Tickets ICS Links** - Add ICS download links per event
3. **Stock Enforcement** - Add stock checking to ticket form (requires Commerce Stock module)
4. **Waitlist Auto-Invite** - Automatically promote waitlist entries when spots open

## Implementation Plan

Given the complexity and that most features already exist, I'll focus on:
1. Adding donation field to RSVP (quick enhancement)
2. Adding ICS links to My Tickets page (quick enhancement)
3. Documenting stock enforcement requirements
4. Creating waitlist promotion mechanism

## Next Steps

The codebase already has most Phase 4 functionality. The enhancements needed are:
- Minor additions (donation field, ICS links)
- Documentation for stock enforcement (requires Commerce Stock module)
- Waitlist promotion can be manual or automated via queue

Would you like me to:
1. Implement the quick wins (donation field + ICS links)?
2. Set up Commerce Stock module for full stock enforcement?
3. Create a waitlist promotion queue worker?




















