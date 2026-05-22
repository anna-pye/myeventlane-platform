# Help article publish decision register

**Date:** 2026-05-22  
**Branch:** `feature/help-article-publish-prep`  
**Drupal nodes:** updated 2026-05-22 on `feature/help-article-editorial-updates` — see `editorial-update-log.md`

| Article | Source | Existing node | Audience | Action | Publish readiness | Blockers | Next step |
|---------|--------|---------------|----------|--------|-------------------|----------|-----------|
| Support contact | Draft + nid **1498** | 1498 “Contacting support” | public | **Done** — merged body/summary | **Published** | Path alias | — |
| Stripe payouts | Draft + nid **1510** | 1510 “Payouts and fees” | vendor | **Done** — merged body/summary | **Published** | Dashboard fee labels on staging | — |
| Waitlist | Draft only | None | public | **New article** after QA | **Blocked until product behaviour is verified** | Staging QA | Browser QA; then create node |
| Ticket confirmation | Draft only | None | public | **New article** after QA | **Blocked until product behaviour is verified** | Staging QA | Test purchase; then create node |
| Checkout errors | Draft + **nid 1668** | 1668 “Having trouble checking out” | public | **Done** — created | **Published** | Moderation state must be `published` on create | Optional checkout contextual link |

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
