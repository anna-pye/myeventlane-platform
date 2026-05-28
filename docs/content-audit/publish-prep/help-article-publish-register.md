# Help article publish decision register

**Date:** 2026-05-22  
**Branch:** `feature/help-publish-waitlist-ticket-confirmation`  
**Publication log:** `waitlist-ticket-confirmation-publish-log.md`

| Article | Source | Existing node | Audience | Action | Publish readiness | Blockers | Next step |
|---------|--------|---------------|----------|--------|-------------------|----------|-----------|
| Support contact | Draft + nid **1498** | 1498 “Contacting support” | public | **Done** | **Published** | — | Alias `/help/attendees/contacting-support` |
| Stripe payouts | Draft + nid **1510** | 1510 “Payouts and fees” | vendor | **Done** | **Published** | — | Alias `/help/vendors/payouts-and-fees` |
| Waitlist | Draft + QA | **1669** “Joining a waitlist” | public | **Done** | **Published** | Offer/claim E2E still conditional in copy | Alias `/help/attendees/joining-a-waitlist` |
| Ticket confirmation | Draft + QA | **1497** “After you book a ticket” | public | **Done** (merged nid 1497) | **Published** | QR/PDF conditional when tickets not issued | Alias `/help/attendees/after-you-book-a-ticket` |
| Checkout errors | Draft + **nid 1668** | 1668 “Having trouble checking out” | public | **Done** | **Published** | — | Alias `/help/attendees/having-trouble-checking-out` |

## Working tree note

- Drupal node content is **database-only** in this pass (not in `config/sync`).
- See `waitlist-ticket-confirmation-publish-log.md` for nid, aliases, and verification.

## Governance reminders

- Do not set `field_help_ai_allowed` false without reason.
- Do not publish vendor audience copy to public hubs.
- Do not mention AI in article body.
- `field_audience: public` required for anonymous Help Assistant retrieval.

## Related prep documents

- `waitlist-ticket-confirmation-publish-log.md`
- `waitlist-verification.md`
- `ticket-confirmation-verification.md`
- `support-contact-merge.md`
- `stripe-payouts-merge.md`
- `checkout-errors-verification.md`
- `editorial-update-log.md`
