# Vendor Studio — Layout System

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define how the Vendor Studio **shell and content regions** structure calm scanning paths — authoritative home for structural layout behaviour.

## Scope

Desktop/tablet/mobile structure, grid behaviour, containers (intents), header/sidebar/workspace/hero/panels. **Numeric token values** (spacing steps, max-widths, gutters) are owned by [11-design-tokens.md](11-design-tokens.md). Decision: [DDR-003](decisions/DDR-003-layout-intents.md).

## Audience

Theme architects, frontend engineers, designers.

## Related documents

- [11-design-tokens.md](11-design-tokens.md) — authoritative numbers
- [04-design-language.md](04-design-language.md)
- [08-mobile-guidelines.md](08-mobile-guidelines.md)
- [DDR-003](decisions/DDR-003-layout-intents.md)
- [`docs/brand/design-tokens.md`](../../brand/design-tokens.md)
- [`docs/implementation/vx2-02a-workspace-layout-convergence.md`](../../implementation/vx2-02a-workspace-layout-convergence.md)

---

## Why layout intents

Organisers scan under time pressure. Predictable structure answers “Where am I?” faster than decorative density. Full-width shells with intent-based content widths keep forms readable and boards roomy without inventing per-page pixel forks.

---

## 1. Structural model

```text
┌─────────────────────────────────────────────────────────────┐
│ Header (organiser identity · Create event · account)        │
├──────────────┬──────────────────────────────────────────────┤
│ Sidebar      │  Workspace content                           │
│ (global nav) │  ┌────────────────────────────────────────┐  │
│              │  │ Hero / page header                     │  │
│              │  ├────────────────────────────────────────┤  │
│              │  │ Panels / grids / tables                │  │
│              │  └────────────────────────────────────────┘  │
├──────────────┴──────────────────────────────────────────────┤
│ Footer (legal / secondary) — optional density               │
└─────────────────────────────────────────────────────────────┘
```

On mobile, sidebar becomes a drawer or bottom/priority nav; content stacks. See [08](08-mobile-guidelines.md) and [DDR-005](decisions/DDR-005-mobile-first.md).

---

## 2. Desktop layout (≥1024px)

| Region | Behaviour |
| --- | --- |
| **Header** | Full-width chrome; identity left; Create event primary; account/help right |
| **Sidebar** | Persistent vertical nav; Settings + Support visually separated |
| **Workspace** | Centred content column using layout intent |
| **Help rail** | Optional `sidebar_help` region; never steals primary action |

Scanning path: **title → primary action → status / attention → supporting content**.

---

## 3. Tablet layout (768px–1023px)

| Change | Why |
| --- | --- |
| Sidebar may collapse to icon rail or overlay | Preserve content width for tables |
| Gutters use tablet token | Touch-friendly without desktop waste |
| Metric grids drop from 4 → 2 columns | Avoid cramped KPI cards |
| Tables keep horizontal scroll rather than inventing a second UI | Consistency with desktop data model |

---

## 4. Mobile layout (<768px)

| Change | Why |
| --- | --- |
| Single column; shell nav in drawer / sheet | One job per viewport |
| Page hero compresses; no decorative empty hero padding | Attention over theatre |
| Sticky primary action when the task requires commit | Reachability for thumbs |
| Tables → card rows or scroll containers | [08-mobile-guidelines.md](08-mobile-guidelines.md) |
| Door Mode uses maximal content, minimal chrome | Stress state |

Baseline design width: **390px**, enhance upward.

---

## 5. Grid

| Rule | Detail |
| --- | --- |
| Base unit | 4px (token scale in [11](11-design-tokens.md)) |
| Content grid | 12-column conceptual grid inside the active container |
| KPI / metric cards | 2 columns mobile · 2–3 tablet · 4 desktop (max) |
| Card stacks | Vertical rhythm via spacing scale |
| Split layouts | Documented split helpers only; avoid one-off 70/30 forks |

Do not introduce a second grid system alongside existing vendor theme layout partials.

---

## 6. Spacing

Use the spacing scale in [11-design-tokens.md](11-design-tokens.md). Whitespace creates hierarchy. If two panels compete equally, reduce one — do not add more chrome. Emotional guidance: [14](14-visual-identity.md).

