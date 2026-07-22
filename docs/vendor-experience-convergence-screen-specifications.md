# Vendor Experience Convergence — Screen Specifications

**Status:** Product authority (documentation only)  
**Date:** 2026-07-22  
**Related:** [`vendor-experience-convergence.md`](vendor-experience-convergence.md)

Each screen answers: **What is the organiser trying to achieve right now?**

Format: Purpose · Primary action · Content · States · Notes

---

## A. Global screens

### A1. Dashboard

| | |
| --- | --- |
| **Purpose** | Know what needs attention; celebrate momentum |
| **Primary action** | Top action-queue item (or Create event if empty) |
| **Content** | Action queue first; KPI strip (revenue, tickets sold, upcoming events, attention items); recent events; Stripe health chip; optional Pro value card |
| **States** | New organiser empty; active; attention (Stripe/refunds); Pro upsell (value-first) |
| **Notes** | Business health, not CMS overview. Mobile: queue then KPIs |

### A2. Events list

| | |
| --- | --- |
| **Purpose** | Find and open events; create new |
| **Primary action** | Create event |
| **Content** | Filters (Upcoming / Past / Draft); cards with status, date, sold/capacity, next step |
| **States** | Empty → guided create; draft resume prompt when applicable |
| **Notes** | No separate “Event Editor” entry |

### A3. Create event / draft choice

| | |
| --- | --- |
| **Purpose** | Start cleanly without silent resume |
| **Primary action** | Continue draft **or** Start new |
| **Content** | Explicit choice when resumable draft exists; then Studio/Workspace create |
| **States** | No draft; one draft; multiple drafts (list) |
| **Notes** | Wire all Create CTAs through this rule |

### A4. Attendees (global)

| | |
| --- | --- |
| **Purpose** | Search guests across events |
| **Primary action** | Open event attendees / Message |
| **Content** | Search, event filter, status chips; deep link to event Attendees |
| **States** | Empty; results; no permission |
| **Notes** | Replaces “Ticket holders” framing |

### A5. Orders (global hub)

| | |
| --- | --- |
| **Purpose** | Find recent sales and refunds entry points |
| **Primary action** | View order |
| **Content** | Recent orders across events; amount; event; status |
| **States** | Empty; list; Pro/export if gated |
| **Notes** | New hub; today mostly per-event |

### A6. Messages (global)

| | |
| --- | --- |
| **Purpose** | Brand, templates, history, start a send |
| **Primary action** | New message |
| **Content** | Brand settings; templates; recent sends; analytics summary |
| **States** | Unbranded; ready; sending limits |
| **Notes** | Merges messaging brand + fragmented send entry |

### A7. Payments

| | |
| --- | --- |
| **Purpose** | Understand money health and act |
| **Primary action** | Connect / Fix Stripe **or** View payouts |
| **Content** | Connection status; payouts; refunds queue; tax/BAS entry; failures; invoices (MEL contributions if any) |
| **States** | Not connected; needs attention; healthy; payout delayed |
| **Notes** | Never say Store / Gateway / Commerce |

### A8. Analytics (global)

| | |
| --- | --- |
| **Purpose** | Business pulse across events |
| **Primary action** | Open top event / Upgrade for depth |
| **Content** | Revenue, sales, attendance, traffic, conversion, refunds, top events, audience, marketing |
| **States** | Free pulse; Pro depth; empty new organiser |
| **Notes** | Merge Insights + Analytics + charts entry |

### A9. Marketing

| | |
| --- | --- |
| **Purpose** | Grow reach |
| **Primary action** | Boost an event / Copy share tools |
| **Content** | Boost hub; share tips; widgets entry; audience growth |
| **States** | No live events; eligible Boost; active campaigns |
| **Notes** | Renames Grow event |

### A10. Settings

| | |
| --- | --- |
| **Purpose** | Organiser defaults |
| **Primary action** | Save |
| **Content** | Profile; branding defaults; venues; guest question library; notification prefs; Pro billing |
| **States** | Incomplete profile; Pro manage |
| **Notes** | Consolidate branding satellites |

### A11. Support

| | |
| --- | --- |
| **Purpose** | Get help / resolve issues |
| **Primary action** | Contact support / Open case |
| **Content** | Help topics; open cases; refunds-in-support if relevant |
| **States** | None open; active case |
| **Notes** | Hide “escalation” jargon |

### A12. Onboarding steps

| | |
| --- | --- |
| **Purpose** | Become a ready organiser |
| **Primary action** | Continue current step |
| **Content** | Progress; why this step; skip where safe; celebrate completion |
| **States** | Per step incomplete/complete; Stripe deferred |
| **Notes** | Action-first; unlock create early |

---

## B. Event Workspace screens

### B1. Overview

| | |
| --- | --- |
| **Purpose** | Orient + next step |
| **Primary action** | Continue setup **or** Share / Door Mode |
| **Content** | Readiness; KPIs; attention; quick links to Tickets / Attendees / Messages |
| **Notes** | Single home — replaces dual Manager/Studio overview |

### B2. Details

| | |
| --- | --- |
| **Purpose** | Tell the story of the event |
| **Primary action** | Save |
| **Content** | Title, summary, description, category |
| **Notes** | No paragraph/entity language |

### B3. Schedule

| | |
| --- | --- |
| **Purpose** | When it happens |
| **Primary action** | Save |
| **Content** | Start/end, timezone, multi-day if needed |

### B4. Venue

| | |
| --- | --- |
| **Purpose** | Where it happens |
| **Primary action** | Save / Use saved venue |
| **Content** | Address, map preview, access notes |

### B5. Images

| | |
| --- | --- |
| **Purpose** | Visual identity for the event page |
| **Primary action** | Upload image |
| **Content** | Hero guidance; gallery optional |
| **Notes** | Say Image/Photo, not Media |

