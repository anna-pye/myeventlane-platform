# Vendor Experience Convergence — Information Architecture

**Status:** Product authority (documentation only)  
**Date:** 2026-07-22  
**Related:** [`vendor-experience-convergence.md`](vendor-experience-convergence.md)

---

## 1. Design objects (organiser mental model)

| Object | What it is | Not |
| --- | --- | --- |
| **Organiser** | The business or person running events | Drupal user role name alone; “Vendor” in UI |
| **Event** | The thing attendees come to | Node, content type |
| **Ticket** | A sellable or free admission option | Product, variation, SKU |
| **Attendee** | A person on the guest list (paid, RSVP, or complimentary) | Order item; paragraph |
| **Order** | A purchase record | Commerce admin order screen |
| **Message** | Email / update to an audience | Notification entity admin |
| **Payment** | Money in, out, and status | Store, gateway, payment method plugin |
| **Insight** | A metric answering a business question | Views report, Commerce report |

---

## 2. Two workspaces

### Global organiser workspace

Home for cross-event work.

```text
Dashboard
Events
Attendees (all events)
Orders (all events)
Messages (templates, brand, history)
Payments (Stripe, payouts, refunds, tax)
Analytics (business pulse)
Marketing (Boost hub, share tools)
Settings
Support
```

### Per-event workspace

One application for one event. Builder and operations share one shell.

```text
Overview
Details
Schedule
Venue
Images
Tickets
Attendees
Messages
Marketing
Orders
Analytics
Publishing
Settings
```

**Rule:** No duplicated navigation between “Studio” and “Manager”. Context switch is Global ↔ Event, not Studio ↔ Manager.

---

## 3. Object relationships

```text
Organiser
  └── owns many Events
        ├── has many Tickets
        ├── has many Attendees (from Orders + RSVPs + Waitlist)
        ├── has many Orders
        ├── has many Messages
        ├── has Marketing (Boost, share, widgets)
        └── has Analytics (sales, attendance, traffic)

Organiser (global)
  ├── Payments (Stripe account, payouts, refunds queue)
  ├── Settings (profile, branding defaults, venues, questions)
  └── Support
```

---

## 4. Current IA → future IA

| Current product surface | Future home |
| --- | --- |
| Vendor dashboard | Global · Dashboard |
| Events list + create | Global · Events → Event Workspace |
| Event Studio | Event Workspace (builder sections) |
| Event Manager tabs | Event Workspace (ops sections) |
| Legacy manage `/vendor/event/*` | Redirect into Event Workspace |
| Build wizard | Staff-only or redirect |
| Advanced ticket manager | Event Workspace · Tickets · Advanced |
| Attendees / Ticket holders / RSVPs / Waitlist | Attendees |
| Door Mode / check-in stacks | Attendees · Door Mode |
| Promotion / Pro message / Studio messaging | Messages |
| Analytics / Insights / Charts / Exports | Analytics |
| Payouts / Stripe / Refunds / Finance BAS | Payments |
| Boost / Grow | Marketing |
| Settings / venues / branding / questions | Settings (+ event Settings) |
| Support / help | Support |

---

## 5. Event Workspace section map

| Section | Purpose | Primary action | Sources merged |
| --- | --- | --- | --- |
| **Overview** | Status, readiness, next step, KPIs | Continue setup / View public page | Studio overview + Manager overview |
| **Details** | Title, summary, description, category | Save | Studio information + content |
| **Schedule** | Date, time, timezone, multi-day | Save | Information datetime |
| **Venue** | Place, map, accessibility notes | Save / Use saved venue | Information + venues |
| **Images** | Hero, gallery | Upload | Branding / media (human terms) |
| **Tickets** | Types, price, capacity, codes, widgets | Add ticket | Studio tickets + advanced manager |
| **Attendees** | Guest list, RSVP, waitlist, Door Mode | Message / Check in / Export | Attendees + RSVP + ops |
| **Messages** | Announcements, reminders, cancel | Send / Schedule | Promotion + Studio messaging + Pro |
| **Marketing** | Share, Boost, embed widget | Boost / Copy link | Boost + promote |
| **Orders** | Sales list, order detail, refunds entry | View order | Manager orders + add-ons |
| **Analytics** | Sales, attendance, traffic | Export | Analytics + Insights + Studio |
| **Publishing** | Visibility, readiness, publish/unpublish | Publish | Studio messaging visibility + publish |
| **Settings** | Event-level prefs, cancel, archive | Save | Studio + Manager settings |

Deferred / advanced (not top-level until needed): Merch & add-ons, Series, Diagnostics, Collection.

---

## 6. Canonical URL strategy (product, not implementation)

| Future concept | Preferred path pattern |
| --- | --- |
| Global shell | `/vendor/{area}` |
| Event Workspace root | `/vendor/events/{id}` |
| Event Workspace section | `/vendor/events/{id}/{section}` |
| Create | `/vendor/events/create` |
| Door Mode | `/vendor/events/{id}/door` (alias OK to operations/door) |
| Payments | `/vendor/payments` (hub; existing payouts/stripe deep-link) |
| Analytics | `/vendor/analytics` |
| Messages | `/vendor/messages` (hub) + event `/…/messages` |

**Migration rule:** Plural `/vendor/events/` is canonical. Singular `/vendor/event/` redirects. `{event}` and `{node}` parameter naming is an engineering cleanup; organisers never see it.

---

## 7. Access & ownership (product rules)

1. Organiser sees only their workspace events and data.
2. UI hiding is never access control.
3. Team members inherit workspace parity per platform ADR.
4. Anonymous users never see private attendee, order, or payout data.
5. Pro gates show value + upgrade — never a bare denied page.

---

## 8. Empty states (IA-level)

Every primary object list has:

- Why it’s empty
- One primary CTA
- Optional secondary learn link

Examples:

| Screen | Empty copy direction |
| --- | --- |
| Events | “Create your first event” |
| Tickets | “Add a ticket so people can register” |
| Attendees | “Guests will appear here after their first booking” |
| Messages | “Send your first update when you’re ready” |
| Payments | “Connect Stripe to get paid” |

---

## 9. Convergence dependency graph

```text
P0 Trust (routes, permissions, language leaks)
  → P1 Navigation + Dashboard + Workspace shell + Onboarding
    → P2 Tickets + Attendees + Messages + Payments
      → P3 Analytics depth + Settings hub + Marketing polish
        → P4 Series + AI delight
```
