# Help articles batch 04 import log

## Scope

Imported Batch 04 organiser ticket capacity help article.

## Import file

`docs/content-audit/help-article-exports/help-articles-batch-04-2026-05.yml`

## Article imported

| Stable key | Title | Node | Alias | Audience |
|---|---|---:|---|---|
| `ticket_sales_and_capacity` | Ticket sales and capacity | 1674 | `/help/vendors/ticket-sales-and-capacity` | vendor |

## Dry-run result

Initial dry-run:

- 1 created
- 0 updated
- 0 skipped
- 0 errors

After live import, repeat dry-run:

- 0 created
- 0 updated
- 1 skipped
- 0 errors
- matched by `field_help_seed_key`
- no field changes detected

## Live import result

Initial live import:

- 1 created
- 0 updated
- 0 skipped
- 0 errors

Created node:

- nid 1674
- stable key `ticket_sales_and_capacity`

Second live import:

- 0 created
- 0 updated
- 1 skipped
- 0 errors

This confirms the importer is idempotent.

## Search API

`mel_content` status after import:

- 66 / 66
- 100%

## Access check

Anonymous request:

`/help/vendors/ticket-sales-and-capacity`

Result:

- HTTP 403

This is expected because the article audience is `vendor`.

## Notes

The organiser waitlist article remains excluded from Batch 04 because paid-tier waitlist organiser UI/reporting and auto-offer claim flow are not fully verified.
