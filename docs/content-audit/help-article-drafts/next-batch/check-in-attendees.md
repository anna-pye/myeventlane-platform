---
title: Checking in attendees at your event
audience: vendor
article_type: guide
product_area: operations
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/check-in-attendees
source_evidence: myeventlane_event_attendees vendor operations door; myeventlane_tickets ticket scan; myeventlane_rsvp RSVP check-in
needs_verification: Camera QR behaviour on specific devices; RSVP QR issuance when no commerce ticket
---

# Checking in attendees at your event

Check-in helps you see who has arrived and reduce queues at the door. This page covers the check-in tools organisers use on event day.

## What this means

Check-in marks an attendee or ticket holder as arrived. **Paid ticket events** use Door Mode and the ticket scanner. **RSVP events** use a separate RSVP check-in list and scan flow. Not every booking includes a scannable QR code — some guests may present email confirmation or match by name on the guest list.

## What to do next

1. Sign in on a phone or laptop with your organiser account.
2. Open the event and go to **Operations** (`/vendor/events/{event}/operations`) for the event-day hub (live list, export, door tools).
3. Open **Door Mode** (`/vendor/events/{event}/operations/door`) as the primary check-in surface:
   - **Start scan** for camera QR check-in when guests have a scannable ticket.
   - **Type a code instead** for manual entry when the camera is unavailable.
   - Use **search** on the door screen to find attendees by name or email when scan fails.
4. For paid tickets only, you can also use the **ticket scanner** at `/event/{event}/tickets/scan` (secondary to Door Mode; same permission and ownership rules).
5. For **RSVP-only** events, use **RSVP check-in** at `/vendor/event/{event}/rsvps/checkin` (list and check-in controls). RSVP QR scan, when available, is at `/vendor/event/{event}/scan` — not the paid ticket scanner.
6. Review the **guest list** at `/vendor/events/{event}/attendees` during the session. Export from `/vendor/events/{event}/attendees/export` when you need a spreadsheet.

## Good to know

- **QR codes** are available when tickets with QR were issued. RSVP-only guests may not have a paid-ticket QR until a ticket is assigned.
- Check-in access is limited to your team and roles with permission. Do not share organiser passwords on shared devices.
- Poor connectivity can delay updates. Refresh the list or retry the scan after signal improves.
- **Exports** can include names, emails, phone numbers, custom answers, ticket codes, and check-in state. Combined attendee export supports `?obfuscate=1` to mask emails; RSVP-only export does not obfuscate by default — treat exports as sensitive.
- Checked-in status is operational data — use it for door control, not as a legal attendance record unless your policies say otherwise.
- Do not use legacy `/vendor/events/{event}/check-in` URLs for training; they redirect or are superseded by Operations and Door Mode.

## Related help

- How to access your tickets
- Managing attendees and orders for your event
- Ticket sales and capacity
- Managing your event dashboard
