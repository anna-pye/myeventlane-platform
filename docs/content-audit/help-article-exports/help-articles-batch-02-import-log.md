# Help articles batch 02 import log

## Scope

Governance import for Batch 02 public post-purchase ticket access help articles:

- `how_to_access_your_tickets`
- `how_to_use_my_tickets`
- `missing_ticket_help`

Branch: `docs/help-batch-02-import-governance`  
Date: 2026-05-23

## Import file

`docs/content-audit/help-article-exports/help-articles-batch-02-2026-05.yml`

## Articles imported

| Stable key | Title | Node | Alias | Audience | Status | AI allowed |
|---|---|---:|---|---|---|---|
| `how_to_access_your_tickets` | How to access your tickets | 1670 | `/help/attendees/how-to-access-your-tickets` | public | published | true |
| `how_to_use_my_tickets` | How to use My tickets | 1671 | `/help/attendees/how-to-use-my-tickets` | public | published | true |
| `missing_ticket_help` | Missing ticket or confirmation email | 1672 | `/help/attendees/missing-ticket-help` | public | published | true |

All three nodes were already present before this governance pass (matched by `field_help_seed_key`). Live import made no field changes; this log closes the missing Batch 02 audit trail.

## Product spot-check (Task C)

Light verification against current product — no blockers logged.

| Check | Result |
|---|---|
| My Tickets page (`/my-tickets`) | Present — `myeventlane_checkout_flow.my_tickets` |
| Order / booking detail (`/my-tickets/order/{commerce_order}`) | Present — order access route and template |
| My Tickets UI labels (Upcoming, Past, View booking) | Match article copy in `myeventlane-my-tickets.html.twig` |
| Missing-ticket support guidance | Valid — inbox/My tickets/support steps align with published checkout and support articles |
| Cluster overlap with “After you book a ticket” (nid **1497**, `/help/attendees/after-you-book-a-ticket`) | Intentional — Batch 02 articles expand the post-purchase cluster; cross-links in YAML bodies point to nid 1497 and related articles |

Editorial notes (not blockers):

- Calendar and wallet articles remain excluded from Batch 02 (see export notes).
- Refund path on order detail uses conditional wording in `how_to_use_my_tickets` body (no exact path asserted).

## Dry-run result

```text
0 created, 0 updated, 3 skipped, 0 errors
```

Per article:

- `how_to_access_your_tickets`: skipped (`matched_by=seed_key`, nid=1670, no field changes)
- `how_to_use_my_tickets`: skipped (`matched_by=seed_key`, nid=1671, no field changes)
- `missing_ticket_help`: skipped (`matched_by=seed_key`, nid=1672, no field changes)

No duplicate-create risk — importer matched existing nodes by stable seed key, not alias collision with legacy seed `how_to_access_tickets` in install config.

## Live import result

```text
0 created, 0 updated, 3 skipped, 0 errors
```

Idempotent re-run confirms Batch 02 YAML is fully applied.

## Search API

`mel_content` after reindex and cache rebuild:

- **69 / 69** indexed
- **100%** complete

Commands run:

```bash
ddev drush search-api:index mel_content
ddev drush search-api:status mel_content
ddev drush cr
```

## Anonymous access checks

| URL | HTTP |
|---|---|
| `/help/attendees/how-to-access-your-tickets` | **200** |
| `/help/attendees/how-to-use-my-tickets` | **200** |
| `/help/attendees/missing-ticket-help` | **200** |

Expected for public/attendee audience.

## Overlap with “After you book a ticket”

Published article nid **1497** (`/help/attendees/after-you-book-a-ticket`) remains the cluster entry point. Batch 02 adds three focused follow-ons:

1. **Access** — where to find tickets (email, My tickets, links)
2. **Use My tickets** — signed-in booking list and order detail workflow
3. **Missing ticket** — troubleshooting when email or links fail

Related-help links in all three Batch 02 bodies link to nid 1497 and to each other. No duplicate nodes created for the same aliases.

## Blockers

None. No code changes required for import or access verification.
