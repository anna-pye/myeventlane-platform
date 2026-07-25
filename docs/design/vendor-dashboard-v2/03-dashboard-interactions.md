# Vendor Dashboard v2 — Interactions (Sprint 1A)

**Status:** Design package only — no implementation  
**Authority:** [07-interaction-guidelines.md](../vendor-studio/07-interaction-guidelines.md) · [12-dashboard-philosophy.md](../vendor-studio/12-dashboard-philosophy.md) · [05-component-library.md](../vendor-studio/05-component-library.md)  
**Motion tokens:** [11-design-tokens.md](../vendor-studio/11-design-tokens.md)

---

## Principles

- Certainty over cleverness ([07](../vendor-studio/07-interaction-guidelines.md)).  
- Do not stack toast theatre over the Action Queue ([12](../vendor-studio/12-dashboard-philosophy.md)).  
- Hover never reveals essential information alone.  
- Respect `prefers-reduced-motion`.  
- Money / Stripe / publish: no silent autosave commits on Dashboard (Dashboard is mostly navigation + status — not a long form surface).

---

## 1. Loading

| Pattern | Behaviour |
| --- | --- |
| Initial paint | Skeleton mirrors final hierarchy: Hero → Queue block → Focus → Upcoming → Metric row |
| Container | `aria-busy="true"` on main Dashboard content until primary payload ready |
| Metrics | Skeleton placeholders — **never** plausible fake revenue ([12](../vendor-studio/12-dashboard-philosophy.md), [19](../vendor-studio/19-anti-patterns.md)) |
| Queue | Skeleton rows (2–3), not empty void then pop-in without structure |
| Partial refresh | Inline spinner on the refreshing region only |
| Full-page blocker | Not used for Dashboard browse |

---

## 2. Empty states

| State | Interaction / content |
| --- | --- |
| No events yet | Empty state component: welcome + Create event + short why; Support quiet secondary |
| Queue empty (caught up) | Calm “You’re caught up” status region still present (landmark); focus moves naturally to Today’s focus / Upcoming |
| No upcoming | Short line + link to Events / Create — not a blank hole |
| No activity | Muted helper: activity appears after bookings/RSVPs/updates — already close to current copy |
| Stripe missing | Action Card (not empty void): reason + connect CTA |

Empty without a next step is forbidden ([19](../vendor-studio/19-anti-patterns.md)).

---

## 3. Success

| Trigger | Behaviour |
| --- | --- |
| Returned from completing a queue item | Optional polite success toast (auto-dismiss); queue re-ranks on next load/refresh |
| Milestone earned | Success panel (celebration rank) — brief, dismissible, next step included ([05](../vendor-studio/05-component-library.md) Success panels) |
| Pro welcome (existing one-shot) | Treat as celebration-rank: polite `role="status"`; must not displace queue on subsequent visits |

Do not use confetti / FOMO celebration patterns ([07](../vendor-studio/07-interaction-guidelines.md)).

---

## 4. Error

| Type | Behaviour |
| --- | --- |
| Page/system error | Alert with recovery; persistent until addressed or dismissed |
| Failed queue action navigation | Destination owns the error; Dashboard may keep the Action Card until backend clears it |
| Metric unavailable | Honest “Unavailable” / omit delta — never invent growth |
| Access denied | Existing console access behaviour — do not soften with UI hiding |

Errors are not auto-dismissed ([07](../vendor-studio/07-interaction-guidelines.md)).

---

## 5. Hover (pointer)

| Element | Allowed |
| --- | --- |
| Action Card / queue row | Subtle background; underline CTA |
| Upcoming row / Open link | Affordance lift within motion-fast (~120ms) |
| Metric Card | Hover only if drill-down exists; otherwise no fake clickability |
| Disabled | Default cursor; no hover affordance |

Essential title, reason, and CTA remain visible without hover.

---

## 6. Focus (keyboard)

