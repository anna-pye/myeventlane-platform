---
title: Saved question templates
audience: vendor
article_type: guide
product_area: checkout
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/saved-question-templates
source_evidence: /vendor/questions library routes; Event Studio "Choose a saved question"; myeventlane_questions module; EventStudioQuestionTemplateManager
needs_verification: Permissions required for library access; whether editing a saved template updates past events; clone vs link behaviour in Event Studio
---

# Saved question templates

Reuse common checkout questions across events by saving them to your question library. This saves time and keeps wording consistent.

## What this means

A saved question template is a reusable definition (label, field type, and options) stored in your organiser library. When you build a new event, you can add a saved question instead of typing the same text again.

## What to do next

1. Sign in to your organiser account.
2. Open your question library at `/vendor/questions`.
3. Select **Add question** and enter a clear label buyers will understand.
4. Choose the field type and any choices (for dropdowns or checkboxes).
5. Save the template.
6. In Event Studio or checkout questions for an event, pick **Choose a saved question** (or equivalent) to attach it to the event.
7. Adjust event-specific settings if prompted, then preview checkout before publishing.

## Good to know

- Saved templates are for your organiser account. They are not visible to buyers until you attach them to an event.
- If buyers have already answered a question on live bookings, treat changes carefully. Prefer archiving an old template and creating a new question rather than changing the meaning of an existing one — **Needs verification** for library edit behaviour.
- Keep questions proportionate. A long library of rarely used fields slows checkout.
- Do not store sensitive personal data in reusable templates unless you need it for every event that uses them.

## Related help

- Collecting attendee questions at checkout
- Managing your event dashboard
- Community guidelines
