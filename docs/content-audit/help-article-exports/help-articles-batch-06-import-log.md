# Help articles batch 06 import log

## Scope

Imported the check-in attendees vendor help article after check-in publish readiness QA.

## Import file

`docs/content-audit/help-article-exports/help-articles-batch-06-2026-05.yml`

## Article imported

| Stable key | Title | Node | Alias | Audience | Status | AI allowed |
|---|---|---:|---|---|---|---|
| `check_in_attendees` | Checking in attendees at your event | 1677 | `/help/vendors/check-in-attendees` | vendor | published | true |

## Dry-run result

- created: 0
- updated: 0
- skipped: 1
- errors: 0

Skipped: matched by `seed_key`, nid **1677**, no field changes detected (node already present from prior seed/import).

## Live import result

- created: 0
- updated: 0
- skipped: 1
- errors: 0

Live import confirmed idempotency: same node **1677**, no field changes required.

## Node verification

| Field | Value |
|---|---|
| nid | 1677 |
| title | Checking in attendees at your event |
| status | published (1) |
| alias | `/help/vendors/check-in-attendees` |
| field_audience | vendor |
| field_help_status | published |
| field_help_ai_allowed | true |

## Search API

`mel_content` status after import and reindex:

- **69 / 69** indexed
- **100%** complete

## Access checks

- Anonymous `/help/vendors/check-in-attendees` → **HTTP 403** (curl, Host: myeventlane.ddev.site)
- Vendor access: entity `view` access **allowed** for uid **1** (vendor event owner); anonymous entity access **denied**. Browser session curl not repeated in this pass; route/node access governed by vendor audience policy.

## Notes

- Physical device camera QR was **not** tested in this import pass.
- Article remains accurate because it does not promise physical-camera support beyond the verified browser/door workflow.
- Paid ticket check-in and RSVP check-in are described as separate paths.
- Export privacy warning preserved in body copy.
- Related code fix (`RsvpCheckinController`) was deployed on branch before import; RSVP check-in route verified in publish-readiness QA.
