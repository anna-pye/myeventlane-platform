# Attendee questions and saved templates — QA verification log

**Date:** 2026-05-22  
**Branch:** `feature/help-verify-attendee-questions-docs`  
**Scope:** Help Centre drafts `attendee-questions-for-organisers.md`, `saved-question-templates.md`  
**Method:** Code and config audit; DDEV bootstrap (`ddev drush status` OK). No Drupal nodes, importer, or YAML export. No live browser checkout run (credentials not in scope).

## Test data

| Item | Value |
|------|--------|
| Event route pattern | `/vendor/events/{nid}/studio/questions` |
| Legacy redirect | `/vendor/event/{nid}/checkout-questions` → Event Studio `workspace_questions` |
| Library route | `/vendor/questions` |
| Export route | `/vendor/events/{nid}/attendees/export` |
| Example event nid | Not exercised in browser — use any paid/RSVP event owned by test organiser |

## Routes tested (code + routing)

| Route name | Path | Access |
|------------|------|--------|
| `myeventlane_event_studio.workspace_questions` | `/vendor/events/{node}/studio/questions` | `EventStudioAccess::access` + event vendor ownership |
| `myeventlane_vendor.manage_event.checkout_questions` | `/vendor/event/{event}/checkout-questions` | Redirects to workspace (see `VendorLegacyWizardRedirectSubscriber`) |
| `myeventlane_questions.library` | `/vendor/questions` | `view vendor question library` |
| `myeventlane_questions.add/edit/delete` | `/vendor/questions/...` | `manage vendor question library` + entity store match |
| `myeventlane_event_attendees.vendor_export` | `/vendor/events/{node}/attendees/export` | Vendor event access |

## Attendee question setup (Event Studio)

**Where organisers configure questions**

- Canonical UI: Event Studio section **Checkout questions** at `/vendor/events/{node}/studio/questions` (`EventCheckoutQuestionsForm`, `QuestionsSection`).
- Inline editor on main Event Studio form (Tickets area) still exposes **Reuse from library** and a CTA **Manage checkout questions**; saves via `EventStudioSaveService` with a **5-question cap** on that path only.
- Legacy vendor wizard step exists but redirects to the studio workspace.

**Per event vs tier vs order**

- Questions are stored on the **event** in `node.event.field_attendee_questions` (paragraph bundle `attendee_extra_field`).
- **Applicability** on each question: `per_ticket` (all ticket holders), `per_ticket_type` (selected ticket types), `per_order` (configurable in studio but **not active at checkout** — skipped with log notice).
- **Legacy:** questions can still live on individual **ticket types** (`field_attendee_questions` on ticket type when `field_use_ticket_attendee_questions` is enabled). Checkout merges event + tier templates (`CheckoutAttendeeSchemaService`).

**Required questions**

- `field_question_required` on each paragraph; enforced in checkout pane validation.

**Readiness warnings**

- `EventStudioQuestionTemplateManager::buildQuestionReadinessFindings()` — blockers for ticket-specific questions with no ticket types selected; warnings for `per_order`, questions with historical answers, and legacy tier-stored questions.

**Save blocking**

- `validateRows()` / `saveRows()` reject: empty labels, unsupported/legacy types in studio UI, option types without options, invalid ticket type references, duplicate active labels, and immutable mutations when answers exist.

## Field / question types

**Event Studio checkout questions** (`QuestionFieldTypeRegistry`, studio UI excludes legacy `tel`):

| Type | Studio label | Options required |
|------|--------------|------------------|
| `textfield` | Text | No |
| `textarea` | Long text | No |
| `email` | Email | No |
| `number` | Number | No |
| `select` | Select | Yes (one per line) |
| `checkboxes` | Checkbox | Yes |
| `radios` | Radio | Yes |
| `tel` | Phone | Legacy only — not offered for new questions |

**Saved library** (`vendor_question` entity `field_question_type` allowed values):

- Text field, Textarea, Select (dropdown), Checkbox only — no Radio, Email, or Number in library forms.

## Checkout rendering