### B6. Tickets

| | |
| --- | --- |
| **Purpose** | Define how people get in |
| **Primary action** | Add ticket |
| **Content** | List of ticket types (GA, VIP, etc.); price; capacity; availability windows; free/paid; expand Advanced: groups, access codes, widgets, inventory |
| **States** | No tickets; RSVP-only mode; sold out; Stripe required for paid |
| **Notes** | One ticket application — hide Product/Variation |

### B7. Attendees

| | |
| --- | --- |
| **Purpose** | Manage the guest list |
| **Primary action** | Message / Door Mode / Export |
| **Content** | Search; filters (ticket type, RSVP, waitlist, checked in); bulk actions; refunds entry; CSV |
| **Mobile** | Check-in dense list; large targets |
| **Notes** | Merge paid + RSVP + waitlist UI |

### B8. Door Mode

| | |
| --- | --- |
| **Purpose** | Check people in at the door |
| **Primary action** | Scan / Search / Toggle check-in |
| **Content** | Scanner; search; recent activity; capacity remaining |
| **Notes** | Canonical check-in; retire parallel stacks via redirect |

### B9. Messages (event)

| | |
| --- | --- |
| **Purpose** | Communicate with this event’s audience |
| **Primary action** | New announcement |
| **Content** | Announcements; important updates; cancellation; reminders; audience selection; history; templates; schedule; send analytics; future AI assist |
| **Notes** | One Messages product |

### B10. Marketing (event)

| | |
| --- | --- |
| **Purpose** | Drive ticket sales for this event |
| **Primary action** | Share link / Start Boost |
| **Content** | Public URL; social share; Boost wizard entry; embed widget |
| **Notes** | Boost remains guided wizard |

### B11. Orders (event)

| | |
| --- | --- |
| **Purpose** | Inspect sales and refund |
| **Primary action** | View order |
| **Content** | Order list; detail; refund; add-on orders if enabled |

### B12. Analytics (event)

| | |
| --- | --- |
| **Purpose** | Understand performance |
| **Primary action** | Export / Upgrade |
| **Content** | Sales; attendance; traffic; conversion; refunds; Boost metrics |
| **Notes** | Free basics; Pro charts/compare |

### B13. Publishing

| | |
| --- | --- |
| **Purpose** | Control visibility |
| **Primary action** | Publish / Take offline |
| **Content** | Readiness checklist; visibility; why blocked |
| **Notes** | Celebrate publish |

### B14. Settings (event)

| | |
| --- | --- |
| **Purpose** | Event preferences and dangerous actions |
| **Primary action** | Save |
| **Content** | Prefs; cancel event; archive; danger zone explained |

---

## C. Ticket experience blueprint (Stage 5)

### Principles

- Never expose Commerce Product, Variation, or Product Reference
- Speak: Tickets · General Admission · VIP · Add Ticket · Availability · Pricing · Capacity · Bundles · Access Codes · Widgets · Inventory

### Flows

1. **Add ticket** — name → price (or Free) → capacity → sale window → save  
2. **Edit inventory** — remaining, pause sales  
3. **Access codes** — create code → limits → linked tickets  
4. **Widgets** — embed snippet for external sites  
5. **Advanced** — groups/bundles only when organiser expands

### Success criteria

Organiser can create GA + VIP without seeing the word Product.

---

## D. Attendee experience blueprint (Stage 6)

### One workspace includes

Paid tickets · RSVP · Waitlist · Messaging · Door Mode · Check-in · Exports · Refunds entry

### Navigation

Tabs or filters within Attendees — not separate top-level apps.

### Capabilities

| Capability | Spec |
| --- | --- |
| Search | Name, email, code |
| Filters | Type, status, checked-in, source |
| Bulk actions | Message, export, check-in (where safe) |
| Messaging | Pre-filled audience from selection |
| Refunds | From attendee/order context → Payments rules |
| CSV | Async if large; clear “ready to download” |
| Mobile check-in | Door Mode PWA-quality |

---

## E. Messaging blueprint (Stage 7)

### Product name: Messages

| Feature | Spec |
| --- | --- |
| Announcements | Standard update |
| Important updates | Higher urgency styling |
| Cancellation | Confirmed template + safety |
| Reminders | Schedule before start |
| Branding | Global defaults + event override |
| Audience | All · ticket type · RSVP · checked-in · custom |
| History | Sent, scheduled, failed |
| Templates | Reusable |
| Scheduling | Local timezone explicit |
| Analytics | Opens/clicks when available |
| Future AI | Assist draft — never auto-send without confirm |

---

## F. Analytics blueprint (Stage 8)

### Product name: Analytics

Merge: Analytics · Insights · Reporting · Charts · Boost metrics

### Business Dashboard modules

Revenue · Sales · Attendance · Traffic · Conversion · Refunds · Top events · Audience · Marketing

### Free vs Pro

| Free | Pro |
| --- | --- |
| Pulse KPIs | Deep charts, compare, advanced export |
| Per-event basics | PDF/Excel depth, longer ranges |

Never bare 403 — always upgrade story.

---

## G. Payments blueprint (Stage 9)

### Product name: Payments

| Area | Spec |
| --- | --- |
| Stripe onboarding | Guided Connect; celebrate success |
| Connection status | Connected / Needs attention / Incomplete + why |
| Payouts | Schedule, history, expected arrival language |
| Refunds | Request queue + order refunds |
| Tax | BAS/export entry in AU language |
| Invoices | MEL contributions if applicable |
| Failures | Retry + support path |
| Health | Dashboard chip mirrors Payments status |

Feel: **Payments** — never Commerce, Store, Gateway.
