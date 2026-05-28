# Organiser manage waitlists — QA verification log

**Date:** 2026-05-23  
**Branch:** `feature/help-verify-organiser-waitlists`  
**Draft:** `organiser-manage-waitlists.md`  
**Prior log:** `organiser-ticket-capacity-waitlist-verification.md` (2026-05-22)  
**Environment:** DDEV local (`https://myeventlane.ddev.site`) — code audit, Drush/SQL, access callbacks; no browser E2E, no node import, no YAML export, no publish.

## Scope

- Verify organiser-facing help for **RSVP / attendance waitlists** vs **paid ticket tier waitlists**.
- Document product truth only; do not claim organiser UI or notifications unless verified.
- No code changes (verification-only pass).

## Test data (local)

| Item | Value |
|------|--------|
| Paid test event | **1666** — [MEL TEST] Event 8 - Paid |
| Tier **245** | capacity 50, `waitlist_enabled=0` |
| Tiers with waitlist enabled (sample) | **4**, **28**, **64**, **95** — `waitlist_enabled=1` (not all sold out locally) |
| `mel_ticket_waitlist_entry` | **1** row — event **1584**, tier **95**, `status=offered`, `offer_reserved=1` |
| `event_attendee` status `waitlist` | **0** rows |
| `rsvp_submission` status `waitlist` | **0** rows |
| `myeventlane_rsvp.settings` | `waitlist_enabled_default=1`, `auto_promote=1`, `notify_on_promotion=1` |

## Product truth table

| Capability | RSVP / attendance waitlist | Paid ticket tier waitlist |
|---|---|---|
| Customer can join | **Yes** — `/event/{node}/waitlist/signup` creates `event_attendee` with status `waitlist` (`WaitlistSignupForm`). Primary `RsvpPublicForm` throws at capacity and does **not** auto-enrol (message only). | **Yes** — sold-out paid tier with `waitlist_enabled` on book page (`TicketSelectionForm` → `TicketTierWaitlistService::joinWaitlist`). |
| Organiser can view list | **Yes** — `/vendor/event/{node}/waitlist` lists `event_attendee` waitlist only (`WaitlistManagementController`). Legacy `rsvp_submission` waitlist appears on `/vendor/event/{event}/rsvps` and check-in, not on the waitlist page. | **No** — no vendor route for `mel_ticket_waitlist_entry`. |
| Organiser can export | **Yes** — `/vendor/event/{node}/waitlist/export` CSV (name, email, position, status). | **No** |
| Auto invite / offer | **No (not verified end-to-end)** — `RsvpPromotionManager` is **never invoked**; `WaitlistPromotionWorker` queue has **no producers** found; `AutomationScheduler::scanWaitlistInvites()` is a **stub** (`field_waitlist_auto_invite` not in `config/sync`); RSVP module `auto_promote` config does not apply to `RsvpSubmissionManager` (full events throw `CapacityExceededException`). | **Partial (code only)** — `auto_promote_waitlist` + `processAutoPromotions()` + `mel_ticket_waitlist_offer_mail` queue; **not** browser- or Mailpit-verified; organiser cannot toggle via Event Studio UI. |
| Claim / booking flow | **Incomplete** — `WaitlistInviteWorker` references route `myeventlane_automation.waitlist_claim` **not found** in routing YAML. | **Yes (code)** — `/event/{node}/book/waitlist/{token}` (`TicketWaitlistClaimController`); not browser-verified. |
| Vendor dashboard link | **Partial** — `VendorDashboardController` exposes `waitlist_url` → `/vendor/event/{id}/waitlist` (hardcoded string); theme templates for waitlist management not grep-verified in nav. | **No** organiser link |
| Ready for help article (this draft) | **Partial** — organiser list/export steps only | **No** — configuration/reporting gaps |

## RSVP waitlist findings

### Data model and enablement

