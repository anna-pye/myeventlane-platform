# Next Help Centre batch — draft register

**Date:** 2026-05-23 (register updated after batch 06 import)  
**Folder:** `docs/content-audit/help-article-drafts/next-batch/`  
**Importer:** Batch 06 live import complete (see `help-articles-batch-06-import-log.md`). Other rows unchanged unless noted.

| Draft | Audience | Recommended alias | Ready to publish? | Ready to export? | Export batch | Needs verification | Notes |
|-------|----------|-------------------|-------------------|------------------|--------------|--------------------|-------|
| how-to-access-your-tickets.md | public | /help/attendees/how-to-access-your-tickets | No | Yes | batch_02_2026_05 | — | Exported in batch 02; seed merge on import |
| how-to-use-my-tickets.md | public | /help/attendees/how-to-use-my-tickets | No | Yes | batch_02_2026_05 | — | Exported in batch 02 |
| add-event-to-calendar.md | public | /help/attendees/add-event-to-calendar | No | No | — | Event `/ics` availability; email vs My Tickets parity | Calendar links conditional in copy |
| wallet-passes-explained.md | public | /help/attendees/wallet-passes-explained | No | No | — | Admin wallet enablement; order-state eligibility | Wallet buttons conditional in copy |
| missing-ticket-help.md | public | /help/attendees/missing-ticket-help | No | Yes | batch_02_2026_05 | — | Exported in batch 02 |
| organiser-manage-waitlists.md | vendor | /help/vendors/organiser-manage-waitlists | No | No | — | Blocked until paid waitlist organiser UI/reporting and auto-offer claim flow are verified | See organiser-ticket-capacity-waitlist-verification.md |
| ticket-sales-and-capacity.md | vendor | /help/vendors/ticket-sales-and-capacity | No | Yes | batch_04_2026_05 | Refund→sold count; lower capacity below sold | Exported in help-articles-batch-04-2026-05.yml; importer not run |
| attendee-questions-for-organisers.md | vendor | /help/vendors/attendee-questions-for-organisers | No | No | — | Field types; archive behaviour; export columns | Privacy-first collection guidance |
| check-in-attendees.md | vendor | /help/vendors/check-in-attendees | **Imported** | Yes | batch_06_2026_05 | Physical device camera QR not verified | Imported 2026-05-23; node **1677** `/help/vendors/check-in-attendees`; publish-ready QA 2026-05-23; physical-camera QA still optional |
| saved-question-templates.md | vendor | /help/vendors/saved-question-templates | No | No | — | Library permissions; edit vs clone semantics | Route `/vendor/questions` |

## Publish order suggestion

1. **Public post-purchase cluster:** access → use My tickets → calendar → wallet → missing ticket (aligns with published “After you book a ticket”).
2. **Vendor operations cluster:** ticket sales and capacity → manage waitlists → attendee questions → saved templates → check-in.

## Related published articles

- After you book a ticket
- Joining a waitlist
- Having trouble checking out
- Contacting support
- Payouts and fees
