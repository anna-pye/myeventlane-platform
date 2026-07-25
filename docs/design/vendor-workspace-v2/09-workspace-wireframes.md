# Vendor Workspace v2 — High-Fidelity Wireframes

**Status:** High-fidelity product design (Sprint 1B) — documentation only  
**Date:** 2026-07-25  
**Surface:** Event Workspace Home (Mission Control) + chrome behaviours  
**Authority:** PDS 03 · 05 · 06 · 08 · 11 · 13 · 14 · prepared DDR-008/009  
**Transitional URL shown:** `/vendor/events/{id}/studio` until DDR-008 Accepted  

These wireframes **redesign composition and hierarchy**. They do not rearrange today’s dense cards into a prettier admin page.

---

## Design intent

| Feel | Means |
| --- | --- |
| Calm | Generous whitespace; one story per band |
| Professional | Clear type hierarchy; no novelty chrome |
| Fast | Next action above the fold; ≤1 primary |
| Reliable | Status + readiness honesty |
| Focused | Lifecycle emphasis, not equal-weight dashboard tiles |
| Helpful | Plain-language recovery |
| Premium | Restraint; quality empty states |

**Avoid:** Nested cards, equal-weight KPI walls, dual primaries, CMS forms as Home, decorative charts.

---

## Shared chrome (all breakpoints)

### Event chrome

```text
← Events    {Event name}                    [Status]    [ Primary CTA ]  [ View ]
            {Date · Venue short}                        (one only)       [ Share ]
```

### Section nav membership (DDR-009 proposed)

```text
Overview · Details · Schedule · Venue · Images · Tickets
Attendees · Messages · Marketing · Orders · Analytics · Publishing · Settings
```

Lifecycle changes **emphasis** (e.g. Live pins Attendees), not membership.

### Drawer (tablet / mobile)

- Section nav in left drawer (tablet) or full-height drawer (mobile)
- Focus trap + Esc + return focus (PDS 07)
- Mobile priority items first; builder sections grouped under “Setup”

### Sticky actions

| Breakpoint | Behaviour |
| --- | --- |
| Desktop | Primary CTA in event chrome; no duplicate sticky unless destructive confirm |
| Tablet | Chrome CTA remains; drawer for nav |
| 390px | Sticky bottom bar: Primary CTA + optional overflow (⋯) for Share/View |

---

## A. Desktop (≥1200px) — Workspace Home

### Selling state (canonical “run my event” composition)

```text
┌─ GLOBAL HEADER ─────────────────────────────────────────────────────────────┐
│ MyEventLane  Organiser    [Create event]              Help   Account        │
├─ GLOBAL NAV ─┬─ EVENT WORKSPACE ────────────────────────────────────────────┤
│ Dashboard    │ ← Events   Summer Night Market          On sale   [Orders] [View]
│ Events ●     │            Sat 18 Oct · Fitzroy                   primary  [Share]
│ Orders       ├─ SECTIONS ───────────────────────────────────────────────────┤
│ Attendees    │ Overview● Details Schedule Venue Images Tickets Attendees … │
│ Messages     ├─ MAIN (Reading intent — calm column) ────────────────────────┤
│ Payments     │                                                              │
│ Analytics    │  ┌─ TODAY’S FOCUS ─────────────────────────────────────────┐ │
│ Marketing    │  │ Tickets are moving — 42 sold. Two types nearly full.  │ │
│ Settings     │  │                                          [ View orders ]│ │
│ Support      │  └──────────────────────────────────────────────────────────┘ │
│              │                                                              │
│              │  Health · On sale · Ready · Stripe connected                 │
│              │  ─────────────────────────────────────────────────────────   │
│              │                                                              │
│              │  OPERATIONAL PULSE                          (not a card wall)│
│              │  Tickets        42 sold · 18 left     →                      │
│              │  Attendees      40 expected           →                      │
│              │  Orders         $1,280 · 2 refunds    →                      │
│              │  Messages       1 draft               →                      │
│              │                                                              │
│              │  GROWTH (quiet)                                              │
│              │  Marketing · last shared 2d ago              [ Share ]       │
│              │  Analytics · views this week                  →              │
│              │                                                              │
│              │  Activity (grouped, scannable)                               │
│              │  · Order #1842 paid · 2h ago                                 │
│              │  · Ticket “Early” 90% sold · 5h ago                          │
│              │                                                              │
│              │  Setup complete · Publishing · Settings          (muted)     │
└──────────────┴──────────────────────────────────────────────────────────────┘
```

