# Help article publish decision register

**Date:** 2026-05-22  
**Branch:** `feature/help-article-publish-prep`  
**Drupal nodes:** not updated in this pass

| Article | Source | Existing node | Audience | Action | Publish readiness | Blockers | Next step |
|---------|--------|---------------|----------|--------|-------------------|----------|-----------|
| Support contact | Draft + nid **1498** | 1498 “Contacting support” | public | **Merge** draft into 1498; expand body & summary | **Ready for editorial update** | Friendly path alias unconfirmed | Update nid 1498 in CMS; `drush search-api:index mel_content` |
| Stripe payouts | Draft + nid **1510** | 1510 “Payouts and fees” | vendor | **Merge** draft into 1510; keep title | **Ready for editorial update** (staging labels) | Exact fee/payout copy on dashboard | Update nid 1510; verify `/stripe/connect` labels on staging |
| Waitlist | Draft only | None (no matching published title) | public | **New article** after QA | **Blocked until product behaviour is verified** | Staging: join UI, offer email, RSVP vs paid clarity | Browser QA on sold-out paid event; then write node |
| Ticket confirmation | Draft only | None (“How to access your tickets” seed not on staging) | public | **New article** after QA | **Blocked until product behaviour is verified** | Wallet UI, email attachments, assignment flow | Complete test purchase on staging; then write node |
| Checkout errors | Draft only | None | public | **New article** (generic copy OK) | **Ready for editorial update** | Optional: capture real decline message on staging | Editorial review → new node; link from checkout help |

## Working tree note (start of pass)

- Branch: `feature/help-article-publish-prep`
- Clean of help code changes; unrelated items may still exist locally (`package.json`, other untracked `docs/content-audit/*` planning files).

## Governance reminders

- Do not set `field_help_ai_allowed` false without reason.
- Do not publish vendor audience copy to public hubs.
- Do not mention AI in article body.
- Merge 1498/1510 rather than duplicate.

## Related prep documents

- `support-contact-merge.md`
- `stripe-payouts-merge.md`
- `waitlist-verification.md`
- `ticket-confirmation-verification.md`
- `checkout-errors-verification.md`
