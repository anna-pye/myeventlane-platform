# Vendor Workspace v2 — Operational State Model

**Status:** Architecture / product design (Sprint 1B) — documentation only  
**Date:** 2026-07-25  
**Principle:** The shell remains constant. Only emphasis changes.  
**Extends:** `03-workspace-state-model.md` · `08-event-lifecycle-model.md`  

States covered:

```text
No Event · Draft · Ready · Published · Upcoming · Live · Completed
```

**Note on Selling:** Selling is a **sub-emphasis of Published** (active sales signals). It does not add/remove panels; it raises Orders/Tickets/Marketing prominence. See matrices below.

---

## Shell constants (all states)

Always present:

- Global organiser shell (DDR-001)  
- Event chrome (when an event context exists)  
- Section nav membership (DDR-009 proposed order)  
- Help entry  
- One primary CTA slot in chrome (content varies)

Never by state:

- Second product shell  
- CMS node-edit takeover  
- Removing Orders/Attendees from membership (only mute emphasis)

---

## No Event

*Context: Events catalogue empty, or user lands with no selectable event — Workspace not entered.*

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Events empty state · Create event · optional Dashboard “get started” |
| **Hidden panels** | All Workspace sections, Door Mode, event metrics |
| **CTA priority** | **Create event** |
| **Messages** | Warm, capable: “Create your first event — we’ll guide the rest” |
| **Metrics** | None |
| **Warnings** | None (unless account/Stripe setup blocks creation — rare; be honest) |
| **Suggested actions** | Create event · Browse help “Hosting on MEL” |

Workspace shell is **not** shown empty with dead section nav.

---

## Draft

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (setup) · Health (draft progress) · Readiness · Setup sections · muted ops |
| **Hidden / muted** | Sales charts, Boost pressure, Door Mode stress UI |
| **CTA priority** | Continue setup |
| **Messages** | Next blocker in plain language |
| **Metrics** | Readiness complete_count / total |
| **Warnings** | Blockers with fix links |
| **Suggested actions** | Fix first blocker · Preview if meaningful · Save confidence |

---

## Ready

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (publish confidence) · Health green + Stripe · Publishing · Preview |
| **Hidden / muted** | Door Mode, dense sales analytics |
| **CTA priority** | Publish / Go to Publishing |
| **Messages** | “Ready when you are” · visibility consequences |
| **Metrics** | Readiness complete; sales still quiet |
| **Warnings** | Stripe / paid-path gates |
| **Suggested actions** | Publish · Preview public page |

---

## Published

*Includes early Published (pre-sales) and **Selling** emphasis.*

### Published (pre-sales / first share)

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (Share) · Health Published · Marketing · calm zero sales |
| **Hidden / muted** | Setup density, Door Mode |
| **CTA priority** | Share |
| **Messages** | “Your event is live on MEL” |
| **Metrics** | Views if honest; sales zero-success |
| **Warnings** | Regressions only |
| **Suggested actions** | Share · Copy link · light Boost optional |

### Selling emphasis (Published + sales activity)

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (sales/inventory) · Pulse: Tickets · Orders · Attendees · Marketing |
| **Hidden / muted** | Builder sections collapsed under Setup |
| **CTA priority** | View orders / Adjust tickets / Share (attention rules) |
| **Messages** | Capacity and refund attention when present |
| **Metrics** | Sold, remaining, revenue (Commerce truth) |
| **Warnings** | Low capacity · Stripe · refund spikes |
| **Suggested actions** | Orders · Tickets · Message · Boost with spend clarity |

---

## Upcoming

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (guest prep) · Attendees · Messages · Door preview · date clarity |
| **Hidden / muted** | Heavy builder; Boost pressure reduced |
| **CTA priority** | Review attendees |
| **Messages** | Door prep checklist items |
| **Metrics** | Expected guests · RSVPs · message draft count |
| **Warnings** | Incomplete guest data / questions |
| **Suggested actions** | Attendees · Message · Preview Door Mode |

---

## Live

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (Door) · Live status · checked-in metrics · exceptions · Attendees/Door |
| **Hidden / muted** | Setup, Marketing/Boost, Analytics deep dives, Publishing (unless emergency unpublish) |
| **CTA priority** | Open Door Mode |
| **Messages** | Minimal; failure messages large and clear |
| **Metrics** | Checked-in / remaining · exceptions |
| **Warnings** | Scan/access failures |
| **Suggested actions** | Door Mode · Exceptions · Orders (edge) · Message (edge) |

---

## Completed

| Dimension | Spec |
| --- | --- |
| **Visible panels** | Focus (closure) · Orders · Analytics · Settings/archive · final metrics |
| **Hidden / muted** | Door Mode, Boost, setup checklists, “share now” pressure |
| **CTA priority** | Review orders / View analytics |
| **Messages** | Thank-you / closure; payouts → Payments hub |
| **Metrics** | Final sales · attendance |
| **Warnings** | Open refunds · payout holds |
| **Suggested actions** | Orders · Refunds (deliberate) · Analytics · Archive |

---

## Panel emphasis legend

| Label | Meaning |
| --- | --- |
| **Visible** | On Home or one tap; visually primary |
| **Muted** | Reachable via nav; de-emphasised on Home |
| **Hidden** | Not appropriate for state (e.g. Door stress UI in Draft) — capability may still exist via nav with calm entry |

---

## CTA priority algorithm (normative)

```text
1. Safety / setup errors (VM / readiness)
2. Publish blockers / Stripe gate
3. State primary (tables above)
4. Growth (Share / Boost)
```

Chrome primary **equals** Focus primary unless a transient confirm dialog is open.

---

## Mapping note for implementers

This model is a **presentation contract** for `EventStudioWorkspacePresentation` + Home builder — not a new state-machine entity. Derive from existing publish/readiness/date/sales signals; if a signal is missing in repository, stop and ask (do not invent fields).
