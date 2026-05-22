# Help articles batch 05 import log

## Scope

Imported Batch 05 organiser help articles for attendee questions and saved question templates.

## Import file

`docs/content-audit/help-article-exports/help-articles-batch-05-2026-05.yml`

## Articles imported

| Stable key | Title | Node | Alias | Audience |
|---|---|---:|---|---|
| `attendee_questions_for_organisers` | Collecting attendee questions at checkout | 1675 | `/help/vendors/attendee-questions-for-organisers` | vendor |
| `saved_question_templates` | Saved question templates | 1676 | `/help/vendors/saved-question-templates` | vendor |

## Dry-run result

- 2 created
- 0 updated
- 0 skipped
- 0 errors

## Live import result

- 2 created
- 0 updated
- 0 skipped
- 0 errors

## Search API

`mel_content` after import:

- 68 / 68
- 100%

## Access checks

Anonymous requests:

- `/help/vendors/attendee-questions-for-organisers` → HTTP 403
- `/help/vendors/saved-question-templates` → HTTP 403

This is expected because both articles are vendor audience.

## Notes

Privacy-first wording was preserved. The articles explain that organisers should collect only what they need and that saved templates are copied into events rather than live-linked.
