# Vendor Dashboard v2 — Wireframes (Sprint 1A)

**Status:** Design package only — no implementation  
**Authority:** [12-dashboard-philosophy.md](../vendor-studio/12-dashboard-philosophy.md) · [06-workspace-patterns.md](../vendor-studio/06-workspace-patterns.md) §1  
**Layout intent:** `.mel-layout--dashboard` / `--mel-layout-dashboard` ([03](../vendor-studio/03-layout-system.md), [DDR-003](../vendor-studio/decisions/DDR-003-layout-intents.md))  
**Components only from:** [05-component-library.md](../vendor-studio/05-component-library.md)

---

## Design intent

A completely new **composition** of the organiser home — not a new component system.

Every layout answers:

1. **Where am I?** — Global shell + Dashboard active + organiser identity  
2. **What needs me?** — Action Queue (or calm caught-up)  
3. **What should I do next?** — Top queue CTA, or Today’s focus Open event, or Create event

---

## Component map (no inventions)

| Region | PDS component |
| --- | --- |
| Identity + primary CTA | Workspace Hero (compact) |
| Needs attention | Task Lists + Action Cards |
| Today’s focus | Panel + Buttons + Badges (single interactive unit — not nested cards) |
| Upcoming | Panel / event row cards (one primary action each) |
| Business health | Metric Cards (≤4) |
| Recent activity | Panel + scannable list (organiser language) |
| Celebrations | Success panels (quiet, dismissible) |
| Help | Alerts / Help panels (quietest); Support link quiet |
| Empty first-run | Empty states |
| Shell | Existing vendor shell — [DDR-001](../vendor-studio/decisions/DDR-001-shell-navigation.md) |

---

## Desktop (≥1024px)

```text
┌──────────────────────────────────────────────────────────────────────────┐
│ SHELL HEADER — MEL · Organiser name · [Create event] · account           │
├────────────┬─────────────────────────────────────────────────────────────┤
│ Sidebar    │  .mel-layout--dashboard                                     │
│ Dashboard● │                                                             │
│ Events     │  ┌─ WORKSPACE HERO (compact) ─────────────────────────────┐ │
│ Orders     │  │ H1 Organiser name                                      │ │
│ …          │  │ Subtitle: calm status line (“Here’s what needs you”) │ │
│            │  │ Primary: Create event (if not already only in chrome)  │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ ACTION QUEUE (P0) ─────────────────────────────────────┐ │
│            │  │ H2 Needs attention                                     │ │
│            │  │ [Action Card] severity · title · reason · one CTA      │ │
│            │  │ [Action Card] …                                        │ │
│            │  │ — or Empty: “You’re caught up” + short reassurance     │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ TODAY’S FOCUS (P1) ───────────────────────────────────┐ │
│            │  │ H2 Today’s focus                                       │ │
│            │  │ Event title · status badge · when                      │ │
│            │  │ Primary: Open event  · Secondary: Door/check-in if due │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ UPCOMING (P2) ────────────────────────────────────────┐ │
│            │  │ H2 Upcoming events          link: View all events      │ │
│            │  │ [row] title · date · badge · Open                      │ │
│            │  │ [row] … (max ~4)                                       │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ BUSINESS HEALTH (P3) ─────────────────────────────────┐ │
│            │  │ H2 Business health                                     │ │
│            │  │ [Metric] [Metric] [Metric] [Metric]   ← ≤4, 4-col     │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ RECENT ACTIVITY (P4) ─────────────────────────────────┐ │
│            │  │ H2 Recent activity                                     │ │
│            │  │ chronological rows · optional link                     │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ CELEBRATIONS (P5, quiet) ─────────────────────────────┐ │
│            │  │ Success panel — milestone only when earned             │ │
│            │  └────────────────────────────────────────────────────────┘ │
│            │                                                             │
│            │  ┌─ HELP (quietest) ──────────────────────────────────────┐ │
│            │  │ Short guidance / Support link — not staff diagnostics  │ │
│            │  └────────────────────────────────────────────────────────┘ │
└────────────┴─────────────────────────────────────────────────────────────┘
```

### Desktop scanning path

Title → Create event → **Action Queue** → Today’s focus → Upcoming → KPIs → Activity.

### Desktop rules

- One H1 (organiser identity).  
- Action Queue visible **without expanders** on first paint.  
- No Pro checklist wall above the queue; Pro confirmation, if shown, is celebration-rank or Settings-adjacent.  
- Stripe/connect incomplete → **Action Card in queue** (and optional reading-width Alert), not a second hero competing above the queue.  
- No duplicate metric strips.  
- No Dashboard-local navigation.  
- Tools/analytics deep boards → link out to Analytics / Events hubs — do not embed marketing theatre on first paint ([19](../vendor-studio/19-anti-patterns.md)).

---

## Tablet (768px–1023px)

