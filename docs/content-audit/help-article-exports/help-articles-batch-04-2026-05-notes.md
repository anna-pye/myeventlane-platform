# Help articles batch 04 — export notes

**Date:** 2026-05-22  
**YAML:** `docs/content-audit/help-article-exports/help-articles-batch-04-2026-05.yml`

## Article included

| Stable key | Title | Alias | Audience |
|------------|-------|-------|------------|
| `ticket_sales_and_capacity` | Ticket sales and capacity | `/help/vendors/ticket-sales-and-capacity` | vendor |

## Deliberately excluded

**`organiser_manage_waitlists`** (draft `organiser-manage-waitlists.md`) is **not** in this batch because:

- `/vendor/event/{node}/waitlist` manages **RSVP** waitlist attendees only, not paid tier waitlist entries (`mel_ticket_waitlist_entry`).
- No verified organiser list/export UI for paid ticket waitlist entries.
- Paid tier waitlist toggles were not found in Event Studio organiser tier save payload at QA time.
- Auto-offer email and claim flow were not browser-verified on staging.

Re-export when organiser-facing paid waitlist configuration and reporting are verified (see `organiser-ticket-capacity-waitlist-verification.md`).

## Verification source

- Draft: `docs/content-audit/help-article-drafts/next-batch/ticket-sales-and-capacity.md`
- QA log: `docs/content-audit/help-article-drafts/next-batch/organiser-ticket-capacity-waitlist-verification.md`
- Register: `docs/content-audit/help-article-drafts/next-batch/next-batch-register.md`

Copy constraints preserved in YAML: completed-order **tickets sold**, per-tier capacity, analytics **Remaining** vs book-page availability with waitlist holds, cautious refund and combined-cap wording.

## Import commands

Use the YAML path as a **positional** argument (do not use `--file`).

**Dry-run:**

```bash
ddev drush mel:help-import-priority docs/content-audit/help-article-exports/help-articles-batch-04-2026-05.yml --dry-run
```

**Live import:**

```bash
ddev drush mel:help-import-priority docs/content-audit/help-article-exports/help-articles-batch-04-2026-05.yml --yes
```

**Post-import:**

```bash
ddev drush search-api:index mel_content
ddev drush search-api:status mel_content
ddev drush cr
```

Importer was **not** run as part of this export task.
