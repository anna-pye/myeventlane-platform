# Vendor Studio — Dashboard Philosophy

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define what the **Dashboard** exists to accomplish — and what it must never become.

## Scope

Philosophy and composition for the global organiser home (`/vendor/dashboard`). Page-level pattern summary remains in [06](06-workspace-patterns.md); this document owns mission, ranking, and experience variants.

## Audience

Product, design, dashboard implementers, accessibility reviewers.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md) — Golden Rule, Three Questions
- [02-information-architecture.md](02-information-architecture.md) — why Dashboard is first
- [06-workspace-patterns.md](06-workspace-patterns.md) — compositional pattern
- [18-product-success-metrics.md](18-product-success-metrics.md)
- [19-anti-patterns.md](19-anti-patterns.md)
- [05-component-library.md](05-component-library.md)

---

## Mission

The Dashboard answers one job:

> **What needs me now so I can run my events successfully today?**

It is the attention home — not a marketing homepage, not a wallpaper of charts, and not a second Event Workspace.

---

## Dashboard hierarchy

```text
1. Identity + Create event (chrome)
2. Action Queue          ← strongest
3. Today’s focus
4. Upcoming events
5. Business health (few KPIs)
6. Recent activity
7. Celebrations / milestones (quiet)
8. Help / guidance (quietest)
```

If space is scarce, preserve **1 → 3** before metrics theatre.

---

## Information ranking

| Rank | Content | Why |
| --- | --- | --- |
| P0 | Blocking actions (publish blockers, Stripe connect, failed sends) | Golden Rule |
| P1 | Time-sensitive ops (door today, live event issues) | Stress states |
| P2 | Near-term events | Planning |
| P3 | Business health KPIs (≤4) | Decision support |
| P4 | Activity feed | Context |
| P5 | Celebrations | Motivation without FOMO |
| — | Decorative hero, vanity charts, duplicate nav | Excluded |

---

## Action Queue philosophy

- Backend severity ordering — Twig does not re-sort
- Each item: **severity + title + reason + one CTA**
- Dismiss only when safe
- Empty queue = calm “You’re caught up” — not a void
- Never hide the top item behind expanders on first paint

**Why:** Prior dashboards failed when vanity content outranked the queue.

---

## Metrics philosophy

- Metrics support decisions; they do not decorate
- Maximum **four** KPIs in the health strip
- Each KPI: label, value, optional meaningful delta
- Click only when a real drill-down exists
- No fabricated numbers while loading ([11](11-design-tokens.md) loading tokens)

Decorative metrics are an anti-pattern ([19](19-anti-patterns.md)).

---

## Today’s focus

Surface the event or task that matters **today** (live event, door soon, publish today). One clear entry into Event Workspace. Avoid multi-event boards that compete with the Action Queue.

---

## Upcoming events

Short list of next events with status badges and open-workspace actions. This is a launcher, not a full Events catalogue (that is Events hub).

---

## Business health

A sober pulse: e.g. sales period total, tickets sold, upcoming count, attention count. Exact KPI set is product-configurable; the **philosophy** is few, honest, and linked.

---

## Recent activity

Chronological, scannable, low chrome. Prefer “Order received”, “Published”, “Refund completed” — organiser language. Not a Drupal watchdog.

---

## Celebrations

Milestones feel earned (first publish, first paid order, successful door). Brief, dismissible, never gamified pressure or FOMO badges.

---

## Notifications

- Success: polite, dismissible
- Errors: persistent until addressed
- Do not stack toast theatre over the Action Queue
- Badge counts in chrome stay sparse

Interaction detail: [07](07-interaction-guidelines.md).

---

## Loading

Skeleton mirrors final layout. `aria-busy` on the container. Never show plausible fake revenue.

---

## Empty states

| State | Response |
| --- | --- |
| No events yet | Welcome + Create event + short why |
| Events but nothing needs attention | Calm caught-up + upcoming list |
| Missing Stripe / payouts | Action Card with reason + connect path |

Empty dashboards without a next step are forbidden ([19](19-anti-patterns.md)).

---

## First organiser experience

- Short path to first draft and first publish
- Action Queue teaches the system by listing real next steps
- Help is contextual; no staff diagnostics
- Metrics may be empty — honesty over vanity zeros dressed as insight

---

## Power organiser experience

- Action Queue still wins over dense widgets
- Cross-event Orders/Attendees reachable from nav, not duplicated as Dashboard chrome
- Density may increase in tables elsewhere; Dashboard stays attention-led
- No “customisable widget soup” in v1

---

## Accessibility

- One H1
- Queue items are real links/buttons with clear names
- Severity not colour-only
- Keyboard reaches Create event and top queue item without trap
- WCAG AA

---

## Mobile dashboard

- Baseline 390px
- Action Queue first; metrics collapse to 2-column
- Sticky Create event / primary only if it doesn’t obscure errors
- Full rules: [08](08-mobile-guidelines.md)

---

## Future AI assistant panel

Out of current roadmap. If introduced later:

- Advisory only; never silent money/publish actions
- Must not displace Action Queue as P0
- Spec belongs in [20](20-vendor-studio-v2-vision.md) until promoted

---

## Success criteria

Dashboard succeeds when:

1. Organisers identify the top needed action within five seconds  
2. Empty and full states both answer “what now?”  
3. Metrics never outrank blockers  
4. First and power organisers share one model  
5. Mobile remains operable for today’s event  

Metric definitions: [18-product-success-metrics.md](18-product-success-metrics.md).

---

## Design implications

- Implementation phases must not “pretty up” the Dashboard by weakening Action Queue priority
- New widgets require a job story and a rank slot — or they do not ship
- Pattern checklist in [06](06-workspace-patterns.md) still applies

## Future considerations

- Optional AI panel (v2)
- Research-gated nav compaction on small screens ([02](02-information-architecture.md))
- Personalisation of KPI set without layout forks

## Related references

- [01](01-vendor-studio-vision.md) · [02](02-information-architecture.md) · [06](06-workspace-patterns.md) · [10](10-roadmap.md) Phase 2 · [18](18-product-success-metrics.md) · [19](19-anti-patterns.md)
