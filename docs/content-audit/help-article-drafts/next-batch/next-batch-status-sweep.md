# Next-batch Help Centre — status sweep

**Date:** 2026-05-23 (reconciled against committed import logs on `main`)  
**Scope:** Docs/status reconciliation only. No YAML export, import, publish, or product changes.  
**Sources:** `next-batch-register.md`, `help-articles-batch-*-import-log.md`, `help-articles-batch-*-notes.md`, verification logs in this folder.

## Reconciliation notes (2026-05-23)

- Import logs **on `main`:** batch 04 (`help-articles-batch-04-import-log.md`), batch 06 (`help-articles-batch-06-import-log.md`), batch 05 (`help-articles-batch-05-import-log.md`, restored from `feature/help-verify-attendee-questions-docs` commit `21842c52`).
- Import logs **missing:** batch 02, batch 03 — batch 02 trio remains **exported only** (YAML + export notes; no import log in any branch).
- Prior sweep (commit `eb8f8cf6` on branch `docs/help-next-batch-register-sweep`, not merged) incorrectly recommended `attendee-questions-for-organisers.md` for verification — batch 05 import log shows both attendee-questions articles already imported.

## Current status table

| Draft | Governance status | Export batch | Evidence |
|-------|-------------------|--------------|----------|
| `how-to-access-your-tickets.md` | **Exported but not imported** | batch_02_2026_05 | `help-articles-batch-02-2026-05.yml`; export notes 2026-05-22; **no import log** |
| `how-to-use-my-tickets.md` | **Exported but not imported** | batch_02_2026_05 | Same as above |
| `missing-ticket-help.md` | **Exported but not imported** | batch_02_2026_05 | Same as above |
| `ticket-sales-and-capacity.md` | **Published/imported** | batch_04_2026_05 | `help-articles-batch-04-import-log.md` — nid **1674**, alias `/help/vendors/ticket-sales-and-capacity` |
| `attendee-questions-for-organisers.md` | **Published/imported** | batch_05_2026_05 | `help-articles-batch-05-import-log.md` — nid **1675**, alias `/help/vendors/attendee-questions-for-organisers` |
| `saved-question-templates.md` | **Published/imported** | batch_05_2026_05 | `help-articles-batch-05-import-log.md` — nid **1676**, alias `/help/vendors/saved-question-templates` |
| `check-in-attendees.md` | **Published/imported** | batch_06_2026_05 | `help-articles-batch-06-import-log.md` — nid **1677**, alias `/help/vendors/check-in-attendees` |
| `organiser-manage-waitlists.md` | **Verified but blocked** | — | `organiser-waitlists-verification.md`; paid-tier organiser UI/reporting missing; RSVP-only partial truth |
| `add-event-to-calendar.md` | **Duplicate/covered** + **Needs verification** | — | Live article **“Adding events to your calendar”** (nid **1501**, published); draft alias differs — prefer editorial merge/update, not new node |
| `wallet-passes-explained.md` | **Needs verification** | — | `publish-prep/ticket-confirmation-verification.md` — wallet UI/email not browser-tested on staging |

## Completed items

- **Ticket sales and capacity** — imported batch 04, nid 1674.
- **Attendee questions + saved question templates** — imported batch 05, nids 1675 and 1676.
- **Check-in attendees** — imported batch 06, nid 1677.
- **Post-purchase public cluster (export only)** — access tickets, use My tickets, missing ticket — YAML batch 02 ready; export-time verification complete; **import pending** (no import log).

## Blocked items

| Draft | Blocker |
|-------|---------|
| `organiser-manage-waitlists.md` | Paid ticket waitlist organiser list/config/export not shipped; `/vendor/event/{event}/waitlist` is RSVP-only; auto-promote/offer flows not E2E-verified for organiser copy |
| `wallet-passes-explained.md` | Config-gated wallet buttons; staging purchase + device QA required before export |
| `add-event-to-calendar.md` | **Do not add a second calendar article** — update nid 1501 after `/ics` and My Tickets parity verified |

## Recommended next article

**`how-to-access-your-tickets.md`** — lead article for **batch 02 import governance**

## Reason for recommendation

1. **Not imported** — batch 02 YAML is ready but no import log exists; this is the highest-value remaining public cluster gap.
2. **Verification already done at export** — `post-purchase-ticket-access-verification.md` and batch 02 export notes; next action is import prep / spot-check, not greenfield QA.
3. **Cluster fit** — first step in the public post-purchase sequence (access → use My tickets → missing ticket) aligned with published “After you book a ticket”.
4. **Lower risk than** waitlist (blocked), wallet (browser/config), calendar (duplicate nid 1501), or re-opening batch 05/06 (import logs prove completion).

## Next three safest actions (not all verification)

1. **Batch 02 import governance** — `how-to-access-your-tickets.md`, `how-to-use-my-tickets.md`, `missing-ticket-help.md` (single import pass; log results).
2. **`add-event-to-calendar.md`** — verify `/ics` and My Tickets parity, then **merge/update nid 1501** (not a new node).
3. **`wallet-passes-explained.md`** — after staging wallet enablement and order-state browser QA.

## Articles to avoid for now

| Draft | Why |
|-------|-----|
| `add-event-to-calendar.md` | Duplicate of published nid 1501 unless scoped as editorial merge |
| `organiser-manage-waitlists.md` | Paid-tier organiser product gaps; RSVP-only article needs explicit scope split |
| `wallet-passes-explained.md` | Staging wallet enablement and order-state eligibility unverified |
| `attendee-questions-for-organisers.md` | **Imported** batch 05 — do not re-verify or re-export |
| `saved-question-templates.md` | **Imported** batch 05 — do not re-verify or re-export |
| `check-in-attendees.md` | **Imported** batch 06 — optional physical-camera QA only |
| `ticket-sales-and-capacity.md` | **Imported** batch 04 |

## Register sync (2026-05-23)

- `ticket-sales-and-capacity.md` — marked imported (nid 1674).
- `attendee-questions-for-organisers.md` — marked imported (nid 1675).
- `saved-question-templates.md` — marked imported (nid 1676).
- `check-in-attendees.md` — marked imported (nid 1677); unchanged from prior register.
- Batch 02 trio — exported only; importer **not run** (no import log).
