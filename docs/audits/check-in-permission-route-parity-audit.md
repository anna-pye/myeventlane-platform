# Check-in permission and route parity audit

**Date:** 2026-05-23  
**Scope:** Vendor organiser event-day check-in, door mode, ticket scanner, RSVP check-in, legacy `myeventlane_checkin` routes.

## Summary

| Issue | Fix |
|-------|-----|
| Legacy routes required non-existent permissions (`myeventlane_checkin.*`) | Routes updated to use machine names from `myeventlane_checkin.permissions.yml` |
| Vendor role lacked `check in tickets`, `scan qr codes`, `toggle check-in status` | Granted on `user.role.vendor` |
| No role had `check in tickets` | Vendor role now has it; `EventTicketsAccess` still enforces event ownership |
| Anonymous/authenticated had `access check-in` (and authenticated had scan/toggle) | Removed from anonymous and authenticated |
| Legacy `/check-in/scan` referenced missing `js/scan.js` | Controller redirects 302 to canonical door route |
| Vendor UI linked legacy check-in and ticket scanner without permission parity | UI links point to `/operations/door`; ticket scanner accessible with new vendor permissions |

**Canonical organiser event-day path:** `/vendor/events/{node}/operations/door` (Door Mode: unified attendee + paid ticket scanner via `myeventlane_tickets/checkin_scanner` library).

---

## Route audit

### Canonical vendor operations (myeventlane_event_attendees + myeventlane_checkout_flow)

| Route | Path | Controller | Permission / access | Ownership check | Linked from UI | Status | Fix decision |
|-------|------|------------|---------------------|-----------------|----------------|--------|--------------|
| `myeventlane_event_attendees.vendor_operations` | `/vendor/events/{node}/operations` | `VendorEventOperationsController::page` | `VendorConsoleAccess` | `assertEventOwnership` | Operations tab, venue ops actions | OK | Keep canonical hub |
| `myeventlane_event_attendees.vendor_operations_door` | `/vendor/events/{node}/operations/door` | `VendorEventOperationsController::doorPage` | `VendorConsoleAccess` | `assertEventOwnership` + `MelAttendeeOperationsAccess::canCheckInAttendees` | Dashboard quick actions, attendees, door CTA | OK | **Primary check-in surface** |
| `myeventlane_event_attendees.vendor_operations_door_validate` | `/vendor/events/{node}/operations/door/validate` | `VendorEventOperationsController::doorValidate` | `VendorConsoleAccess` | Same as door page | Door Mode JS | OK | Keep; JSON mutation path |
| `myeventlane_event_attendees.vendor_list` | `/vendor/events/{node}/attendees` | `VendorEventAttendeesController::attendees` | `VendorConsoleAccess` | `assertEventOwnership` | Workspace tabs, dashboard | OK | Keep |
| `myeventlane_event_attendees.vendor_export` | `/vendor/events/{node}/attendees/export` | `VendorAttendeeController::export` | Custom `VendorAttendeeController::access` | Event ownership via access service | Operations export action | OK | Keep |
| `myeventlane_event_attendees.vendor_checkin` | `/vendor/attendee/{event_attendee}/checkin` | `VendorAttendeeController::checkIn` | Custom `accessAttendee` | Row + event scoped | Operations list POST | OK | Keep |

### Paid ticket scanner (myeventlane_tickets)

| Route | Path | Controller | Permission / access | Ownership check | Linked from UI | Status | Fix decision |
|-------|------|------------|---------------------|-----------------|----------------|--------|--------------|
| `myeventlane_tickets.ticket_scan` | `/event/{event}/tickets/scan` | `TicketScanController::page` | `check in tickets` + `EventTicketsAccess` | Vendor console + workspace parity | Event Studio, event overview, ticket manager | Was 403 for vendors | **Grant `check in tickets` to vendor** |
| `myeventlane_tickets.ticket_checkin` | `/event/{event}/tickets/checkin` | `TicketCheckinForm` | Same | Same | Ticket task tabs | Was 403 for vendors | Same fix |
| `myeventlane_tickets.ticket_checkin_api_*` | `/event/{event}/tickets/checkin/api*` | API controllers | Same | Same | Scanner PWA | Was 403 for vendors | Same fix |

Note: Door Mode also attaches `myeventlane_tickets/checkin_scanner` when the tickets module is enabled — preferred path for event-day scanning.

### RSVP check-in (myeventlane_rsvp)

