# DDR-002 — One Event Workspace (contextual application)

**Status:** Accepted  
**Date:** 2026-07-25  
**Version:** RC1  
**Owners:** Design Authority · Product Owner · Technical Authority

---

## Decision

Per-event work happens in a **single Event Workspace** entered from Events (or Dashboard shortcuts). Workspace is **not** a permanent global sidebar twin of Events. Builder and operations share one sectioned application.

---

## Problem

Splitting “build” and “manage” into separate products forced organisers to reorient, duplicated navigation, and hid the next step behind product history rather than event lifecycle.

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Permanent “Workspace” global nav item | Empty context; requires constant event picking; recreates dual product |
| Separate Builder and Manager apps | Fails IA philosophy; doubles maintenance |
| Drupal node edit as primary UX | CMS terminology and admin patterns |
| Global Check-in as peer product | Inflates shell; Door Mode is event guest ops |

---

## Reason

- Organiser experience: one event application  
- Clarity: Global ↔ Event is the only context switch  
- Commerce-aware: tickets/orders/attendees stay event-scoped when operating an event  
- Long-term maintainability: one shell to evolve  

Authoritative detail: [13-event-workspace-philosophy.md](../13-event-workspace-philosophy.md) · [02](../02-information-architecture.md).

---

## Consequences

- Door Mode lives under Attendees  
- Advanced tools nest under sections (e.g. Tickets)  
- Overview owns readiness + next action  
- Canonical paths: `/vendor/events/{id}` and `/vendor/events/{id}/{section}`  

---

## Future review triggers

- Multi-event bulk operations productisation ([20](../20-vendor-studio-v2-vision.md))  
- Evidence that section count requires a new IA pattern on mobile  
- Publish/autosave architecture changes that alter section boundaries  
