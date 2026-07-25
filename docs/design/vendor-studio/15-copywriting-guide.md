# Vendor Studio — Copywriting Guide

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Ensure every contributor writes with **one consistent voice** so organisers never feel like they entered a different product — or a CMS.

## Scope

Voice, tone, grammar, Australian English, UI labels, messages, payments/refunds wording, accessibility wording, preferred/avoided vocabulary, and Drupal/Commerce terminology translation. Brand consumer copy guidelines remain authoritative for public MEL; this guide owns **organiser console** copy.

## Audience

Product, design, content, engineers writing UI strings, support article authors for organiser audience.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md) — personality
- [14-visual-identity.md](14-visual-identity.md)
- [A01-glossary.md](appendices/A01-glossary.md)
- [docs/brand/copy-guidelines.md](../../brand/copy-guidelines.md)
- [docs/vendor-experience-convergence-language-guide.md](../../vendor-experience-convergence-language-guide.md)

---

## Voice

Warm · Capable · Local · Calm · Honest

We speak as a knowledgeable guide standing beside the organiser — never as a bureaucratic system, never as a hype machine.

---

## Tone

| Context | Tone |
| --- | --- |
| Setup / readiness | Encouraging, specific |
| Live ops / Door Mode | Brief, certain |
| Payments / refunds | Sober, precise |
| Errors | Clear, blame-free, recoverable |
| Success | Brief celebration + next step |
| Empty states | Honest + inviting action |

---

## Grammar

- Sentence case for headings and buttons  
- Prefer active voice  
- Prefer second person (“your event”)  
- Contractions OK when they sound natural  
- Avoid exclamation overload  

---

## Australian English

| Prefer | Avoid |
| --- | --- |
| Organiser | Organizer |
| Cancelled | Canceled |
| Colour (docs) | Color (except code tokens) |
| Favourite | Favorite |

Follow `docs/brand/copy-guidelines.md` for consumer-facing overlap.

---

## Button labels

| Pattern | Example |
| --- | --- |
| Verb + object | Create event · Refund order · Connect Stripe |
| Specific confirmations | Refund order — not OK / Confirm |
| Avoid vagueness | Submit · Click here · Continue (unless step context is obvious) |

One primary label per region.

---

## Headings

- One H1 that names the task  
- Headings describe organiser jobs, not modules  
- Question headings sparingly (“Ready to publish?”) when they aid readiness  

---

## Success messages

Structure: **What happened** + **What to do next**.

> Event published. Share your event link or view orders.

---

## Errors

Structure: **What went wrong** + **Why it matters** + **How to fix**.

> We couldn’t save your ticket. Check capacity is a whole number and try again.

Never: “An error has occurred.” alone.

---

## Warnings

Explain risk before irreversible actions. Do not soften money consequences.

> Refunding returns funds to the purchaser. This cannot be undone from Vendor Studio.

---

## Notifications

Short. Organiser language. Coalesce duplicates. Errors persist; successes may dismiss.

---

## Payments

- Use: payouts, Stripe connection, available balance (as product defines)  
- State honesty: never claim paid/payout ready without confirmed state  
- Prefer “Connect Stripe to receive payouts” over gateway jargon  

---

## Refunds

- Name the object: “Refund this order”  
- State partial vs full clearly when supported  
- Confirm with consequence sentence  
- UI does not invent refund success  

---

## Accessibility wording

- Link text describes destination (“View order 1042”, not “Click here”)  
- Icon-only controls need accessible names  
- Severity in text, not colour alone  
- Live regions: short status phrases (“Saved”, “Saving”, “Error saving”)  

---

## Preferred wording

discover · explore · find · join · experience · community · publish · draft · live · attendees · orders · tickets · payouts · Door Mode · ready · next step · organiser

Human labels: **Organiser**. Machine/URLs may say `vendor`.

---

## Avoided wording

exclusive · VIP · members only · secret access · limited access · FOMO pressure · configuration detected · entity · node · taxonomy · paragraph · product variation · store · commerce (in UI) · CMS · submit (as default money CTA)

---

## Drupal terminology translation

| Drupal / platform | Organiser copy |
| --- | --- |
| Node / content | Event (or the specific thing) |
| Media | Image / event image |
| Taxonomy | Category / tags (as product defines) |
| Unpublished | Draft (or exact visibility label) |
| User | Account / team member (context) |
| Permission denied | You don’t have access — ask your organiser admin / support path |

---

## Commerce terminology translation

| Commerce | Organiser copy |
| --- | --- |
| Product / variation | Ticket (or ticket type) |
| Order | Order |
| Order item | Line / ticket line (context) |
| Payment gateway | Payment method / Stripe |
| Completed / fulfilled states | Paid / confirmed — only when true |
| Adjustment | Fee / discount (plain language) |
| Store | (omit — not organiser-facing) |

---

## Examples of excellent copy

> You’re almost ready to publish. Add at least one ticket to continue.

> Payouts unavailable until Stripe is connected — here’s why.

> You’re caught up. Nothing needs you right now.

> Check in — large, certain, paired with attendee name.

---

## Examples of poor copy

> Incomplete configuration detected in field_event_tickets.

> VIP exclusive access unlocked!

> Error 403.

> OK

> Please submit the form to persist entity values.

---

## Design implications

- All new UI strings reviewed against this guide  
- Money and publish copy gets extra scrutiny  
- Staff-only help vocabulary never appears in organiser UI  

## Future considerations

- Locale expansion beyond en-AU must preserve organiser voice  
- Tone samples library for support macros  
- AI-generated copy must pass this guide before ship ([20](20-vendor-studio-v2-vision.md))  

## Related references

- [01](01-vendor-studio-vision.md) · [A01](appendices/A01-glossary.md) · [14](14-visual-identity.md) · [docs/brand/copy-guidelines.md](../../brand/copy-guidelines.md)
