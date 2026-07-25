# Vendor Workspace v2 — Workspace State Model

**Status:** Discovery / design model (no implementation)  
**Date:** 2026-07-25  
**Principle:** The shell remains consistent. Only emphasis changes. (DDR-002 · 13)

---

## Shell invariants (all states)

| Element | Always present |
| --- | --- |
| Global organiser shell | Sidebar + header (DDR-001) |
| Event chrome | Event name · status · one primary CTA slot · secondary view/share |
| Section nav | Same section set / order (pending DDR on Orders vs Attendees) |
| Help | Context help available, not blocking ops |

**Do not** swap to a second product, second sidebar taxonomy, or CMS node-edit chrome by state.

---

## State matrix

### Draft

| Slot | Emphasis |
| --- | --- |
| **Hero** | Event name + Draft badge; subtitle = next incomplete step |
| **Status** | Draft |
| **Readiness** | Dominant — checklist open or summary with issue count |
| **Operational focus** | Setup |
| **Metrics** | Quiet / empty-state calm |
| **Primary actions** | Continue setup |
| **Secondary actions** | Preview (if meaningful), Save status |
| **Help** | “What makes an event ready?” |
| **Warnings** | Blockers with fix links |
| **Success** | Soft progress (“3 of 8 complete”) — not fireworks |

### Ready

| Slot | Emphasis |
| --- | --- |
| **Hero** | Ready to publish |
| **Status** | Ready (unpublished) |
| **Readiness** | Green + Stripe attention if needed |
| **Operational focus** | Publish confidence |
| **Metrics** | Still quiet |
| **Primary actions** | Go to Publishing / Publish |
| **Secondary actions** | Preview public page |
| **Help** | What guests will see |
| **Warnings** | Stripe / visibility consequences |
| **Success** | Calm “ready when you are” |

### Published

| Slot | Emphasis |
| --- | --- |
| **Hero** | Published confirmation |
| **Status** | Published |
| **Readiness** | Collapsed unless regressions |
| **Operational focus** | First share |
| **Metrics** | Zero sales OK — empty success |
| **Primary actions** | Share |
| **Secondary actions** | View public page, Marketing |
| **Help** | Sharing tips |
| **Warnings** | None unless capability regresses |
| **Success** | Quiet published confirmation |

### Selling

| Slot | Emphasis |
| --- | --- |
| **Hero** | Sales pulse headline |
| **Status** | Published · On sale |
| **Readiness** | Background |
| **Operational focus** | Revenue + inventory |
| **Metrics** | Sales, tickets sold, conversion cues |
| **Primary actions** | View orders / Manage tickets (context) |
| **Secondary actions** | Boost, Messages |
| **Help** | Pricing / capacity tips (non-blocking) |
| **Warnings** | Low capacity, Stripe issues |
| **Success** | Milestone sales (quiet) |

### Upcoming

| Slot | Emphasis |
| --- | --- |
| **Hero** | Countdown / date clarity |
| **Status** | Published · Upcoming |
| **Readiness** | Guest-ops readiness (questions, door) |
| **Operational focus** | Attendees + Messages |
| **Metrics** | Attendee / RSVP counts |
| **Primary actions** | Review attendees |
| **Secondary actions** | Message guests, open Door Mode preview |
| **Help** | Door prep checklist |
| **Warnings** | Incomplete guest data |
| **Success** | Prep complete |

### Live

| Slot | Emphasis |
| --- | --- |
| **Hero** | Unmistakable Live / Door status |
| **Status** | Live |
| **Readiness** | Hidden unless blocking check-in |
| **Operational focus** | Door Mode + exceptions |
| **Metrics** | Checked-in / remaining |
| **Primary actions** | Door Mode |
| **Secondary actions** | Orders exceptions, Messages |
| **Help** | Minimal chrome |
| **Warnings** | Scan failures, offline (v2) |
| **Success** | Check-in confirmations — brief |

### Completed

| Slot | Emphasis |
| --- | --- |
| **Hero** | Completed / thank-you tone |
| **Status** | Completed / Archived |
| **Readiness** | N/A or historical |
| **Operational focus** | Aftermath money + learning |
| **Metrics** | Final sales, attendance |
| **Primary actions** | Review orders / Analytics |
| **Secondary actions** | Refunds via deliberate path, Archive |
| **Help** | Payouts → Payments hub |
| **Warnings** | Open refunds |
| **Success** | Calm closure — no growth pressure |

---

## Mapping to runtime today

| State model slot | Runtime seed |
| --- | --- |
| Hero / status | Studio topbar + `event.status*` on VM |
| Readiness | Facade + Home Event Ready |
| Operational focus | Partially via next_action + nav order — **not** full state machine |
| Metrics | Home cards / sales snapshot |
| Primary actions | `resolveNextRecommendedAction()` |
| Door Live mode | Separate Door Mode route (shell split) |

**Gap:** Emphasis changes are mostly **content-level** (next_action + cards), not a formal Workspace state presentation layer. Future slices should extend builders/presentation — not invent a second shell.