| Rule | Detail |
| --- | --- |
| Visible focus ring | Vendor focus token ([07](../vendor-studio/07-interaction-guidelines.md)) |
| Order | Skip link → shell → Create event → H1 region → first Action Card CTA → Today’s focus primary → Upcoming Opens → KPI links (if any) → activity |
| Queue | Each row CTA is a real link/button with clear accessible name (include event/task context) |
| Dialogs (e.g. existing remove/archive) | Focus trap; Esc; restore focus — reuse existing dialog contract |
| Never | Remove outlines for aesthetics |

---

## 7. Keyboard

| Capability | Expectation |
| --- | --- |
| Tab / Shift+Tab | Full primary path operable |
| Enter / Space | Activate focused CTA |
| Esc | Close dialogs/drawers only |
| Shortcuts | None required for Sprint 1A Dashboard (avoid teaching a second OS) |

---

## 8. Notifications

| Type | Dashboard rule |
| --- | --- |
| Success | Polite, dismissible, short delay |
| Error / warning | Persistent; prefer inline Alert near relevant block |
| Badge counts in chrome | Sparse — do not invent a second notification centre on the page |
| Stacking | Coalesce; never cover the Action Queue |

Copy: organiser language ([15](../vendor-studio/15-copywriting-guide.md)).

---

## 9. Animations

| Allowed | Disallowed |
| --- | --- |
| Short expand/collapse if any secondary panel remains | Decorative looping chrome motion |
| Soft fade for toast enter/exit ≤ motion tokens | Layout thrash, parallax |
| Skeleton shimmer reduced under `prefers-reduced-motion` | Motion required to understand state |
| Dialog enter ≤400ms (existing remove dialog) | Confetti / FOMO |

---

## 10. Autosave indicators

| Context on Dashboard | Guidance |
| --- | --- |
| Dashboard itself | **No autosave** — not a form hub |
| Navigating into Workspace forms | Workspace owns Saving / Saved / Error ([13](../vendor-studio/13-event-workspace-philosophy.md), [07](../vendor-studio/07-interaction-guidelines.md)) |
| Dismiss celebration / safe dismiss of Action Card | Explicit control; only when backend says dismiss is safe ([12](../vendor-studio/12-dashboard-philosophy.md)) |

If Sprint 1A does not implement dismiss, do not fake dismissible UI.

---

## 11. Action Queue behavioural contract

| Rule | Source |
| --- | --- |
| Severity order from backend | [12](../vendor-studio/12-dashboard-philosophy.md) — Twig does not re-sort |
| Item anatomy | severity + title + reason + one CTA |
| Max visible | Prefer existing builder max (6) unless Product changes it |
| Top item | Never behind an expander on first paint |
| Governance reorder | Presentation-only services must not invent payment success |

---

## 12. Metric Card interactions

| Rule | Detail |
| --- | --- |
| Click | Only when a real drill-down route exists (e.g. Analytics, Orders) |
| Delta | Announce meaning in text (“up/down”), not colour alone |
| Loading | Skeleton / “—” — not `0` pretending to be insight when unknown |

---

## 13. State matrix (summary)

| State | Visual | AT | Next step |
| --- | --- | --- | --- |
| Loading | Skeletons | `aria-busy` | Wait |
| Empty first-run | Empty state | Status text | Create event |
| Queue items | Action Cards | List + named CTAs | Resolve top item |
| Caught up | Calm empty queue | Status | Open focus / upcoming / create |
| Error | Alert | `role="alert"` as appropriate | Recovery CTA |
| Success toast | Transient | `role="status"` | Continue |
| Celebration | Success panel | Dismissible | Provided next step |

---

## DDR check

All interaction patterns above are covered by [07](../vendor-studio/07-interaction-guidelines.md) and [12](../vendor-studio/12-dashboard-philosophy.md). **No DDR required.**

If a future proposal adds live-updating WebSocket theatre or an AI advisory panel on Dashboard, stop — that is [20](../vendor-studio/20-vendor-studio-v2-vision.md) / parked scope, not Sprint 1A.

**Next:** [04-dashboard-mobile.md](04-dashboard-mobile.md)
