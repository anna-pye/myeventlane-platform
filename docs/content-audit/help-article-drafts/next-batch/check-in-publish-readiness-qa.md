# Check-in help article — publish readiness QA

**Date:** 2026-05-23  
**Branch:** `feature/help-check-in-publish-readiness`  
**Draft:** `check-in-attendees.md`  
**Parity audit:** `docs/audits/check-in-permission-route-parity-audit.md`

## Scope

Browser/manual QA for vendor event-day check-in after permission and route parity fixes. No Help Article importer run. No Drupal node publish. Batch 06 YAML updated to publish-ready export state only.

Routes exercised:

- `/vendor/events/{node}/operations`
- `/vendor/events/{node}/operations/door`
- `/vendor/events/{node}/operations/door/validate`
- `/vendor/events/{node}/attendees`
- `/vendor/events/{node}/attendees/export`
- `/event/{event}/tickets/scan`
- `/vendor/events/{node}/check-in/scan` (legacy redirect)
- `/vendor/event/{event}/rsvps/checkin`
- `/vendor/event/{event}/rsvps/export`
- `/vendor/event/{event}/scan` (RSVP QR)

## Test data (synthetic / local; no real customer PII in this log)

| Field | Value |
|-------|--------|
| Event nid (paid, tickets) | **1592** — local paid event with completed orders |
| Vendor uid | **1** (vendor role; event owner) |
| Order id (sample) | **444** |
| Order item id (sample) | **655** / **673** |
| Ticket id / code (sample) | Ticket entity **149**; export code format `MEL-1592-446-663-A2789C`; unchecked check-in used paragraph **632** / attendee **287** |
| RSVP event nid | **1659** — `[MEL TEST] Event 1 - RSVP` |
| RSVP submission id (sample) | **139** (confirmed, not checked in at QA start) |
| Non-owner uid | **2** (`Vendor test`) |

Note: `[MEL TEST] Event 8 - Paid` (nid **1666**) had no `myeventlane_ticket` rows in local DB; QA used nid **1592** instead.

## Browser / manual checks

| Check | Method | Result |
|-------|--------|--------|
| Operations hub | Authenticated curl (vendor subdomain, uid 1 session) | **200** |
| Door Mode page | curl + HTML inspection | **200** — `mel-door-checkin`, camera scan CTA, manual code card, validate URLs in `drupalSettings.melDoorCheckin` |
| Validate endpoint | Drush service + curl POST with CSRF | **Pass** (see below) |
| Ticket scanner | curl owner / anon / non-owner | Owner **200**; anon **302/403**; non-owner **403** |
| Legacy `/check-in/scan` | curl `-D -` (no follow) | **302** → `/vendor/events/1592/operations/door` |
| Automated browser ULI | cursor-ide-browser | **Blocked** — one-time login URL returns Access denied (route anonymous-only); **not** a product regression; curl session used for page QA |
| RSVP check-in page | curl after code fix | **200** (was **500** before fix) |
| RSVP export | curl | **200** (header row only — no `event_attendee` RSVP rows for 1659 in DB) |
| RSVP scan page | curl owner | **200** |

## Paid ticket check-in (Door Mode validate)

| Case | Result |
|------|--------|
| Valid QR token (paragraph **632**) | **200** `status: success`, checked in attendee **287** |
| Duplicate scan (same token) | **200** `status: duplicate`, message “Already checked in.” |
| Wrong event (token on event **1666**) | **404** “Attendee not found for this event.” |
| Invalid code | **404** “No matching attendee for this event.” |
| Manual export ticket code typed as code | **404** — door validate searches attendee paragraph/name/email, not `event_attendee.ticket_code` alone; document manual search + QR, not raw export code guarantee |

## Ticket scanner (`/event/{event}/tickets/scan`)

- Vendor owner: **200**, `mel-html5-qrcode` / manual entry present.
- Secondary to Door Mode; Door Mode embeds same scanner library when tickets module enabled.
- Anonymous and non-owner: **denied** (403 or login redirect).

## RSVP check-in

| Item | Result |
|------|--------|
| Route | `/vendor/event/{event}/rsvps/checkin` |
| Separate from paid scanner | **Yes** — paid scanner under `/event/{event}/tickets/scan` |
| RSVP QR | `/vendor/event/{event}/scan` + `/vendor/qr/validate` (RSVP module) |
| List vs scan | Check-in page = list; scan = separate route |
| Product bug found | `RsvpCheckinController` called `$this->repo->getEventRsvpsByStatus()` but method lives on controller → **500**; **fixed** to `$this->getEventRsvpsByStatus()` |
| Access | Owner **200**; anon **302**; non-owner **403** on export |

## Guest list / export

| Route | Owner | Non-owner | Anon |
|-------|-------|-----------|------|
| `/vendor/events/{node}/attendees` | 200 | 403 / studio redirect | denied |
| `/vendor/events/{node}/attendees/export` | 200 | 403 | denied |

**Columns (combined export):** Name, Email, Phone, Source, Ticket type, Ticket code, Custom answers, Operational state, Checked in, Checked in at, Registered.

**`?obfuscate=1`:** Verified — email masked (`an***@myeventlane.com.au`) on combined export.

**RSVP export:** Uses `MelAttendeeExportBuilder` with `obfuscateEmail = FALSE` for RSVP source filter; local event **1659** returned headers only (no rows).

## Access control summary

| Persona | Door | Attendees | Export | Ticket scan | Legacy scan | RSVP check-in |
|---------|------|-----------|--------|-------------|-------------|---------------|
| Anonymous | denied | denied | denied | denied | denied | denied |
| Authenticated non-owner (uid 2) | 403 | redirect/denied | 403 | 403 | denied | denied |
| Vendor event owner (uid 1) | 200 | 200 | 200 | 200 | 302 → door | 200 |

## Privacy notes

- Exports may contain names, emails, phone, custom answers, ticket codes, check-in state.
- Combined export supports email obfuscation; RSVP export does not.
- Help copy must not expose attendee PII; article uses generic paths only.

## Article readiness decision

**Publish-ready** for export/import after this QA:

- Primary Door Mode and validate pipeline verified on paid event **1592**.
- Permission parity holds (anon / non-owner denied).
- Legacy scan redirects to door.
- RSVP check-in unblocked by minimal controller fix (documented; committed separately from YAML).

## Remaining blockers / residual risk

- **Importer not run** — Drupal Help node still absent until `mel:help-import-priority` on batch 06 YAML.
- **Human device QA** — camera QR on physical phones not in this pass (curl + service tests only).
- **RSVP export empty** on test event **1659** — no `event_attendee` RSVP rows; copy already treats RSVP as separate path.
- **Name search** on door returned empty for query `anna` at one point (many attendees already checked in); search path exists via validate GET `?q=`.
- **Automated browser ULI** unusable in Cursor browser tool; manual organiser retest on device still recommended before production comms.

## Code change (workflow blocker only)

- `web/modules/custom/myeventlane_rsvp/src/Controller/RsvpCheckinController.php` — call `$this->getEventRsvpsByStatus()` instead of repository (fixes RSVP check-in **500**).

## Commands run

```bash
git status --short && git branch --show-current
ddev drush cr
php -l web/modules/custom/myeventlane_rsvp/src/Controller/RsvpCheckinController.php
# curl session QA on https://vendor.myeventlane.ddev.site (uid 1 / 2)
# drush php:eval door_checkin_validate service tests
```
