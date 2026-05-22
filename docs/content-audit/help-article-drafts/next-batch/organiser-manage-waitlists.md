---
title: Managing waitlists for your event
audience: vendor
article_type: guide
product_area: capacity
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/organiser-manage-waitlists
source_evidence: myeventlane_event_attendees.waitlist_manage (RSVP); mel_ticket_type.waitlist_enabled; TicketTierWaitlistService; TicketSelectionForm; published "Joining a waitlist"
needs_verification: Organiser UI to enable paid tier waitlist (not in Event Studio tier card payload); organiser view/export for mel_ticket_waitlist_entry; auto-offer email on staging; manual promote for paid tier waitlist
---

# Managing waitlists for your event

When demand exceeds capacity, waitlists let you capture interest. **Paid ticket waitlists** and **free RSVP waitlists** work differently in MyEventLane — this page explains what organisers can verify today.

## What this means

**Paid ticket waitlist (buyers on the book page)**  
A waitlist entry is **not** a ticket. For a **sold-out paid ticket type** where waitlist is enabled, buyers submit an email and how many places they want on the event **book** page. Entries are stored separately from RSVP guest lists.

**RSVP waitlist (free events)**  
For RSVP-style events, waitlisted guests are tracked as attendees in **waitlist** status. You review them on the event **Waitlist** page in your organiser dashboard (for example `/vendor/event/{event}/waitlist`), not via the paid ticket waitlist entity.

Do not use the RSVP waitlist page to manage **paid** ticket waitlist sign-ups — that route lists RSVP attendees only (**verified in code**).

## What to do next

### RSVP waitlist (verified organiser paths)

1. Sign in to your organiser dashboard.
2. Open the event and go to **Waitlist** (`/vendor/event/{event}/waitlist`).
3. Review entries: name, email, position, and status where shown.
4. Use **Export** on that page if you need a spreadsheet for your team.
5. When places open, adjust RSVP capacity or promote guests according to your event settings and RSVP module behaviour.

### Paid ticket waitlist (configuration and limits)

1. Ensure each paid ticket type has a **capacity** and is configured for waitlist when sold out (**Waitlist when sold out** on the ticket type in the data model).
2. Optionally enable **Auto-offer waitlist when tickets free up** on that ticket type so MyEventLane **may** email time-limited purchase offers when capacity returns — only where that option is enabled and working for your event.
3. Tell buyers to use the public **book** page to join when a type is sold out — see the attendee article *Joining a waitlist* (do not repeat those steps here).
4. When places open, capacity may return from cancellations, refunds, or capacity changes — **refunds restoring paid capacity are not verified** in this pass; confirm on your dashboard before relying on extra places.
5. There is **no verified organiser list or export** for paid ticket waitlist entries (`mel_ticket_waitlist_entry`) in the vendor console at the time of this audit — plan manual communication or support if you need a spreadsheet of paid waitlist emails.

## Good to know

- Paid ticket waitlist join is only offered when the type is **sold out**, waitlist is **enabled** on that type, and the type has a finite capacity.
- **Automatic offers** run only where **Auto-offer waitlist when tickets free up** is enabled on the ticket type. Offers are time-limited; buyers must still **complete checkout** to receive tickets. Do not promise every waitlisted buyer will receive an offer.
- Active offers can **hold** capacity until they expire or are used — check ticket sales and the book page when reconciling numbers.
- Event Studio tier cards currently save title, price, and capacity — **waitlist toggles for paid tiers were not found in the Event Studio organiser UI** during this audit. Enabling paid waitlist may require another workflow or support until self-service controls ship (**Needs verification**).
- Treat waitlist emails and exports as **personal data**. Use them only for the relevant event and keep files secure.

## Related help

- Joining a waitlist (attendees — public book page and offers)
- Ticket sales and capacity
- Having trouble checking out
- Managing your event dashboard
