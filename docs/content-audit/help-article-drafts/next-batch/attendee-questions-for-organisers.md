---
title: Collecting attendee questions at checkout
audience: vendor
article_type: guide
product_area: checkout
help_status: draft
ai_allowed: true
recommended_alias: /help/vendors/attendee-questions-for-organisers
source_evidence: /vendor/events/{node}/studio/questions; EventCheckoutQuestionsForm; CheckoutAttendeeSchemaService; event-studio-question-governance-audit.md; MelAttendeeExportBuilder
needs_verification: —
---

# Collecting attendee questions at checkout

You can ask buyers short questions during checkout when they enter ticket holder details. Use this to collect useful logistics information without gathering more personal data than you need.

## What this means

Attendee questions are custom fields attached to your **event** (and, for older setups, sometimes to individual ticket types). Answers are stored with each ticket holder on the booking so you can plan catering, accessibility, or session choices. Keep most questions optional unless you truly need an answer to admit someone or run the session safely.

## What to do next

1. Open the event in **Event Studio**.
2. Go to **Checkout questions** (`/vendor/events/{event}/studio/questions`). Older links such as `/vendor/event/{event}/checkout-questions` redirect here.
3. Add a clear **Question** label — buyers should understand why you are asking.
4. Choose a **Type** (short text, long text, email, number, dropdown, checkbox, or radio). For choice types, add one option per line.
5. Set **Required** only when you need every answer.
6. Under **Applies to**, choose **All ticket holders** or **Specific ticket types** when a question only applies to some tiers.
7. Leave new questions **Active**. Use **Archived** to retire a question without deleting past answers.
8. Save, then preview checkout as a buyer before you publish.
9. After sales, review answers on your attendee list or export attendees — custom answers appear in a **Custom answers** column in the CSV export.

## Good to know

- **Collect only what you need** to run the event. Avoid sensitive data such as full medical histories, government ID numbers, payment card details, or passwords unless you have a lawful reason and secure handling.
- Tell buyers how you will use their answers and how long you will keep them. Follow your privacy policy and applicable law.
- **Per order** questions can be configured in Event Studio but are **not collected at checkout yet** — use per-ticket or ticket-type questions instead.
- **First name, last name, and email** are collected separately for each ticket holder. Do not duplicate those unless your process truly requires it.
- If buyers have already answered a question, the system **locks** field type, choices, ticket targeting, and required rules. You can still tidy the label or help text, change order, or **archive** the question and add a new one — do not rewrite the meaning of a question that already has answers.
- Events with a long-standing setup may still have questions stored on **ticket types**; those continue to work at checkout, but new questions should be managed from **Checkout questions**.

## Related help

- Saved question templates
- Managing attendees and orders for your event
- Ticket sales and capacity
- Community guidelines
