# Help article export — batch 02 (2026-05)

**Export file:** `docs/content-audit/help-article-exports/help-articles-batch-02-2026-05.yml`  
**Generated:** 2026-05-22  
**Importer:** `mel:help-import-priority` (not run for this export task)

## Articles included

| Stable key | Title | Alias | Audience | Type |
|------------|-------|-------|----------|------|
| `how_to_access_your_tickets` | How to access your tickets | `/help/attendees/how-to-access-your-tickets` | public | guide |
| `how_to_use_my_tickets` | How to use My tickets | `/help/attendees/how-to-use-my-tickets` | public | guide |
| `missing_ticket_help` | Missing ticket or confirmation email | `/help/attendees/missing-ticket-help` | public | troubleshooting |

## Articles deliberately excluded

| Draft | Reason |
|-------|--------|
| `add-event-to-calendar.md` | Calendar behaviour not verified for standalone article |
| `wallet-passes-explained.md` | Wallet enablement and eligibility not verified |
| Vendor drafts in `next-batch/` | Out of scope for this public post-purchase batch |
| Priority export articles (`help-articles-priority-2026-05.yml`) | Already exported — no duplication |

## Verification source

- `docs/content-audit/help-article-drafts/next-batch/how-to-access-your-tickets.md`
- `docs/content-audit/help-article-drafts/next-batch/how-to-use-my-tickets.md`
- `docs/content-audit/help-article-drafts/next-batch/missing-ticket-help.md`
- `docs/content-audit/help-article-drafts/next-batch/next-batch-register.md`
- `docs/content-audit/help-article-drafts/next-batch/post-purchase-ticket-access-verification.md` (task-specified verification register)
- Export format reference: `docs/content-audit/help-article-exports/help-articles-priority-2026-05.yml`

Editorial guardrails applied at export: conditional QR/PDF/wallet/calendar wording only; no staff/vendor/internal claims; no test IDs or staging-only data; editorial “Needs verification” markers removed from export body (refund path on My tickets).

## Import commands (run later — not executed for this task)

Dry-run:

```bash
ddev drush mel:help-import-priority --file=docs/content-audit/help-article-exports/help-articles-batch-02-2026-05.yml --dry-run
```

Live import:

```bash
ddev drush mel:help-import-priority --file=docs/content-audit/help-article-exports/help-articles-batch-02-2026-05.yml --yes
```

## Post-import checks

1. Confirm three nodes exist (or updated) with `field_help_seed_key` matching stable keys above.
2. Confirm path aliases resolve: `/help/attendees/how-to-access-your-tickets`, `/help/attendees/how-to-use-my-tickets`, `/help/attendees/missing-ticket-help`.
3. Confirm `help_status` is **published**, `audience` is **public**, and `ai_allowed` is enabled.
4. Spot-check HTML rendering (lists, `/my-tickets` links, related help links).
5. Confirm Help Centre browse/search surfaces the articles for public audience (no staff/vendor leakage).
6. Re-run dry-run after import — expect **skipped** (no field changes) when content is already applied.
7. Cross-check published cluster: “After you book a ticket”, “Having trouble checking out”, “Contacting support” still link sensibly to the new articles.