| Route | Path | Controller | Permission / access | Ownership check | Linked from UI | Status | Fix decision |
|-------|------|------------|---------------------|-----------------|----------------|--------|--------------|
| `myeventlane_rsvp.checkin_list` | `/vendor/event/{event}/rsvps/checkin` | `RsvpCheckinController::checkinPage` | `VendorEventAccess` | `manage own event rsvps` + workspace parity | RSVP console tab | OK | Keep separate from ticket scanner |
| `myeventlane_rsvp.checkin_list_pdf` | `/vendor/event/{event}/rsvps/checkin/pdf` | `RsvpCheckinController::pdf` | Same | Same | RSVP check-in page | OK | Keep |
| `myeventlane_rsvp.checkin_scan` | `/vendor/event/{event}/scan` | `QrCheckinController::scanPage` | Same | Same | RSVP flows | OK | Keep (RSVP-specific QR) |
| `myeventlane_rsvp.checkin_validate` | `/vendor/qr/validate` | `QrCheckinController::validate` | `manage own event rsvps` | POST endpoint | RSVP scan JS | OK | Keep |

### Legacy combined check-in (myeventlane_checkin)

| Route | Path | Controller | Permission / access | Ownership check | Linked from UI | Status | Fix decision |
|-------|------|------------|---------------------|-----------------|----------------|--------|--------------|
| `myeventlane_checkin.page` | `/vendor/events/{node}/check-in` | `CheckInController::page` | `access check-in` | `assertEventAccess` | **Removed from promoted UI** | Fixed permissions; not promoted | Keep for direct URLs only |
| `myeventlane_checkin.scan` | `/vendor/events/{node}/check-in/scan` | `CheckInController::scan` | `scan qr codes` | `assertEventAccess` | Was linked from legacy page | **302 → operations/door** | Do not rebuild `scan.js` |
| `myeventlane_checkin.list` | `/vendor/events/{node}/check-in/list` | `CheckInController::list` | `access check-in` | `assertEventAccess` | Legacy page | Fixed permissions | Not promoted |
| `myeventlane_checkin.toggle` | `/vendor/events/{node}/check-in/toggle/{id}` | `CheckInController::toggle` | `toggle check-in status` | `assertEventAccess` | Legacy JS | Fixed permissions | Superseded by door/ops |
| `myeventlane_checkin.search` | `/vendor/events/{node}/check-in/search` | `CheckInController::search` | `access check-in` | `assertEventAccess` | Legacy JS | Fixed permissions | Superseded by door validate |

### Public door mode (myeventlane_event)

| Route | Path | Controller | Permission / access | Ownership check | Linked from UI | Status | Fix decision |
|-------|------|------------|---------------------|-----------------|----------------|--------|--------------|
| `myeventlane_event.checkin_door` | `/event/{event}/checkin` | `CheckinController::doorMode` | Custom `checkAccess` (workspace parity / staff) | No anonymous | Staff/mobile door | OK | Separate from vendor console |
| `myeventlane_event.checkin_validate` | `/event/{event}/checkin/validate` | `CheckinController::validate` | Same | Same | Public door JS | OK | Keep |

---

## Role config changes

| Role | Before | After |
|------|--------|-------|
| **vendor** | `access check-in` only | + `check in tickets`, `scan qr codes`, `toggle check-in status`; module dep `myeventlane_tickets` |
| **anonymous** | `access check-in` | Removed (unsafe; legacy routes could pass permission gate) |
| **authenticated** | `access check-in`, `scan qr codes`, `toggle check-in status` | Removed (check-in is vendor/staff scoped via ownership checks) |
| **administrator** | `is_admin: true` (bypass) | Unchanged |

---

## Scanner asset decision

- **Legacy** `/vendor/events/{node}/check-in/scan` referenced `myeventlane_checkin/scan` library with missing `js/scan.js`.
- **Decision:** Redirect route to `myeventlane_event_attendees.vendor_operations_door` (302). Door Mode uses existing `myeventlane_event/door_checkin`, `myeventlane_checkout_flow/mel_vendor_door_checkin`, and `myeventlane_tickets/checkin_scanner` — no duplicate scanner implementation.
- Legacy `checkin-page.html.twig` scan button now links directly to door route.

---

## Residual risk

- Legacy `/vendor/events/{node}/check-in` page remains reachable for vendors with `access check-in` who know the URL; it is not linked from primary UI.
- `mel_pro` role does not inherit vendor check-in permissions; users need the vendor role (or staff admin perms) for door/scanner access.
- Help article draft still references legacy `/check-in` path; update alias guidance after publish QA (draft not changed in this fix).
