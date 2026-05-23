# Next Help Centre batch — draft register

**Date:** 2026-05-23 (reconciled against import logs on `main`; calendar duplicate governance added)  
**Folder:** `docs/content-audit/help-article-drafts/next-batch/`  
**Importer:** Batches 04, 05, and 06 live imports complete (see `help-articles-batch-04-import-log.md`, `help-articles-batch-05-import-log.md`, `help-articles-batch-06-import-log.md`). **Batch 02 is exported only — not imported; there is no batch 02 import log and batch 02 must not be treated as closed.**

**Calendar duplicate (2026-05-23):** See `calendar-duplicate-governance.md` and `calendar-article-merge-qa.md`. Canonical node **nid 1501**; duplicate **nid 1673** holds seed key + alias until manual cleanup. **Do not import** calendar YAML while `add_event_to_calendar` is on nid 1673.

| Draft | Audience | Recommended alias | Ready to publish? | Ready to export? | Export batch | Needs verification | Notes |
|-------|----------|-------------------|-------------------|------------------|--------------|--------------------|-------|
| how-to-access-your-tickets.md | public | /help/attendees/how-to-access-your-tickets | No | Yes | batch_02_2026_05 | — | Exported in batch 02; **import pending** (no import log) |
| how-to-use-my-tickets.md | public | /help/attendees/how-to-use-my-tickets | No | Yes | batch_02_2026_05 | — | Exported in batch 02; **import pending** |
| add-event-to-calendar.md | public | /help/attendees/add-event-to-calendar | No | No | — | Event `/ics` availability; email vs My Tickets parity | **Blocked:** duplicate nid **1673** (seed + alias) vs canonical **1501** (stub). Content update only after 1673 retired — see `calendar-duplicate-governance.md`. **Do not create new node.** **Do not import** until seed/alias on 1501. |
| wallet-passes-explained.md | public | /help/attendees/wallet-passes-explained | No | No | — | Admin wallet enablement; order-state eligibility | Wallet buttons conditional in copy |
| missing-ticket-help.md | public | /help/attendees/missing-ticket-help | No | Yes | batch_02_2026_05 | — | Exported in batch 02; **import pending** |
| organiser-manage-waitlists.md | vendor | /help/vendors/organiser-manage-waitlists | No | No | — | RSVP list/export verified in code; paid-tier organiser UI/export missing; RSVP auto-promote not wired; browser QA pending | See organiser-waitlists-verification.md; recommended scope RSVP-only organiser section until paid reporting ships |
| ticket-sales-and-capacity.md | vendor | /help/vendors/ticket-sales-and-capacity | **Imported** | Yes | batch_04_2026_05 | Refund→sold count; lower capacity below sold | Imported batch 04 — nid **1674** (`help-articles-batch-04-import-log.md`) |
| attendee-questions-for-organisers.md | vendor | /help/vendors/attendee-questions-for-organisers | **Imported** | Yes | batch_05_2026_05 | — | Imported batch 05 — nid **1675** (`help-articles-batch-05-import-log.md`) |
| check-in-attendees.md | vendor | /help/vendors/check-in-attendees | **Imported** | Yes | batch_06_2026_05 | Physical device camera QR not verified | Imported batch 06 — nid **1677** (`help-articles-batch-06-import-log.md`); physical-camera QA still optional |
| saved-question-templates.md | vendor | /help/vendors/saved-question-templates | **Imported** | Yes | batch_05_2026_05 | — | Imported batch 05 — nid **1676** (`help-articles-batch-05-import-log.md`) |

## Publish order suggestion

1. **Public post-purchase cluster:** access → use My tickets → calendar → wallet → missing ticket (aligns with published “After you book a ticket”).
2. **Vendor operations cluster:** ticket sales and capacity → manage waitlists → attendee questions → saved templates → check-in.

## Related published articles

- After you book a ticket
- Joining a waitlist
- Having trouble checking out
- Contacting support
- Payouts and fees
