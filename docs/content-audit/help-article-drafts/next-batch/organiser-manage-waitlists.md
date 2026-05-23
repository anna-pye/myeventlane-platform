---
title: Managing waitlists for your event
audience: vendor
article_type: guide
product_area: capacity
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/organiser-manage-waitlists
source_evidence: myeventlane_event_attendees.waitlist_manage; myeventlane_event_attendees.waitlist_export; mel_ticket_type.waitlist_enabled; TicketTierWaitlistService; TicketSelectionForm; published "Joining a waitlist"
needs_verification: Paid-tier organiser list/export; Event Studio waitlist toggles; paid auto-offer email on staging; RSVP auto-promote end-to-end; browser nav to waitlist page
---

# Managing waitlists for your event

When demand exceeds capacity, waitlists let you capture interest. **Free RSVP / attendance waitlists** and **paid ticket waitlists** use different data and screens in MyEventLane. This guide covers what organisers can rely on today.

## What this means

### RSVP and attendance waitlist (free events)

Waitlisted guests are stored as **event attendees** with status **waitlist** (not as paid ticket waitlist entries).

- **Organiser list and export:** `/vendor/event/{event}/waitlist` and `/vendor/event/{event}/waitlist/export` — verified in code (`WaitlistManagementController`, `AttendanceWaitlistManager`).
- **Public join:** guests can use `/event/{event}/waitlist/signup` when the event is full and a waitlist is offered (`WaitlistSignupForm`).
- **Not on this page:** legacy **RSVP submissions** (`rsvp_submission`) may appear on **Manage RSVPs** (`/vendor/event/{event}/rsvps`) or check-in lists — that is a separate list from the waitlist page above.

Optional event setting **`field_waitlist_capacity`** limits how many people can join the waitlist (leave empty for unlimited in the data model).

### Paid ticket waitlist (buyers on the book page)

A waitlist entry is **not** a ticket. For a **sold-out paid ticket type** where waitlist is enabled on that type, buyers submit email and quantity on the event **book** page. Entries live in **`mel_ticket_waitlist_entry`**, separate from RSVP attendee lists.

- **Organisers:** there is **no verified vendor list or CSV export** for paid ticket waitlist entries at the time of this audit.
- **Do not** use `/vendor/event/{event}/waitlist` for paid ticket waitlist emails — that route lists attendance waitlist guests only.

See the attendee article *Joining a waitlist* for buyer steps (cross-link only; do not duplicate here).

## What to do next

### RSVP waitlist — organiser steps (verified)

1. Sign in to your organiser dashboard.
2. Open the event and go to **Waitlist** (`/vendor/event/{event}/waitlist`).
3. Review the table: position, name, email, date added, and status.
4. Use **Export CSV** on that page when you need a spreadsheet for your team.
5. When capacity opens, you may need to adjust event or RSVP capacity and follow up with guests manually — **automatic promotion email for attendance waitlist is not verified end-to-end** in this pass (see verification log). Do not assume guests are promoted without checking the list.

Guests who are full on the main RSVP booking flow may see a message to join the waitlist; the dedicated signup path is `/event/{event}/waitlist/signup`.

### Paid ticket waitlist — what organisers can do today

1. Ensure each paid ticket type has a **capacity** and, where your workflow allows, enable **Waitlist when sold out** and optional **Auto-offer waitlist when tickets free up** on that ticket type in the data model.
2. **Event Studio tier cards** currently save title, price, and capacity only — **waitlist toggles are not in the Event Studio save payload** (`mel-event-studio.js`). Enabling paid waitlist may require another workflow or support until self-service controls ship.
3. Tell buyers to use the public **book** page when a type is sold out — see *Joining a waitlist*.
4. When places return (cancellations, refunds, or capacity changes), **auto-offer** may email time-limited purchase links **only** where **Auto-offer waitlist when tickets free up** is enabled on the type and the automation runs — **not verified on staging** in this pass. Buyers must still **complete checkout** to receive tickets.
5. For a spreadsheet of paid waitlist emails, plan **manual outreach or support** — no organiser export route was found for `mel_ticket_waitlist_entry`.

## Good to know

- Paid waitlist join appears only when the type is **sold out**, waitlist is **enabled** on that type, and the type has a finite capacity.
- Active paid offers can **hold** capacity until they expire or are used — reconcile with ticket sales and the book page.
- **Refunds restoring paid capacity** were not re-verified in this pass; confirm availability on your dashboard before promising extra places.
- Treat waitlist exports and emails as **personal information**. Use them only for the relevant event; store CSV files securely; do not share in public channels.

## Related help

- Joining a waitlist (attendees — public book page and offers)
- Ticket sales and capacity
- Check-in attendees (RSVP check-in may show a separate legacy waitlist section)
- Managing your event dashboard
