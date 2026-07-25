# Vendor Studio — Workspace Patterns

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define **page-level composition patterns** for each global hub — how components assemble to answer the Three Questions.

## Scope

Pattern goals, primary/secondary tasks, layout intent choice, component mix, success criteria. **Dashboard philosophy depth:** [12](12-dashboard-philosophy.md). **Event Workspace philosophy depth:** [13](13-event-workspace-philosophy.md). Field inventories remain in VX2 screen specifications.

## Audience

Product designers, theme implementers, feature leads.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [02-information-architecture.md](02-information-architecture.md)
- [05-component-library.md](05-component-library.md)
- [12-dashboard-philosophy.md](12-dashboard-philosophy.md)
- [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md)
- [`docs/vendor-experience-convergence-screen-specifications.md`](../../vendor-experience-convergence-screen-specifications.md)

---

## Why patterns

Hubs share jobs (find, act, confirm) even when data differs. Patterns prevent every route from inventing a new page grammar.

Each pattern must pass the Three Questions Framework and Golden Rule in [01](01-vendor-studio-vision.md).

Attendees and Payments follow the same structural rules as Orders / Messages even when not listed as separate pattern headings below — see [02](02-information-architecture.md) for scope.

---

## 1. Dashboard

| | |
| --- | --- |
| **Goals** | Surface what needs attention; orient the organiser’s business today |
| **Primary task** | Resolve the top action-queue item |
| **Secondary tasks** | Open today’s event, scan upcoming, skim activity |
| **Layout** | Dashboard container. Identity header → Action required (strongest) → Today status → Upcoming → Activity |
| **Components** | Workspace Hero (compact), Task Lists, Metric Cards, Action Cards, Empty states |
| **Success criteria** | Action queue is visible without expanding; empty queue feels calm; Create event always reachable |

**Philosophy authority:** [12-dashboard-philosophy.md](12-dashboard-philosophy.md).

---

## 2. Events

| | |
| --- | --- |
| **Goals** | Find, create, and open events |
| **Primary task** | Open an event Workspace or create a new event |
| **Secondary tasks** | Filter by status; duplicate/archive where allowed |
| **Layout** | Workspace container. Page header + filters + list/table |
| **Components** | Buttons, Badges, Data Tables or event rows, Empty states |
| **Success criteria** | ≤2 clicks from list to Event Workspace overview; draft vs live unmistakable |

---

## 3. Workspace (Event Workspace)

| | |
| --- | --- |
| **Goals** | Build and operate one event in a single application shell |
| **Primary task** | Context-dependent: continue setup, publish, or run ops (tickets/attendees/orders) |
| **Secondary tasks** | Section navigation; view public page; Boost entry |
| **Layout** | Workspace container. Event chrome (name, status, section nav) → section body. Overview uses compositional Home: readiness + next action + KPIs + activity |
| **Components** | Workspace Hero, Progress/readiness, Metric Cards, Forms, Tables, Alerts, Help panels |
| **Success criteria** | No Studio/Manager dual nav; next step always visible on Overview; publish blockers explained |

**Philosophy authority:** [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md).

---

## 4. Orders

| | |
| --- | --- |
| **Goals** | Understand and act on sales records |
| **Primary task** | Find an order and view detail / refund entry |
| **Secondary tasks** | Filter by event/status; export where permitted |
| **Layout** | Workspace/Dashboard hub width for lists; Reading/Form for detail panes |
| **Components** | Data Tables, Badges, Drawers/Dialogs for confirmations, Alerts |
| **Success criteria** | Order state matches Commerce truth; refund path is deliberate and confirmed |

**Risk note:** Order ownership and payment state are high risk — UI never invents success.

---

## 5. Messages

| | |
| --- | --- |
| **Goals** | Communicate with audiences using brand-consistent tools |
| **Primary task** | Send or schedule a message to the right audience |
| **Secondary tasks** | Edit brand/templates; review history |
| **Layout** | Form width for compose/brand; Workspace for history tables |
| **Components** | Forms, Inputs, Alerts, Success panels, Empty states |
| **Success criteria** | Organiser can tell audience + event context before send; failures explain recovery |

---

## 6. Analytics

| | |
| --- | --- |
| **Goals** | Answer business questions with trustworthy numbers |
| **Primary task** | Understand performance for a period or event |
| **Secondary tasks** | Export; drill into event Workspace analytics |
| **Layout** | Dashboard/Workspace width; charts + KPI row + supporting table |
| **Components** | Metric Cards, Charts, Data Tables, Skeleton loading, Empty states |
| **Success criteria** | Critical figures available as text; empty states do not invent growth stories |

---

## 7. Marketing

| | |
| --- | --- |
| **Goals** | Grow reach (Boost, share, embeds) without cluttering ops nav elsewhere |
| **Primary task** | Start or manage a Boost / share the event |
| **Secondary tasks** | Placement performance; widgets |
| **Layout** | Wide/Marketing container for grids; Form for purchase/configure steps |
| **Components** | Action Cards, Metric Cards, Panels, Buttons, Help panels |
| **Success criteria** | Growth actions never block publish/check-in paths; pricing/state clear before spend |

---

## 8. Settings

| | |
| --- | --- |
| **Goals** | Configure organiser defaults safely |
| **Primary task** | Update profile, venues, branding defaults, preferences |
| **Secondary tasks** | Team access where available; commerce-linked fields with guidance |
| **Layout** | Form container. Sectioned forms; clear save feedback |
| **Components** | Forms, Inputs, Alerts, Notifications, Help panels |
| **Success criteria** | Destructive settings confirmed; Stripe/business fields explain payout impact |

---

## 9. Support

| | |
| --- | --- |
| **Goals** | Resolve blockers with organiser-safe help |
| **Primary task** | Find an answer or escalate |
| **Secondary tasks** | Browse Help Centre; contact paths |
| **Layout** | Reading container. Search + article list + contact |
| **Components** | Help panels, Alerts, Empty states, Forms (contact) |
| **Success criteria** | Staff-only content never appears; articles match organiser audience |

---

## Pattern checklist (all pages)

1. Where am I?
2. What needs me?
3. What is the next useful action?
4. Empty / error / success states defined
5. Layout intent class chosen (no hardcoded widths)
6. One primary CTA
7. Anti-patterns checked ([19](19-anti-patterns.md))

---

## Design implications

- New hubs reuse an existing pattern before inventing a twelfth grammar
- Dashboard/Workspace PRs must also satisfy [12](12-dashboard-philosophy.md) / [13](13-event-workspace-philosophy.md)

## Future considerations

- Global Attendees and Payments hubs use Orders/Messages structural patterns (list → detail → deliberate action)
- Screen-level field inventories remain in VX2 screen specifications
- Customisable widgets parked ([A03](appendices/A03-future-ideas-parking-lot.md))

## Related references

- [01](01-vendor-studio-vision.md) · [05](05-component-library.md) · [12](12-dashboard-philosophy.md) · [13](13-event-workspace-philosophy.md) · [10](10-roadmap.md) · [16](16-design-review-checklist.md)