---

## 7. Containers (layout intents)

Shell stays full width. Content chooses an intent — **numbers in [11](11-design-tokens.md)**:

| Intent | Use |
| --- | --- |
| Form | Settings, Stripe connect, messaging brand, wizards |
| Reading | Support, help, next-action banners, status cards |
| Workspace | Event Workspace, tickets, attendees, builder, events list |
| Dashboard | Organiser home, global hubs |
| Wide / Marketing | Boost grids, placement boards |

**Rule:** Next-action and readiness blocks inside a wide workspace still prefer **Reading** width so urgency stays readable.

Never hardcode content widths in Twig — layout classes and theme tokens own the numbers ([09](09-drupal-mapping.md)).

---

## 8. Header

| Element | Role |
| --- | --- |
| MEL mark + organiser name | Answers “whose business is this?” |
| Create event | Persistent primary chrome action |
| Context label | Global vs event name when in Workspace |
| Account / notifications | Secondary; never louder than Create event |

Header is identity and wayfinding — not a second dashboard.

---

## 9. Sidebar

| Rule | Why |
| --- | --- |
| Job-based order per [02](02-information-architecture.md) | Predictable mental model |
| Active state is clear and not colour-only | Accessibility |
| Badge counts sparingly (unread, needs attention) | Avoid FOMO theatre |
| No nested mega-menus of Drupal tools | Hide complexity |

---

## 10. Workspace

The workspace is the main content region inside the shell.

| Property | Rule |
| --- | --- |
| One H1 | Page title owned by organiser task |
| Primary action placement | Top-right of page header on desktop; sticky on mobile when needed |
| Event Workspace subnav | Horizontal or secondary rail inside content — not a second global sidebar of CMS tools |
| Scroll | Prefer document scroll; freeze only intentional sticky bars |

---

## 11. Hero (Workspace Hero)

Vendor Studio heroes are **operational**, not marketing full-bleed heroes.

| Allowed | Not allowed |
| --- | --- |
| Title, one-line status, one primary CTA, optional compact metrics | Full-bleed photo theatre, floating promo stickers, stat strips that bury the next action |
| Readiness + next action composition | Multiple competing CTAs of equal weight |

Public MEL hero locks in `DESIGN_SYSTEM.md` do **not** automatically apply decorative rules here; do not copy public homepage hero patterns into ops chrome. See [19](19-anti-patterns.md) Dashboard wallpaper.

---

## 12. Panels

Panels group related work. Prefer:

1. **Action / attention panel** (strongest)
2. **Status / KPI strip** (secondary)
3. **Data board** (tables, lists)
4. **Help / guidance** (quiet)

Panels use elevation sparingly ([11](11-design-tokens.md)). Adjacent panels separate primarily by whitespace and a single border token — not stacked heavy shadows.

---

## 13. Responsive rules (summary)

| Breakpoint | Nav | Content | Tables | Actions |
| --- | --- | --- | --- | --- |
| Mobile | Drawer / priority | 1 col, form/reading widths | Cards or x-scroll | Sticky primary |
| Tablet | Compact rail | 2-col metrics | x-scroll OK | Header actions |
| Desktop | Persistent sidebar | Intent containers | Full tables | Header actions |

`prefers-reduced-motion`: no layout-dependent animation required for comprehension.

---

## Design implications

- New pages declare a layout intent in PR description
- Competing max-width tokens are regressions ([19](19-anti-patterns.md), [DDR-003](decisions/DDR-003-layout-intents.md))

## Future considerations

- Do not reintroduce competing max-width tokens (`1080`, hardcoded `1120`, dual `.mel-page` contracts). VX2-02A remains the layout authority to extend in code.
- Ultra-wide monitors: content stays centred at intent max; do not stretch forms to fill the void.
- Density themes parked ([A03](appendices/A03-future-ideas-parking-lot.md))

## Related references

- [11](11-design-tokens.md) · [02](02-information-architecture.md) · [08](08-mobile-guidelines.md) · [09](09-drupal-mapping.md) · [DDR-003](decisions/DDR-003-layout-intents.md) · [DDR-005](decisions/DDR-005-mobile-first.md)
