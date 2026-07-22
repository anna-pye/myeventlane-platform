# VX2 Sprint 4 — Implementation notes

**Epic:** VX2-05 Attendees  
**Branch:** `feature/vx2-attendee-workspace`  
**Date:** 2026-07-22

## What shipped

- One Attendee Workspace in Event Workspace (`workspace_attendees` → `attendees_stack`)
- Guest cards with name, ticket/RSVP, booking status, check-in, order reference, quick actions
- Immediate search + filters (ticket, RSVP, waitlist, checked in / not, refunded, cancelled, ticket type)
- Door Mode treated as Attendees mode; legacy check-in stacks redirect
- Message / Export attendees / Refund entry points (no messaging rebuild)
- AU English empty states
- Instrumentation hooks documented (logger + JS CustomEvent)

## Metrics (instrumentation)

| Hook | Where | Pipeline |
| --- | --- | --- |
| `attendee_viewed` | `EventAttendeeWorkspaceBuilder` logger | Deferred |
| `attendee_checked_in` | Card check-in form JS + existing check-in services | Deferred |
| `attendee_exported` | Export CTA `data-mel-attendee-analytics` | Deferred |
| `attendee_filtered` | Filter chips / ticket type JS | Deferred |
| `door_mode_opened` | Door Mode CTA analytics attr | Deferred |
| `message_attendees_clicked` | Message CTA analytics attr | Deferred |

## Manual QA checklist

- [ ] Small paid event — cards, search, export
- [ ] Large event (≥100) — dense layout
- [ ] RSVP-only — RSVP filter; no separate RSVPs tab
- [ ] Mixed paid + RSVP
- [ ] Waitlist filter via legacy waitlist URL redirect
- [ ] Door Mode from Attendees + legacy `/check-in`
- [ ] Message attendees entry
- [ ] Refund entry on ticketed guest with order
- [ ] Desktop / tablet / 390px mobile
- [ ] Keyboard: search, filter chips, action buttons
- [ ] Screen reader: list region, live status, button pressed state

## Inventory

See `docs/implementation/vx2-sprint4-attendee-surface-inventory.md`.
