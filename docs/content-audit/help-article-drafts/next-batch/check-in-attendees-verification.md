# Check-in attendees help article — publish QA verification

**Date:** 2026-05-23  
**Related draft:** `check-in-attendees.md`  
**QA log:** `check-in-publish-readiness-qa.md`  
**Code fix:** `docs/audits/check-in-permission-route-parity-audit.md`

## Browser verification summary

| Persona | Canonical door | Attendees list | Attendees export | Ticket scanner | Legacy `/check-in/scan` | RSVP check-in |
|---------|----------------|----------------|------------------|----------------|-------------------------|---------------|
| Anonymous | denied | denied | denied | denied | denied | denied |
| Authenticated non-vendor | 403 | denied/redirect | 403 | 403 | denied | denied |
| Vendor event owner | 200 | 200 | 200 | 200 | 302 → door | 200 |
| Administrator | 200 | 200 | 200 | 200 | 302 → door | 200 |

## Documentation updates applied

1. **Canonical path:** Draft points to `/vendor/events/{node}/operations/door` for primary door check-in.
2. **Operations hub:** `/vendor/events/{node}/operations` documented.
3. **RSVP vs tickets:** RSVP at `/vendor/event/{event}/rsvps/checkin`; paid scanner at `/event/{event}/tickets/scan` (secondary to Door Mode).
4. **Legacy scan:** Not documented; redirects to door.
5. **Privacy:** Export columns, `?obfuscate=1`, RSVP export sensitivity.

## Publish readiness

**Ready for export/import** after publish-readiness QA on local DDEV (2026-05-23).

**Blockers cleared:** permission parity, vendor ticket scanner access, legacy scan redirect, RSVP check-in controller bug (500 → 200).

**Residual:** Help node not created until batch 06 import; physical device camera QA recommended.
