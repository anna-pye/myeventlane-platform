---
title: Saved question templates
audience: vendor
article_type: guide
product_area: checkout
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/saved-question-templates
source_evidence: /vendor/questions; EventStudioForm library picker; QuestionTemplateCloner; VendorQuestionAccessControlHandler
needs_verification: —
---

# Saved question templates

Reuse common checkout questions across events by saving them to your organiser question library. This saves time and keeps wording consistent.

## What this means

A saved question template is a reusable definition (label, field type, options, and whether it is required) stored for your **organiser account** (Commerce store). Templates are **not** shown to buyers until you add them to an event. Adding a template **copies** it onto the event as its own question — it is not a live link.

## What to do next

1. Sign in to your organiser account.
2. Open your question library at `/vendor/questions` (you need permission to view the library).
3. Select **Add question** and enter a clear label buyers will understand.
4. Choose the field type: **Text field**, **Textarea**, **Select (dropdown)**, or **Checkbox**. For select or checkbox, enter one option per line.
5. Optionally mark the template as **Required** and add help text.
6. Save the template.
7. On the event, either:
   - In **Event Studio**, use **Reuse from library** on the tickets/attendee area and **Add from library**, then open **Checkout questions** to set ticket targeting; or
   - In **Checkout questions** (`/vendor/events/{event}/studio/questions`), recreate or adjust questions manually to match your template.
8. Set **Applies to** and ticket types on the event copy if needed, then preview checkout before publishing.

## Good to know

- Templates belong to your organiser store. Another organiser cannot see or edit your library.
- **Editing a saved template does not change** questions already on live events or answers buyers submitted. Update the library for future events, or archive the event question and add a new one.
- The library supports fewer field types than **Checkout questions** on an event (for example, radio, email, and number are event-only).
- Saved templates do not include **ticket type targeting** — set that on the event after the question is attached.
- If buyers have already answered a question on the event, treat changes like any other checkout question: archive and add anew rather than changing type or choices.
- Keep the library small. Long lists of rarely used fields slow checkout.

## Related help

- Collecting attendee questions at checkout
- Managing your event dashboard
- Community guidelines
