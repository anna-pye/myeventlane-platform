# Vendor Experience Convergence — Language Guide

**Status:** Product authority (documentation only)  
**Date:** 2026-07-22  
**Related:** [`vendor-experience-convergence.md`](vendor-experience-convergence.md)  
**Locale:** Australian English

---

## 1. Rules

1. **Human copy = Organiser.** Machine/URLs may remain `vendor` / `/vendor/*`.
2. If a word requires Drupal knowledge, it is banned in organiser UI.
3. Prefer verbs organisers use: Create, Publish, Share, Message, Refund, Check in.
4. Prefer Australian spelling: organise, favour, colour (in brand-facing prose where applicable), cancelled, enrolment only if domain requires.

---

## 2. Master terminology table

| Term | Classification | Organiser language | Notes |
| --- | --- | --- | --- |
| Vendor | **Rename** | Organiser | Keep in code/URLs |
| Vendor settings | **Rename** | Organiser settings | |
| Vendor help | **Rename** | Help | |
| Event Organisers (public) | **Keep** | Event organisers | |
| Drupal | **Hide** | — | Never |
| Commerce | **Hide** | — | Especially Studio nav group |
| Store | **Hide** | — | Payments / your account |
| Product | **Hide** | Ticket / Ticket type | |
| Ticket Product | **Hide** | Ticket | Field labels |
| Product variation / Variation | **Hide** | Ticket option / Price option | |
| SKU | **Hide** | Code (internal only if needed) | |
| Order item | **Hide** | Item on order / Ticket in order | |
| Gateway | **Hide** | Payments / Stripe | |
| Payment gateway | **Hide** | Payment method / Stripe | |
| Media entity / Media | **Hide** | Image / Photo | |
| Taxonomy / Term | **Hide** | Category / Tag | |
| Paragraph | **Hide** | Content block / Section | |
| Node / Entity / Bundle / Field | **Hide** | — | |
| Entity reference | **Hide** | — | |
| Event Studio | **Rename** (soft) | Event Workspace / Edit event | Brand “Studio” optional in marketing |
| Event Editor | **Rename** | Edit event | |
| Event Manager | **Rename** | Event Workspace | |
| Manage event | **Rename** | Overview | |
| Advanced ticket manager | **Rename** | Advanced ticket tools | Progressive disclosure |
| Ticket holders | **Rename** | Attendees | |
| Guest list | **Keep** | Guest list | Synonym OK |
| RSVP | **Keep** | RSVP | Distinct mode, same Attendees home |
| Waitlist | **Keep** | Waitlist | |
| Check-in / Check In | **Rename** (unify) | Check in / Door Mode | Prefer Door Mode for live ops |
| Live operations | **Rename** | Door Mode / Live at the door | |
| Attendee Messaging | **Rename** | Messages | |
| Visibility & updates | **Rename** | Publishing / Updates | Split concepts |
| Grow event | **Rename** | Marketing | |
| Boost | **Keep** | Boost | Paid promotion product name |
| Promote | **Rename** | Marketing / Boost | Avoid synonym soup |
| Insights | **Rename** | Analytics | Or “Insight” as a metric card, not a product name |
| Analytics Dashboard | **Rename** | Analytics | |
| Reporting / Charts / Export Centre | **Merge language** | Analytics · Exports | |
| Payouts | **Keep** | Payouts | Under Payments |
| Refund requests | **Keep** | Refunds | Under Payments / event Orders |
| Collection / Fulfilment | **Keep / soft rename** | Collection | Merch pickup |
| Merch & add-ons | **Keep** | Merch & add-ons | Not under “Commerce” |
| Checkout questions | **Rename** | Guest questions | |
| Capacity | **Keep** | Capacity | |
| Access codes | **Keep** | Access codes | |
| Widgets / Purchase surface | **Rename** | Embed / Ticket widget | Hide “purchase surface” |
| Pro | **Keep** | MyEventLane Pro | |
| Stripe Connect | **Rename** (soft) | Connect Stripe / Get paid | |
| Onboard | **Rename** (soft) | Set up / Get started | |
| Publish | **Keep** | Publish | |
| Unpublish | **Keep** | Unpublish / Take offline | |
| Archive | **Keep** | Archive | |
| Draft | **Keep** | Draft | |
| Order | **Keep** | Order | |
| Revenue | **Keep** | Revenue | |
| Support / Escalation | **Rename** (soft) | Support | Hide “escalation” unless staff |

---

## 3. Banned phrases (organiser UI)

Do not show:

- “Commerce linked / partial / invalid” as raw chips — translate to “Tickets connected”, “Tickets need attention”, “Tickets not set up”
- “Ticket product”
- “Select a store”
- “Product variation”
- “Entity”, “Node ID”, “Bundle”
- Env vars, PHP, Drush, gateway plugin IDs in errors

---

## 4. Preferred microcopy patterns

| Situation | Pattern |
| --- | --- |
| Blocked publish | “You can’t publish yet because {reason}. {Fix CTA}.” |
| Stripe incomplete | “Connect Stripe to get paid for tickets.” |
| Pro gate | “Analytics depth is included in Pro. See what’s included →” |
| Empty attendees | “No guests yet. Share your event to get your first booking.” |
| Success publish | “You’re live. Share your event link.” |
| Refund | “Refund {name}? This can’t be undone from here.” |

Tone: warm, specific, blame-free, Australian English.

---

## 5. Dual vocabulary policy

| Layer | Allowed |
| --- | --- |
| Organiser UI, emails, help centre (customer-facing) | Language guide only |
| Admin / staff tools | Technical terms OK |
| Code, routes, config keys | `vendor`, Commerce APIs OK |
| Support macros to organisers | Language guide only |

---

## 6. Rename sprint checklist (product)

Priority order for visible string sweeps:

1. Studio nav group **Commerce** → **Tickets & sales**
2. Ticket Product / product / variation labels in ticket UI
3. Ticket holders → Attendees
4. Insights product name → Analytics (keep “insight” as noun in cards)
5. Vendor settings / Vendor help titles
6. Event Editor / Event Manager → Edit event / Event Workspace
7. Grow event → Marketing
8. Messaging surfaces → Messages
9. Help `/help/vendors` → organisers
10. Operational capability chips (Commerce language)

---

## 7. Glossary for support & design

| Say | Don’t say |
| --- | --- |
| Organiser | Vendor (to customers) |
| Event Workspace | Studio vs Manager |
| Ticket type | Product variation |
| Door Mode | QR validate endpoint |
| Payments | Commerce store |
| Guest questions | Checkout paragraph fields |
| Analytics | Reporting module |
| Messages | Vendor comms |
| Get paid with Stripe | Configure payment gateway |
