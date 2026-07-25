# Vendor Workspace v2 — Mission Control Model

**Status:** Discovery / philosophy (no implementation)  
**Date:** 2026-07-25  
**Role:** Product philosophy for Event Workspace Home and chrome  
**Authority chain:** ADR-0001 → 01 → 02 → DDR-002 → 13 → this model (composition detail)

---

## How should organisers feel when opening an event?

**Calm confidence.**  
They should feel: “I know where I am, I know what matters for *this* event, and I know the next useful move — without learning MEL’s internal history.”

Not: a CMS, a second Dashboard of the whole business, or a marketing landing page.

---

## Mission Control definition

Event Workspace **Home** is Mission Control for **one event**.

| It is | It is not |
| --- | --- |
| Readiness + next action + live pulse | Global action queue (that is Dashboard) |
| Builder + operations in one app | Studio vs Manager fork |
| Status-honest | Decorative green |
| Lifecycle-aware emphasis | A different shell per lifecycle |

Dashboard Foundation (merged) owns portfolio attention. Workspace owns event attention. Cross-link; do not duplicate.

---

## Composition blocks

### 1. Workspace Hero

- Event name, date clarity, status badge, primary CTA slot, secondary view public page / share
- One primary action (01 Principle 1)
- Component contract: PDS 05 Workspace Hero

### 2. Readiness

- Honest score/checklist with reasons and recovery links
- Visible on Home; detailed on Publishing
- Never green without capability (13)

### 3. Publishing

- Deliberate publish/unpublish
- Blockers explained
- Explicit confirm — not autosave (07, 13)

### 4. Today’s Focus

- Single narrative of “what matters now” for this event
- Runtime seed: `todays_focus` on VM + Studio `next_action` (prefer one presented story on Home)

### 5. Operational status

- Tickets capability, capacity signals, door readiness near live
- Presentation alerts for recoverable issues

### 6. Business health (event-scoped)

- Sales snapshot, orders pulse, refund attention when present
- Money truth from Commerce — UI never invents paid

### 7. Communications

- Event-scoped Messages entry; brand/templates link out to global Messages hub
- Audience clarity before send

### 8. Attendees

- Guest list ops; **Door Mode** nests here (DDR-002)
- Counts + exceptions on Home near live

### 9. Tickets

- Sellable options in organiser language
- Advanced inventory nested — Commerce backstage

### 10. Orders

- Event-filtered sales; deliberate refund entry
- Readonly Studio section today — preserve caution

### 11. Marketing

- Share, embed, Boost for **this** event
- Spend/state clear before purchase (DDR-007 separation from Analytics)

### 12. Analytics

- This event’s performance; text alternatives for critical figures
- Not a replacement for global Analytics hub

### 13. Quick actions

- Grouped shortcuts (runtime `action_grid`: sales / setup / growth)
- Never outrank the single primary CTA

### 14. Workspace navigation

- Stable section list; lifecycle changes **emphasis**, not membership
- Resolve Orders↔Attendees order via DDR before wireframes freeze

### 15. Progress

- Event Ready complete_count / total; autosave Saving/Saved/Error
- Celebrate quietly (01 Principle 6)

### 16. Success criteria (Mission Control)

1. Organiser answers Three Questions within one viewport on Home  
2. No dual-product navigation  
3. Publish blockers always explain recovery  
4. Live/Door stress path reachable in ≤2 taps from Home when Live  
5. Dashboard and Workspace do not show competing “what needs me” systems for the same item without clear scope  

---

## Emotional design notes (14 / 15)

- Warm, capable, local, calm, honest
- Severity not colour-only
- Australian English
- Avoid FOMO, VIP, exclusive pressure on Boost/share

---

## Runtime seeds to extend (not replace)

| Philosophy block | Runtime seed |
| --- | --- |
| Hero | Studio topbar + VM `event` |
| Readiness | Facade + `#event_ready` / `#readiness` |
| Next / Today’s focus | `resolveNextRecommendedAction` + VM `todays_focus` / `next_action` |
| Ops cards | tickets, attendees, sales, marketing, boost, analytics, activity |
| Publish | `workspace_publishing` + publish POST |
| Door | `vendor_operations_door` (shell continuity debt) |

Wireframes (next sprint, pending human approval) should compose these seeds against the state model — not invent parallel builders.
