# VX2-06 — Messages surface inventory

**Epic:** VX2-06 Messages  
**Branch:** `feature/vx2-messages`  
**Date:** 2026-07-24  
**Authority:** Vendor Experience Convergence A6 / B9 / E Messages

## Disposition

| Surface | Path | Disposition | Notes |
| --- | --- | --- | --- |
| Messages Hub | `/vendor/messages` | **KEEP (created)** | Canonical Communication Centre |
| Messaging brand | `/vendor/dashboard/messaging/brand` | **KEEP** (deep link) | Branding section under Messages |
| Event Messages (Studio) | `/vendor/events/{id}/studio/messaging` (+ `/messages` alias) | **KEEP**; body replaced | Event Communication Centre |
| Event compose (vendor_comms) | `/vendor/events/{id}/promotion` | **KEEP**; **RENAME** product language | Canonical writer; redirect-to-placeholder removed |
| Event message branding | `/vendor/events/{id}/promotion/branding` | **KEEP** (deep link) | Event override |
| Manage-event comms | `/vendor/event/{id}/comms` | **REDIRECT** → Studio Messages | Placeholder |
| Pro Message attendees | `/vendor/events/{id}/message` | **REDIRECT** → compose | Stub retired as product |
| Pro communications templates | `/vendor/pro/settings/comms` | **KEEP** | Templates deep link when Pro |
| Pro branding | `/vendor/settings/branding` | **MERGE entry** | Same brand form; hub deep-links |
| Cancel event | `/vendor/events/{id}/cancel` | **KEEP** | Adjacent cancellation workflow |
| VendorNotifyForm | (no route) | **MERGE** RSVP idea; **RETIRE** orphan UI | RSVP via AttendeeRecipientResolver |
| System reminders | schedulers / workers | **KEEP** | Read-only scheduled note on hub |
| Admin template/log controllers | orphan admin UI | **HIDE** from organiser | Not part of hub |

## Terminology

| Avoid | Prefer |
| --- | --- |
| Vendor Comms / Promotion | Messages |
| Notification queue / Mail plugin | Message / Sending |
| Attendee Messaging (product name) | Messages |
| Confirm and Send (cold) | Preview · Send message |
| Refund logs style jargon | Sent · Sending · Failed |

## Architecture

- Host: `myeventlane_vendor` (`VendorMessagesHubController`, `VendorMessagesHubBuilder`, `VendorEventMessagesBuilder`)
- Canonical writer: `myeventlane_vendor_comms` (`VendorEventCommsForm` + `vendor_event_comms` queue)
- Recipients: `AttendeeRecipientResolver` (everyone) + `EventRecipientResolver` (ticket holders)
- History: `myeventlane_event_comms_log`
- Brand: existing `VendorBrandingForm` / event override
- No new mail architecture; no new notification entity product