```text
┌────────────────────────────────────────────────────────────┐
│ Header (Create event reachable)                            │
├──────┬─────────────────────────────────────────────────────┤
│ Icon │ Workspace Hero                                      │
│ rail │ Action Queue (full width of content)                │
│  or  │ Today’s focus                                       │
│ overlay│ Upcoming (2-col cards OK if touch-friendly)       │
│      │ Metric Cards — 2 columns                            │
│      │ Activity → Celebrations → Help                      │
└──────┴─────────────────────────────────────────────────────┘
```

| Change from desktop | Why ([03](../vendor-studio/03-layout-system.md)) |
| --- | --- |
| Sidebar → icon rail / overlay | Preserve content width |
| Metrics 4 → 2 columns | Avoid cramped KPI cards |
| Upcoming may use 2-col summary cards | Touch scanning |
| Queue stays single column | Severity ranking remains readable |

Three Questions unchanged.

---

## Mobile (<768px, baseline 390px)

```text
┌─────────────────────────────┐
│ Header · section: Dashboard │
│ [Create event]              │
├─────────────────────────────┤
│ Workspace Hero (stacked)    │
│ H1 + one calm line          │
├─────────────────────────────┤
│ ACTION QUEUE first          │
│ Action Cards full width     │
│ or “You’re caught up”       │
├─────────────────────────────┤
│ Today’s focus               │
│ Open event (primary)        │
├─────────────────────────────┤
│ Upcoming (stacked rows)     │
├─────────────────────────────┤
│ Metrics — 2-column max      │
├─────────────────────────────┤
│ Activity / quiet help       │
└─────────────────────────────┘
  Nav: drawer / sheet (shell)
```

| Mobile rule | Source |
| --- | --- |
| Action Queue first after identity | [12](../vendor-studio/12-dashboard-philosophy.md) · [08](../vendor-studio/08-mobile-guidelines.md) |
| One primary action per viewport region | [01](../vendor-studio/01-vendor-studio-vision.md) |
| Metrics ≤2 columns | [08](../vendor-studio/08-mobile-guidelines.md) |
| Sticky Create only if it does not obscure errors | [12](../vendor-studio/12-dashboard-philosophy.md) — **product call** |
| No card-inside-card | [19](../vendor-studio/19-anti-patterns.md) |
| 44×44 targets | [08](../vendor-studio/08-mobile-guidelines.md) |

Detail: [04-dashboard-mobile.md](04-dashboard-mobile.md).

---

## Experience variants (wireframe states)

### A. First organiser — no events

```text
Hero: Welcome + why MEL helps
Empty state: Create event (primary) · Support (secondary quiet)
Action Queue: may include profile / Stripe / create first event cards
No KPI strip with vanity zeros dressed as insight — empty/honest metrics only
No Upcoming / Focus sections (or empty stubs with next step)
```

### B. Events exist — items need attention

```text
Queue populated (backend severity order)
Today’s focus when a time-sensitive event exists
Upcoming launcher
≤4 KPIs
Activity
```

### C. Caught up

```text
Queue empty state: “You’re caught up” + short calm line
Today’s focus / Upcoming still answer “what now?” for planning
KPIs sober
```

### D. Door / live today

```text
Queue may include door-related P1 items
Today’s focus elevated with Door/check-in secondary or primary as appropriate
No marketing panels above focus
```

### E. Stripe / payouts incomplete

```text
Action Card in queue: reason + Connect path (existing route only)
Optional Alert in reading width — not metrics theatre
```

---

## Content ranking (wireframe annotations)

| Rank | Block | First paint? |
| --- | --- | --- |
| P0 | Blocking Action Cards | Yes |
| P1 | Time-sensitive ops / Today’s focus | Yes |
| P2 | Upcoming | Yes |
| P3 | ≤4 KPIs | Yes (after P0–P2) |
| P4 | Activity | Yes if lightweight; else Sprint 1A+ |
| P5 | Celebrations | Only when earned |
| — | Pro checklist wall, Boost boards, analytics tables, full events grid | **Not** on first paint |

---

## Anti-patterns to reject in review

From [19](../vendor-studio/19-anti-patterns.md) / [12](../vendor-studio/12-dashboard-philosophy.md):

- Metrics above Action Queue  
- Empty void when caught up  
- Dual navigation  
- Customisable widget soup  
- Fake revenue while loading  
- Three primary buttons in one region  
- CMS terminology  

---

## Product decisions still required (not wireframe inventions)

1. Exact ≤4 KPI keys for v1 (candidates from current runtime: revenue, tickets sold, live events, upcoming count, attention count — **Product chooses ≤4**).  
2. Sprint 1A include Activity + Celebrations, or hierarchy + queue + KPIs only?  
3. Mobile sticky Create: yes/no.  
4. Empty-state copy owner (Design vs Product).

---

## Success criteria for this wireframe pack

- Matches [12](../vendor-studio/12-dashboard-philosophy.md) hierarchy  
- Uses only [05](../vendor-studio/05-component-library.md) contracts  
- Passes Three Questions on desktop, tablet, mobile  
- Ready for Gate B review before coding ([IMPLEMENTATION_WORKFLOW.md](../vendor-studio/IMPLEMENTATION_WORKFLOW.md))

**Next:** [03-dashboard-interactions.md](03-dashboard-interactions.md)
