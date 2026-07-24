# VX2-06 — Messages Hub implementation notes

**Epic:** VX2-06 Messages  
**Branch:** `feature/vx2-messages`  
**Date:** 2026-07-24

## What shipped

- Organiser **Messages Hub** at `/vendor/messages` (Communication Centre)
- Sections: Overview, Compose, Scheduled, History, Templates, Audience, Branding, Support
- Shell nav **Messages** points at the hub (was messaging brand settings)
- Event Workspace **Messages** panel replaces informational placeholder
- Live compose restored at `/vendor/events/{id}/promotion` (legacy redirect to placeholder removed)
- Pro `/vendor/events/{id}/message` redirects into compose
- Attendees “Message” CTA prefers live compose
- Message types: Announcement, Reminder, Important update, Cancellation, Thank you
- Audience: Everyone, Ticket holders, RSVP-only (via existing resolvers); further filters marked Soon
- AU English trust copy; no Vendor Comms / queue / mail plugin jargon in organiser UI

## Architecture

```text
/vendor/messages  ← VendorMessagesHubController
  └─ VendorMessagesHubBuilder
       ├─ VendorMessagesHistoryService (myeventlane_event_comms_log)
       ├─ TicketSalesService (managed events)
       └─ AttendeeRecipientResolver (audience counts)

Event Studio Messages ← MessagingForm
  └─ VendorMessagesHubBuilder::buildForEvent()

Compose ← VendorEventCommsForm (canonical writer)
  └─ vendor_event_comms queue → VendorEventCommsWorker
       ├─ AttendeeRecipientResolver (everyone / rsvp)
       └─ EventRecipientResolver (ticket holders)
```

## Instrumentation (documented; logger + data attributes)

| Event | Where | Pipeline |
| --- | --- | --- |
| `messages_hub_opened` | Hub builder logger + Twig `data-mel-analytics-event` | Deferred |
| `message_created` | New message CTAs | Deferred |
| `message_scheduled` | Compose submit logger | Deferred |
| `message_sent` | Worker completion logger + history marker | Deferred |
| `message_cancelled` | Not yet (no cancel API) | Deferred |
| `message_failed` | History status Failed | Deferred |
| `audience_selected` | Compose audience radios (future collector) | Deferred |
| `template_used` | Pro templates deep link | Deferred |

Analytics product wiring is deferred — events are logged / marked for a future collector.

## Manual QA checklist

- [ ] New organiser — empty events + create CTA
- [ ] Paid event with ticket holders — compose Everyone / Ticket holders counts
- [ ] RSVP event — Everyone includes RSVP guests
- [ ] Mixed event — Everyone count ≥ ticket holders
- [ ] Large / small audience
- [ ] Cancelled event — Cancel event deep link from Event Messages
- [ ] Reminder / Announcement / Important update / Thank you / Cancellation types
- [ ] Brand settings deep link
- [ ] History timeline after send
- [ ] Desktop / tablet / 390px
- [ ] Keyboard: jump links, CTAs, compose radios, focus rings
- [ ] Screen reader: section headings, KPI aria-labels, empty status

## Inventory

See `docs/implementation/vx2-06-messages-surface-inventory.md`.

## Remaining roadmap

- Ticket type / checked-in / not checked-in / waitlist / custom audience filters
- Organiser schedule UI (edit / duplicate / cancel / retry) on top of `scheduled_for`
- Wire analytics collector for documented events
- Optionally retire Pro MessageAttendeesForm stub file after traffic dies
- Soften residual admin/platform messaging jargon outside organiser surfaces