- Organiser waitlist UI reads **`event_attendee`** entities with status **`waitlist`** (`AttendanceWaitlistManager`).
- Event field in sync: **`field_waitlist_capacity`** (max waitlist size; optional/unlimited). **`field_waitlist_enabled`** is referenced in `AttendanceWaitlistManager::isWaitlistEnabled()` but **not exported** in `config/sync` (defaults to enabled when field absent).
- Wizard / Event Studio schema exposes **`field_waitlist_capacity`** for RSVP/both modes (`EventWizardTicketsForm`).
- Separate legacy entity **`rsvp_submission`** with status `waitlist` still counted on vendor RSVP dashboard (`VendorEventRsvpController`) and check-in PDF/HTML; **not** listed on `/vendor/event/{node}/waitlist`.

### Public join

| Route | Handler | Result |
|-------|---------|--------|
| `myeventlane_event_attendees.waitlist_signup` | `/event/{node}/waitlist/signup` | `WaitlistSignupForm` — creates `event_attendee`, shows FIFO position message |
| `myeventlane_rsvp.public_rsvp_form` | `/event/{event}/rsvp` | Redirects to Commerce booking — not primary free-RSVP waitlist path |
| `RsvpPublicForm` | Booking flow | On capacity exceeded: error “Join the waitlist?” — **no automatic waitlist enrolment** |

`EventModeManager` links to `waitlist_signup` when appropriate for full events.

### Organiser list / export

| Route | Access | Data |
|-------|--------|------|
| `/vendor/event/{node}/waitlist` | Event owner, `field_event_vendor` team users, or `administer nodes` (`WaitlistManagementController::access`) | Table: position, name, email, date added, status; analytics cards |
| `/vendor/event/{node}/waitlist/export` | Same | CSV: Position, Name, Email, Date Added, Status |

**Access tested (Drush):** anonymous **forbidden**; event owner (uid 1) **allowed**.

**UI gap:** Waitlist management template has **no** promote/remove actions — read-only list + export.

### Related organiser RSVP routes (not the waitlist page)

| Route | Path | Notes |
|-------|------|-------|
| `myeventlane_rsvp.vendor_event_rsvps` | `/vendor/event/{event}/rsvps` | RSVP dashboard; waitlist count includes `rsvp_submission` + commerce `event_attendee` waitlist |
| `myeventlane_rsvp.export_csv` | `/vendor/event/{event}/rsvps/export` | RSVP export (separate from waitlist CSV) |
| `myeventlane_rsvp.checkin_list` | `/vendor/event/{event}/rsvps/checkin` | Shows waitlist section for `rsvp_submission` |

**Broken link:** `VendorEventRsvpController` builds promote URL to `myeventlane_rsvp.admin_promote` — **route not defined** in `myeventlane_rsvp.routing.yml`.

### Auto-promote / notifications (RSVP)

| Mechanism | Status |
|-----------|--------|
| `myeventlane_rsvp.settings` `auto_promote` / `notify_on_promotion` | Config present; **not wired** to live `RsvpSubmissionManager` submit path |
| `RsvpPromotionManager::promoteNext()` | Service registered; **no callers** in codebase |
| `myeventlane_waitlist_promotion` queue worker | Exists; **no `createItem` producers** found |
| `automation_waitlist_invite` + `waitlist_invite` template | Worker sends email, but scheduler scan is **stub**; claim route **missing** |
| `WaitlistNotificationService` | Promotion email for `event_attendee` exists; trigger path not verified |

**Verdict:** Do **not** tell organisers that RSVP waitlist guests are auto-promoted or emailed unless product re-verifies after wiring fixes.

## Paid ticket waitlist findings

### Tier fields (`mel_ticket_type`)

| Field | Label |
|-------|-------|
| `waitlist_enabled` | Waitlist when sold out |
| `waitlist_capacity` | Waitlist capacity |
| `auto_promote_waitlist` | Auto-offer waitlist when tickets free up |

`TicketTierLifecycleService` can persist all three when present in API payload; **Event Studio** `buildDraftTierFromCard()` sends only title, kind, capacity, price — **no waitlist fields**.

### Buyer join and offer (code-verified)

- Join: `TicketSelectionForm` → `TicketTierWaitlistService::joinWaitlist()` — paid tier, sold out, finite capacity, `waitlist_enabled`.
- Auto-offer: `processAutoPromotions()` when tier **active** (capacity available) and `auto_promote_waitlist` enabled; 48h TTL; holds capacity via `offer_reserved`.
- Email: queue `mel_ticket_waitlist_offer_mail` → `TicketTierWaitlistOfferMailWorker` → messaging template `ticket_tier_waitlist_offer`.
- Claim: `myeventlane_commerce.event_ticket_waitlist_claim` → `/event/{node}/book/waitlist/{token}`.
- Cron: `myeventlane_commerce` cron → `runCronMaintenance()`.

