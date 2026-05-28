# Help articles batch 05 — export notes

**Date:** 2026-05-22  
**YAML:** `docs/content-audit/help-article-exports/help-articles-batch-05-2026-05.yml`

## Articles included

| Stable key | Title | Alias | Audience |
|------------|-------|-------|----------|
| `attendee_questions_for_organisers` | Collecting attendee questions at checkout | `/help/vendors/attendee-questions-for-organisers` | vendor |
| `saved_question_templates` | Saved question templates | `/help/vendors/saved-question-templates` | vendor |

## Verification source

- Drafts: `docs/content-audit/help-article-drafts/next-batch/attendee-questions-for-organisers.md`, `saved-question-templates.md`
- QA log: `docs/content-audit/help-article-drafts/next-batch/attendee-questions-template-verification.md`
- Register: `docs/content-audit/help-article-drafts/next-batch/next-batch-register.md`

## Privacy constraints applied in YAML

- Organisers should **collect only what they need** to run the event.
- Custom answers may contain **personal information**; exports and attendee lists should be handled accordingly.
- Do not encourage collecting sensitive data (medical histories, government IDs, payment card details, passwords) without lawful basis and secure handling.
- Explicit note that MyEventLane does **not** automatically block sensitive questions.

## Known limitations (preserved in article copy)

- **Per order** applicability can be configured in Event Studio but is **not collected at checkout yet**.
- Saved library templates are **cloned** onto events; editing the library does **not** update existing event questions or past answers.
- Library templates do **not** include ticket type targeting — set **Applies to** and ticket types on the event copy in **Checkout questions**.
- Library field types are a subset of Event Studio checkout question types (no radio, email, or number in the library).
- CSV export uses a single **Custom answers** column, not one column per question.
- Legacy questions may still exist on individual ticket types; new questions should use the **Checkout questions** workspace.

## Import commands

Use the YAML path as a **positional** argument (do not use `--file`).

**Dry-run:**

```bash
ddev drush mel:help-import-priority docs/content-audit/help-article-exports/help-articles-batch-05-2026-05.yml --dry-run
```

**Live import:**

```bash
ddev drush mel:help-import-priority docs/content-audit/help-article-exports/help-articles-batch-05-2026-05.yml --yes
```

**Post-import:**

```bash
ddev drush search-api:index mel_content
ddev drush search-api:status mel_content
ddev drush cr
```

Importer was **not** run as part of this export task.
