# Check-in attendees help article — publish QA verification

**Date:** 2026-05-23  
**Related draft:** `check-in-attendees.md`  
**Code fix:** `docs/audits/check-in-permission-route-parity-audit.md`

## Browser verification summary

| Persona | Canonical door | Attendees list | Attendees export | Ticket scanner | Legacy `/check-in/scan` | RSVP check-in |
|---------|----------------|----------------|------------------|----------------|-------------------------|---------------|
| Anonymous | 403 | 403 | 403 | 403 | 403 | 403 |
| Authenticated non-vendor | 403 | 403 | 403 | 403 | 403 | 403 |
| Vendor event owner | 200 | 200 | 200 | 200 | 302 → door | 200 (when RSVPs enabled) |
| Administrator | 200 | 200 | 200 | 200 | 302 → door | 200 |

## Documentation updates required before publish

1. **Canonical path:** Replace example `/vendor/events/{event}/check-in` with `/vendor/events/{node}/operations/door` for primary door check-in.
2. **Operations hub:** Mention `/vendor/events/{node}/operations` for live attendee list, export, and manual lookup.
3. **RSVP vs tickets:** RSVP check-in remains at `/vendor/event/{event}/rsvps/checkin`; paid ticket scanner at `/event/{event}/tickets/scan` (also embedded in Door Mode).
4. **Do not document** legacy `/vendor/events/{node}/check-in/scan` (redirects to door).

## Publish readiness

**Not yet ready for publish QA** until a human re-walks the vendor event-day flow in the browser after config import (`drush cim` or role sync) and confirms Door Mode scanner behaviour on a real paid-ticket event.

**Blockers cleared by code fix:** permission mismatch, vendor 403 on ticket scanner, broken legacy scan asset.

**Remaining:** Help draft body and Batch 06 YAML still reference legacy routes — update in a separate content pass after browser sign-off.