### Organiser management

- **No** list, export, or dashboard route for `mel_ticket_waitlist_entry`.
- `/vendor/event/{node}/waitlist` is **RSVP/attendance only** — using it for paid waitlist emails would be **wrong**.

### Status

**Buyer-only join/claim with limited organiser management** — backend entity and automation exist; organiser self-service configuration and reporting **incomplete**.

## Route / access table

| Route name | Path | Persona | Expected |
|------------|------|---------|----------|
| `myeventlane_event_attendees.waitlist_manage` | `/vendor/event/{node}/waitlist` | Event owner / vendor team / admin | 200 |
| `myeventlane_event_attendees.waitlist_manage` | same | Anonymous / non-owner | 403 |
| `myeventlane_event_attendees.waitlist_export` | `/vendor/event/{node}/waitlist/export` | Owner / team / admin | CSV download |
| `myeventlane_event_attendees.waitlist_signup` | `/event/{node}/waitlist/signup` | Public (`access content`) | Form |
| `myeventlane_commerce.event_ticket_waitlist_claim` | `/event/{node}/book/waitlist/{token}` | Public with valid token | Redirect to book |
| `myeventlane_rsvp.vendor_event_rsvps` | `/vendor/event/{event}/rsvps` | Vendor access callback | RSVP list (includes legacy waitlist count) |

## Notification / email findings

| Path | Template / queue | Verified sent locally? |
|------|------------------|------------------------|
| Paid tier offer | `mel_ticket_waitlist_offer_mail` / `ticket_tier_waitlist_offer` | **No** (Mailpit not exercised) |
| RSVP submission (legacy saver) | `mel_rsvp_waitlist_email` | Deprecated path |
| Attendee promotion | `waitlist_promotion` / `email-waitlist-promotion` | Trigger not verified |
| Automation invite | `waitlist_invite` | Scheduler stub; claim route missing |

## Privacy notes

- Waitlist CSV export includes **name and email** — treat as personal information; store securely; use only for the relevant event.
- Paid tier waitlist stores email on `mel_ticket_waitlist_entry` (organisers have no UI to view).
- Do not paste waitlist exports into public channels or unrelated tickets.

## Article readiness decision

| Question | Answer |
|----------|--------|
| Ready to publish? | **No** |
| Ready to export? | **No** |
| Recommended scope | **RSVP-only organiser section** (list + export on `/vendor/event/{event}/waitlist`) could be extracted later; **exclude** paid-tier organiser management until product ships list/config UI. Full draft should stay **blocked** or be split. |

### Blockers

1. Paid ticket waitlist: no organiser list/export; Event Studio cannot enable tier waitlist toggles.
2. RSVP auto-promote / invite: multiple code paths **not wired** or **stub**; do not document auto-invite for organisers.
3. Dual RSVP data stores (`event_attendee` vs `rsvp_submission`) — help must not blur routes.
4. Browser QA not run: book-page join, offer email, claim URL, nav link to waitlist page.
5. `myeventlane_rsvp.admin_promote` route missing (broken promote action on RSVP dashboard).

### YAML export recommendation

**Not recommended** for `organiser_manage_waitlists` in the next batch. Revisit when paid-tier organiser reporting exists or a **narrow RSVP-only** article is approved by editorial.

## Commands run

```bash
git branch --show-current && git status --short
ddev drush status --fields=bootstrap,uri
ddev drush sqlq "SELECT status, COUNT(*) FROM mel_ticket_waitlist_entry GROUP BY status"
ddev drush sqlq "SELECT id, waitlist_enabled, auto_promote_waitlist FROM mel_ticket_type WHERE waitlist_enabled=1 LIMIT 5"
ddev drush ev # WaitlistManagementController::access + route paths
```

**Residual risk:** Local DB has no `event_attendee` waitlist rows; paid waitlist browser flow not exercised; production may differ where tiers were configured outside Event Studio.
