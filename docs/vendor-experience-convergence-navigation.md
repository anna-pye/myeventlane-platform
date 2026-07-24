# Vendor Experience Convergence — Navigation Blueprint

**Status:** Product authority (documentation only)  
**Date:** 2026-07-22  
**Related:** [`vendor-experience-convergence.md`](vendor-experience-convergence.md), [`vendor-experience-convergence-information-architecture.md`](vendor-experience-convergence-information-architecture.md)

---

## Mission

Design **one** organiser navigation.

Hide forever from the shell:

Commerce · Content · Stores · Media · Taxonomy · Configuration

---

## 1. Target global navigation

| Order | Label | Job | Future location | Current sources to converge |
| --- | --- | --- | --- | --- |
| 1 | **Dashboard** | What needs attention now | `/vendor/dashboard` | Home |
| 2 | **Events** | Create and open events | `/vendor/events` | Events + Event Editor child |
| 3 | **Attendees** | Cross-event guest work | `/vendor/attendees` | Ticket holders |
| 4 | **Orders** | Cross-event sales | `/vendor/orders` (new hub; event orders remain) | Event orders only today |
| 5 | **Messages** | Brand, templates, history | `/vendor/messages` | Messaging brand + fragmented send UIs |
| 6 | **Payments** | Stripe, payouts, refunds, tax | `/vendor/payments` | Payouts + Stripe + refunds + finance |
| 7 | **Analytics** | Business pulse | `/vendor/analytics` | Insights label → Analytics route; merge Insights |
| 8 | **Marketing** | Boost, share, growth | `/vendor/marketing` | Grow event / Boost hub → Event Growth Centre (VX2-09) |
| 9 | **Settings** | Profile, venues, defaults | `/vendor/settings` | Organiser settings |
| 10 | **Support** | Help + escalations | `/vendor/support` | Support + Help |

**Count target:** ≤10 top-level items. Prefer 8 if Orders nest under Events and Marketing nests under Analytics on small screens — but product default is the ten above for clarity.

### Items removed from shell

| Current | Disposition |
| --- | --- |
| Event Editor (sibling of Events) | Open from Events / Create; not a peer nav item |
| Check-in (global) | Lives under Attendees · Door Mode (event-scoped primary) |
| Refund requests (global) | Payments · Refunds |
| Audience (orphan) | Marketing or Analytics · Audience |

---

## 2. Current shell (evidence)

Built by `VendorNavBuilder` → vendor theme sidebar.

**Today (approx.):** Home · Events (+ Event Editor) · Event Editor · Orders · Ticket holders · Check-in · Refund requests · Payouts · Grow event · Messaging · Insights · Support · Organiser settings

**Groups today:** Home / Events / Operations / Growth / Account

**Problems:**

1. Event Editor competes with Events.
2. Operations overweights refunds/check-in vs business flow.
3. Insights label vs Analytics product confusion.
4. Messaging points at brand, not send.
5. No Payments hub; money is scattered.

---

## 3. Desktop navigation

```text
┌──────────────────────────────────────────────────────────┐
│ MEL · {Organiser name}                    [Create event] │
├────────────┬─────────────────────────────────────────────┤
│ Dashboard  │  Main content                               │
│ Events     │                                             │
│ Attendees  │                                             │
│ Orders     │                                             │
│ Messages   │                                             │
│ Payments   │                                             │
│ Analytics  │                                             │
│ Marketing  │                                             │
│ ─────────  │                                             │
│ Settings   │                                             │
│ Support    │                                             │
└────────────┴─────────────────────────────────────────────┘
```

- Persistent left sidebar (≥1024px)
- Active state by section, not by Drupal route family
- Create event always visible as primary header CTA
- Account avatar menu: Profile · Settings · Help · Sign out

---

## 4. Tablet navigation

- Collapsible sidebar (icon rail + labels on expand)
- Same item order as desktop
- Create event remains sticky in header
- Event Workspace uses horizontal section tabs under event chrome when sidebar is icon-only

---

## 5. Mobile navigation

```text
┌─────────────────────────┐
│ Header · Create         │
│ Content                 │
├─────────────────────────┤
│ Home Events More        │  ← bottom bar (3–5)
└─────────────────────────┘
```

**Bottom bar (recommended):**

1. Home (Dashboard)
2. Events
3. Attendees
4. More → Orders, Messages, Payments, Analytics, Marketing, Settings, Support

Door Mode uses a dedicated full-screen chrome (minimal chrome, large scan targets) — not the marketing shell.

---

## 6. Per-event workspace navigation

Single secondary nav inside event chrome:

| Label | Section key |
| --- | --- |
| Home | overview |
| Details | details |
| Schedule | schedule |
| Venue | venue |
| Images | images |
| Tickets | tickets |
| Attendees | attendees |
| Messages | messages |
| Marketing | marketing |
| Orders | orders |
| Analytics | analytics |
| Publishing | publishing |
| Settings | settings |

**Rules:**

- One nav system (not Studio sidebar + Manager tabs)
- Advanced ticket tools nest under Tickets
- Door Mode is a mode of Attendees, not a peer top-level
- Merch / Collection appear under Tickets or Settings when enabled — not a second Commerce group

### Event chrome (always visible)

```text
← Events    {Event title}    Draft|Live|Past    [View page] [Publish|Share]
```

Context switcher: jump between recent events without returning to list.

---

## 7. Global vs event context switching

| From | Action | To |
| --- | --- | --- |
| Global Events | Click event | Event Workspace · Overview |
| Event Workspace | ← Events | Global Events list |
| Global Attendees | Click event name | Event Attendees |
| Global Analytics | Click event | Event Analytics |
| Deep link legacy | Auto-redirect | Canonical section + toast “Moved here” once |

Never open Studio in one tab model and Manager in another for the same task.

---

## 8. Quick actions

### Global (Dashboard / header)

| Action | Destination |
| --- | --- |
| Create event | Create flow |
| Connect Stripe | Payments |
| Message attendees | Messages (event picker if needed) |
| Open Door Mode | Event picker → Door Mode |

### Per-event

| Action | When shown |
| --- | --- |
| Publish | Draft + readiness near-complete |
| Share | Live |
| Boost | Live + eligible |
| Door Mode | Day-of / near start |
| Export guests | Has attendees |

One primary action in chrome; others in overflow.

---

## 9. Onboarding navigation

During `/vendor/onboard/*`:

- Reduced shell: Home · Events · Support
- Progress stepper for onboarding steps
- No full Growth / Operations distraction until complete or skippable milestone reached

---

## 10. Navigation migration matrix

| Current key / label | Action | Future |
| --- | --- | --- |
| Home | RENAME | Dashboard (label; route may stay) |
| Events | KEEP | Events |
| Event Editor | MERGE / HIDE from shell | Inside Events |
| Orders | KEEP + expand | Orders hub |
| Ticket holders | RENAME | Attendees |
| Check-in | MERGE | Attendees · Door Mode |
| Refund requests | MERGE | Payments · Refunds |
| Payouts | MERGE | Payments |
| Grow event | RENAME | Marketing |
| Messaging | RENAME / MERGE | Messages |
| Insights | RENAME | Analytics |
| Support | KEEP | Support |
| Organiser settings | KEEP | Settings |

---

## 11. Accessibility & IA quality bars

- Landmark: `nav` labelled “Organiser navigation”
- Current page announced (`aria-current`)
- Keyboard order matches visual order
- Touch targets ≥ 44px on mobile bottom bar
- Do not use colour alone for Live / Draft / Attention badges
