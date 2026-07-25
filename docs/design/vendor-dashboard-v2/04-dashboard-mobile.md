# Vendor Dashboard v2 — Mobile (Sprint 1A)

**Status:** Design package only — no implementation  
**Authority:** [08-mobile-guidelines.md](../vendor-studio/08-mobile-guidelines.md) · [DDR-005](../vendor-studio/decisions/DDR-005-mobile-first.md) · [12-dashboard-philosophy.md](../vendor-studio/12-dashboard-philosophy.md)  
**Baseline:** 390px width; enhance upward  
**Layout structure:** [03-layout-system.md](../vendor-studio/03-layout-system.md) §4

---

## Mobile mission

On a phone, Dashboard must still answer:

1. Where am I?  
2. What needs me?  
3. What should I do next?

Mobile is a **first-class ops surface** for today’s attention and door-adjacent jobs — not a shrunk desktop ([08](../vendor-studio/08-mobile-guidelines.md)).

---

## Structure (390px)

```text
┌──────────────────────────────┐
│ Shell header                 │
│ “Dashboard” context label    │
│ Create event (reachable)     │
│ Menu → nav drawer/sheet      │
├──────────────────────────────┤
│ Compact Workspace Hero       │
│ H1 organiser · one calm line │
├──────────────────────────────┤
│ ★ ACTION QUEUE               │
│   full-width Action Cards    │
│   or “You’re caught up”      │
├──────────────────────────────┤
│ Today’s focus                │
│ Open event (primary)         │
│ Door/check-in if due         │
├──────────────────────────────┤
│ Upcoming (stacked rows)      │
│ one Open per row             │
├──────────────────────────────┤
│ Business health              │
│ 2×2 Metric Cards max         │
├──────────────────────────────┤
│ Recent activity (compact)    │
│ Help / Support (quiet)       │
└──────────────────────────────┘
```

---

## Navigation

| Rule | Application |
| --- | --- |
| Global nav in drawer/sheet | Reuse vendor shell — do not invent Dashboard bottom-nav |
| Show current section in header | “Dashboard” answers Where am I? |
| Create event reachable | Header or approved sticky primary |
| ≤5 priority destinations if bottom nav ever exists | Not required for Sprint 1A; do not ship a second mobile nav ([19](../vendor-studio/19-anti-patterns.md)) |

---

## Priority under vertical constraint

If the viewport is short, preserve in order ([12](../vendor-studio/12-dashboard-philosophy.md)):

1. Identity + Create event  
2. Action Queue  
3. Today’s focus  

Defer or collapse: celebrations, long activity, help copy. Never hide the top Action Card behind an accordion on first paint.

---

## Cards & touch

| Rule | Detail |
| --- | --- |
| Action Cards full width | One primary CTA each; min 44×44 |
| Upcoming as stacked interactive rows | Not a horizontal carousel of essential content |
| Metrics | 2-column grid maximum |
| No card-inside-card | [19](../vendor-studio/19-anti-patterns.md) |
| Hover-only actions | Forbidden |

---

## Sticky primary (product call)

| Option | When |
| --- | --- |
| Sticky Create event | Only if Product approves and sticky bar does **not** obscure queue errors/alerts |
| No sticky | Prefer when queue frequently shows errors — keep Create in header |

Decision required before implementation ([DASHBOARD_IMPLEMENTATION_PREPARATION.md](../vendor-studio/DASHBOARD_IMPLEMENTATION_PREPARATION.md) Q6).

Safe-area insets and elevation separation apply when sticky is used ([08](../vendor-studio/08-mobile-guidelines.md)).

---

## Door / live-today on mobile

| Need | Design |
| --- | --- |
| Event today / live | Today’s focus near top (after queue) |
| Check-in / Door Mode | Secondary or primary button with large target — destination is existing Door Mode / check-in route |
| Minimal chrome | Do not open marketing panels above focus |

Door Mode UI itself remains Attendees/event-scoped ([02](../vendor-studio/02-information-architecture.md)); Dashboard only launches it.

---

## Tablet bridge (768–1023)

- Metrics: 2 columns  
- Upcoming: optional 2-column cards if each card keeps one primary action  
- Sidebar: icon rail / overlay per [03](../vendor-studio/03-layout-system.md)  
- Queue: still single column and first after hero  

---

## Accessibility on mobile

- Visible focus for external keyboards  
- Severity not colour-only on Action Cards  
- `aria-busy` during load  
- Do not rely on swipe-only gestures for primary jobs  
- Respect `prefers-reduced-motion`  

---

## Anti-patterns (mobile-specific)

- Miniature persistent sidebar eating content width  
- Metric carousels that hide values off-screen  
- Multiple sticky bars  
- Boost/marketing sticky CTAs competing with Create event / queue  
- Shrinking desktop Tools accordion as the mobile “home”  

---

## DDR check

Mobile Dashboard behaviour is fully specified by [08](../vendor-studio/08-mobile-guidelines.md), [DDR-005](../vendor-studio/decisions/DDR-005-mobile-first.md), and [12](../vendor-studio/12-dashboard-philosophy.md). **No DDR required.**

**Next:** [05-dashboard-drupal-mapping.md](05-dashboard-drupal-mapping.md)