### Whitespace hierarchy (desktop)

1. **Band 0** — Event chrome (identity + one CTA)  
2. **Band 1** — Today’s Focus (single narrative + primary)  
3. **Band 2** — Health strip (status · readiness · payments) — one line  
4. **Band 3** — Operational pulse (list rows, not nested cards)  
5. **Band 4** — Growth quiet + activity  
6. **Band 5** — Setup/Publishing/Settings muted footer links  

### Draft state differences (desktop)

- Today’s Focus = next readiness blocker + **Continue setup**
- Operational pulse muted / collapsed
- Health strip shows “Draft · 3 of 8 ready”
- Primary CTA in chrome = Continue setup

### Live state differences (desktop)

- Status unmistakable **Live**
- Today’s Focus = Door readiness + **Open Door Mode**
- Operational pulse leads with Attendees checked-in/remaining
- Builder links hidden under “Setup” disclosure

### Completed state differences (desktop)

- Tone closure; Marketing demoted
- Primary = Review orders / Analytics
- No Boost pressure

---

## B. Tablet (768–1199px)

```text
┌─ GLOBAL HEADER (compact) ──────────────────────────────────────────┐
│ ☰  MEL Organiser          [Create]              Account            │
├────────────────────────────────────────────────────────────────────┤
│ ← Events   Summer Night Market     On sale    [ Orders ]  [ ⋯ ]   │
│            Sat 18 Oct                                             │
├─ SECTION CHIP ROW (scroll) ────────────────────────────────────────┤
│ Overview●  Tickets  Attendees  Orders  More…                      │
├────────────────────────────────────────────────────────────────────┤
│ TODAY’S FOCUS                                                     │
│ Tickets are moving — 42 sold.                                     │
│                                              [ View orders ]      │
├────────────────────────────────────────────────────────────────────┤
│ Health · On sale · Ready · Stripe OK                              │
├────────────────────────────────────────────────────────────────────┤
│ Pulse rows (full width)                                           │
│ Tickets →   Attendees →   Orders →   Messages →                   │
├────────────────────────────────────────────────────────────────────┤
│ Activity                                                          │
└────────────────────────────────────────────────────────────────────┘

☰ opens global nav drawer
⋯ opens Share / View / Publishing shortcuts
“More…” opens Workspace section drawer (full section list)
```

**Tablet rules**

- Chip row shows lifecycle-priority sections; full list in drawer  
- Today’s Focus remains above pulse  
- No dual sidebars visible simultaneously  
- Touch targets ≥44px  

---

## C. 390px Mobile — Workspace Home (Selling)

```text
┌────────────────────────────┐
│ ☰  MEL        [Create]  👤 │
├────────────────────────────┤
│ ← Events                   │
│ Summer Night Market        │
│ On sale · Sat 18 Oct       │
├────────────────────────────┤
│ TODAY’S FOCUS              │
│ Tickets moving · 42 sold   │
│                            │
│ [ View orders ]            │  ← promoted (order: -1 pattern preserved)
├────────────────────────────┤
│ On sale · Ready · Stripe OK│
├────────────────────────────┤
│ Tickets          42 sold › │
│ Attendees      40 expect › │
│ Orders           $1,280 ›  │
│ Messages        1 draft ›  │
├────────────────────────────┤
│ Share event              › │
│ Activity                   │
│ · Order paid · 2h          │
├────────────────────────────┤
│                            │
│                            │
├─ STICKY ───────────────────┤
│ [ View orders ]      [ ⋯ ] │
└────────────────────────────┘
```

### 390px Live

