# Help articles batch 06 — export notes

**Date:** 2026-05-23  
**YAML:** `docs/content-audit/help-article-exports/help-articles-batch-06-2026-05.yml`

## Article included

| Stable key | Title | Alias | Audience |
|------------|-------|-------|----------|
| `check_in_attendees` | Checking in attendees at your event | `/help/vendors/check-in-attendees` | vendor |

## Publish-ready status

This batch is **publish-ready for import** after:

- Check-in permission and route parity audit (`docs/audits/check-in-permission-route-parity-audit.md`)
- Publish-readiness QA (`docs/content-audit/help-article-drafts/next-batch/check-in-publish-readiness-qa.md`)

Importer was **not** run as part of this task.

## Verification source

- Draft: `docs/content-audit/help-article-drafts/next-batch/check-in-attendees.md`
- QA log: `docs/content-audit/help-article-drafts/next-batch/check-in-publish-readiness-qa.md`
- Register: `docs/content-audit/help-article-drafts/next-batch/next-batch-register.md`

## Related code fix (deploy before or with import)

`RsvpCheckinController` — RSVP check-in page called undefined repository method; fixed to use controller `getEventRsvpsByStatus()`. Without this fix, `/vendor/event/{event}/rsvps/checkin` returns 500.

## Import commands

Use the YAML path as a **positional** argument (do not use `--file`).

**Dry-run:**

```bash
ddev drush mel:help-import-priority docs/content-audit/help-article-exports/help-articles-batch-06-2026-05.yml --dry-run
```

**Live import:**

```bash
ddev drush mel:help-import-priority docs/content-audit/help-article-exports/help-articles-batch-06-2026-05.yml --yes
```

**Post-import:**

```bash
ddev drush search-api:index mel_content
ddev drush search-api:status mel_content
ddev drush cr
```
