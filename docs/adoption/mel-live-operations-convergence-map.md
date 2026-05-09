# MEL live operations — mutation path convergence map

This document traces **production code paths** that change **event attendee check-in state** (canonical `event_attendee` + ticket `attendee_answer` mirror where applicable). It was produced during implementation of the venue operations slice (May 2026).

## Canonical authority (after slice)

| Component | Role |
|-----------|------|
| `MelAttendeeCheckinManager` (`myeventlane_checkout_flow`) | **Single write authority** for `EventAttendee` check-in / undo, paragraph mirror sync, access via `MelAttendeeOperationsAccess` → `EventVendorAccessCheckerInterface`. |
| `AttendanceManager::checkIn()` | **Internal** transition used only by `MelAttendeeCheckinManager` (still calls `EventAttendee::checkIn()`). |
| `EventAttendee::checkIn()` | **Entity API**; must not be called from controllers/forms/API once manager is available—only from `AttendanceManager` / legacy fallbacks. |

## Route map — operational surfaces

| Route name | Path | Writer |
|------------|------|--------|
| `myeventlane_event_attendees.vendor_operations` | `/vendor/events/{node}/operations` | None (read-only VM); links to other surfaces. |
| `myeventlane_event_attendees.vendor_checkin` | `POST /vendor/attendee/{event_attendee}/checkin` | `MelAttendeeCheckinManager::checkInAttendee` when service exists; else `AttendanceManager`. |
| `myeventlane_checkout_flow.vendor_checkin_scan` | `/vendor/check-in/scan/{token}` | `MelAttendeeCheckinManager::checkInForTicketParagraph` |
| `myeventlane_checkout_flow.vendor_checkin_action` | Form on paragraph | `MelAttendeeCheckinManager::checkInForTicketParagraph` / `undoCheckInForTicketParagraph` |
| `myeventlane_event.checkin_validate` | Door JSON | `MelAttendeeCheckinManager::checkInForTicketParagraph` (requires `myeventlane_checkout_flow`; otherwise **503**). |
| `myeventlane_checkin.toggle` | JSON toggle | `CheckInStorage::toggleCheckIn` → `markManualOverride` for ticket `event_attendee` when manager present. |
| `myeventlane_rsvp` QR validate (Commerce branch) | JSON | `MelAttendeeCheckinManager::checkInAttendee` when service present; else `AttendanceManager` / entity. |
| `myeventlane_rsvp` QR validate (legacy `rsvp_submission`) | JSON | **`RsvpSubmission::checkIn()`** — separate storage; **not** `EventAttendee`. |
| Vendor REST `VendorAttendeeApiController::checkIn` | JSON | `markManualOverride` when manager present; else direct entity mutation. |

## Paths that still bypass `MelAttendeeCheckinManager` (documented exceptions)

1. **`TicketCheckinService` (`myeventlane_tickets`)** — mutates **Commerce ticket** entities (`mel_ticket` / order item ticket record), not `event_attendee`. Out of scope for this slice; still a parallel “checked in” concept for ticket SKU scanning.
2. **Legacy RSVP entity** — `QrCheckinController::validateLegacyRsvpQr` uses `rsvp_submission` rows; no `EventAttendee`.
3. **`TicketAttendee::checkIn()`** — adapter used when `CheckInStorage` falls back (no checkout_flow manager). Avoid disabling `myeventlane_checkout_flow` on environments that rely on mirror parity.
4. **`AttendanceManager::checkIn()`** — callable only from `MelAttendeeCheckinManager` for canonical path.

## Access stack

- `MelAttendeeOperationsAccess::canCheckInAttendees()` composes **`EventVendorAccessCheckerInterface`** plus staff permissions (`administer event attendees`, `administer commerce_order`).
- `VendorAttendeeController::access` / `VendorConsoleBaseController::assertEventOwnership` remain for route-level vendor console gates; they align with vendor membership rules (not a second ownership system).

## Observability

- `MelAttendeeCheckinManager` continues to emit **structured `watchdog` notices/warnings** on success, denial, and idempotent hits.
- `myeventlane_checkout_flow_mel_observability_page_payload_alter()` includes route `myeventlane_event_attendees.vendor_operations` in its staff trace allowlist (surface-gated as before).