- Pane: `TicketHolderParagraphPane` (Commerce checkout).
- Vendor questions shown under **Organiser questions**; default name/email/phone collected separately (`classifyBasicAttendeeQuestion`).
- Questions render **per ticket holder** (each `attendee_answer` paragraph on the order item).
- Active questions only (`field_question_status` ≠ `archived`).
- `per_ticket_type` questions filtered to the ticket type on the line item.
- `per_order` questions **not rendered** (deferred; storage not enabled).

## Answer storage

- Templates: `attendee_extra_field` paragraphs on the event (and optionally on ticket types).
- Answers: child paragraphs on each ticket holder (`attendee_answer` → `field_attendee_questions` → answer rows with `field_attendee_extra_field` and question metadata).
- Historical-answer detection: `EventStudioQuestionAnswerExistenceRepository` (SQL join on order items for the event).
- RSVP: `EventAttendeeQuestionCaptureService` uses same event templates.

## Organiser visibility and export

- Attendee list and order views expose `custom_answers` / `custom_answers_display` (`VendorAttendeePresentationService`).
- CSV export: `/vendor/events/{node}/attendees/export` — column **Custom answers** (aggregated string), not one column per question (`MelAttendeeExportBuilder`).

## Saved question templates

**Entity:** `vendor_question` (store-scoped via `field_store`).

**Clone vs reference**

- `QuestionTemplateCloner::cloneToParagraph()` creates a **new** `attendee_extra_field` paragraph on the event.
- Editing or deleting a library template **does not** change questions already attached to published events.
- Adding from library in Event Studio main form sets `vendor_question_id` in JSON; save clones template if accessible.

**Tier targeting in library**

- Not stored on `vendor_question`. After clone, organisers set applicability and ticket types in **Checkout questions** workspace.

**Duplicate prevention**

- Event-level: unique active labels (`validateRows`).
- Checkout merge: dedupe by machine name or label (`questionTemplateDedupeKey`).
- Library: no duplicate-label enforcement found.

**Access control**

- `VendorQuestionAccessControlHandler`: admin full access; otherwise store must match organiser’s vendor store.
- Permissions defined in module (`view` / `manage vendor question library`) — **not found granted in `config/sync` role YAML**; assume granted to vendor roles in runtime or pending config export.

## Edit vs clone after sales

When `questionHasHistoricalAnswers()` is true, organisers **cannot** change: field type, applicability, required→optional, machine name, option values, or ticket-type targeting. They **can** adjust label/help copy, ordering, and **archive** the question. UI marks rows as locked; readiness warning shown.

Editing a **saved library template** does not retroactively alter event questions or past answers.

## Privacy and sensitive data

| Check | Result |
|-------|--------|
| Free-text collection | Yes (`textfield`, `textarea`) |
| Product blocks sensitive questions | No dedicated validation |
| In-product privacy warnings | Studio copy: “Ask only what helps you prepare”; limit warning on inline editor (5 questions). **No** explicit sensitive-data warning in checkout questions form or library |
| Answer storage | Standard Drupal paragraph/DB; export available to event organiser |
| Help wording | Drafts must warn organisers to collect only necessary data and avoid unnecessary sensitive data |

## Article readiness

| Draft | Ready to publish? | Ready to export? | Notes |
|-------|-------------------|------------------|-------|
| `attendee-questions-for-organisers.md` | Yes | Yes | Wording tightened to studio route, types, archive/immutability, export column |
| `saved-question-templates.md` | Yes | Yes | Clone semantics, library types, attach paths documented |

## Wording constraints for Help Assistant

- Use **organiser** in user-facing copy.
- Do not claim `per_order` questions work at checkout.
- Do not claim library templates update live events.
- Do not claim safe wholesale edits after ticket sales.
- Do not list Radio/Email/Number for saved library — only Event Studio custom questions.
- Mention CSV **Custom answers** column, not per-question export columns.

## Remaining blockers (product / ops, not draft)

- `per_order` applicability UI exists but checkout capture deferred.
- Library permissions may need role assignment verification in deployed environments.
- Two UIs: dedicated checkout questions table (no 5-question cap) vs inline Event Studio editor (5-question cap) — document organiser path via **Checkout questions** workspace as primary.
- No automated sensitive-data classifier — governance is organiser responsibility + help copy.

## Export recommendation

Recommend YAML batch **05** (`help-articles-batch-05-2026-05.yml`) with keys `attendee_questions_for_organisers`, `saved_question_templates` when export is authorised.