```text
┌────────────────────────────┐
│ Summer Night Market        │
│ ● LIVE · Door open         │
├────────────────────────────┤
│ TODAY’S FOCUS              │
│ Check guests in as they    │
│ arrive.                    │
│ [ Open Door Mode ]         │
├────────────────────────────┤
│ Checked in  28 / 40        │
│ Exceptions             2 › │
├────────────────────────────┤
│ Messages · Orders (muted)  │
├─ STICKY ───────────────────┤
│ [ Open Door Mode ]         │
└────────────────────────────┘
```

### Mobile drawer — sections

```text
┌─ Workspace ───────────────┐
│ Overview                  │
│ ── Run event ──           │
│ Tickets                   │
│ Attendees                 │
│   Door Mode               │
│ Messages                  │
│ Orders                    │
│ Marketing                 │
│ Analytics                 │
│ ── Setup ──               │
│ Details · Schedule · …    │
│ Publishing · Settings     │
│                     Close │
└───────────────────────────┘
```

---

## Section body wireframes (not Home)

Home is Mission Control. Other sections use Workspace layout intent with a **section header** (title · short purpose · secondary tools) — not a second hero stack.

### Tickets

```text
Tickets
Sellable options for this event

[ Add ticket type ]

Early bird     $25 · 90% sold · On sale     [ Edit ]
GA             $35 · On sale                [ Edit ]
Comp           Free · Hidden                [ Edit ]

Advanced inventory (disclosure) → Commerce backstage tools
```

### Attendees

```text
Attendees                         [ Open Door Mode ]
40 expected · 2 exceptions

[ Search guests ]

Name · Ticket · Status · Actions
…
```

### Orders

```text
Orders                            (Commerce truth)
$1,280 · 36 orders · 2 refunds

Filters · Export (if permitted)

Order · Buyer · Total · State · →
```

### Messages

```text
Messages for this event
Audience: ticket holders (clarify before send)

[ New message ]

Drafts · Sent history (event-scoped)
Brand/templates → global Messages hub (link out)
```

### Marketing

```text
Marketing
Share · Embed · Boost

Public link                    [ Copy ]
Boost status · spend clear     [ Boost ]
```

### Analytics

```text
Analytics (this event)
Views · Sales over time · Top tickets
Text alternatives for critical figures
Pro-gated depth if product requires — honest about limits
```

### Publishing

```text
Publishing
Status: Ready / Published
Checklist with recovery links
[ Publish ] or [ Unpublish ] — explicit confirm
Stripe attention if paid path blocked
```

### Settings

```text
Settings
Event-level preferences · danger zone (archive/unpublish)
Ownership-sensitive toggles · confirm patterns
```

### Help (contextual)

Inline “Need help?” near blockers; links to Help Centre articles with **organiser** audience only — never staff playbooks.

---

## Primary / secondary CTA matrix (chrome)

| State | Primary | Secondary |
| --- | --- | --- |
| Draft | Continue setup | Preview |
| Ready | Publish / Go to Publishing | Preview |
| Published | Share | View public |
| Selling | View orders *or* Adjust tickets | Share |
| Upcoming | Review attendees | Message |
| Live | Open Door Mode | Exceptions |
| Completed | Review orders | Analytics |

\* Resolved by attention rules (errors → lifecycle primary → growth).

---

## What we deliberately did **not** wireframe

- A second global “Workspace” nav item  
- Nested card grids of six equal ops tiles as the hero  
- Manager dual product  
- CMS node-edit as Home  
- Decorative “mission control” gauges without action  

---

## Traceability to runtime seeds

| Wireframe band | Extend (do not replace) |
| --- | --- |
| Event chrome | Studio topbar + VM `event` |
| Today’s Focus | Unify `todays_focus` + `next_action` presentation |
| Health strip | Readiness facade + Stripe gate signals |
| Pulse rows | Home cards flattened to rows |
| Activity | Overview activity feed |
| Door entry | Attendees + `vendor_operations_door` |
| Sticky mobile CTA | Existing `<720px` next-action priority pattern |

---

## Acceptance notes for future implementation

Wireframes are approved for composition **only after** human review of this sprint. Path labels may show DDR-002 target URLs in mock copy once DDR-008 Accepted; until then engineering uses `/studio`.
