# Vendor Studio — Event Workspace Philosophy

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Become the definitive specification for **Event Workspace** — the per-event application where organisers build and run a single event.

## Scope

Philosophy, shell, sections, readiness, publishing, autosave, live ops, Door Mode, and lifecycle. Global IA remains in [02](02-information-architecture.md). Component contracts remain in [05](05-component-library.md).

## Audience

Product, design, Event Workspace implementers, Commerce-aware reviewers, accessibility lead.

## Related documents

- [02-information-architecture.md](02-information-architecture.md)
- [06-workspace-patterns.md](06-workspace-patterns.md)
- [07-interaction-guidelines.md](07-interaction-guidelines.md)
- [08-mobile-guidelines.md](08-mobile-guidelines.md)
- [DDR-002](decisions/DDR-002-event-workspace.md)
- [09-drupal-mapping.md](09-drupal-mapping.md)
- VX2 screen specs for field inventories (composition only here)

---

## Workspace philosophy

Event Workspace is **one application per event** — builder and operations together.

```text
Global Studio  ←→  Event Workspace (this event)
```

There is no Studio vs Manager split. Organisers should never learn MEL’s internal history to edit tickets or check attendees.

**Why:** Dual products recreate cognitive tax, duplicate navigation, and broken “where am I?” answers.

---

## Workspace shell

```text
┌──────────────────────────────────────────────────────────┐
│ Global header (organiser · Create event · account)       │
├──────────────┬───────────────────────────────────────────┤
│ Global nav   │ Event chrome: name · status · primary CTA │
│ (dimmed /    ├───────────────────────────────────────────┤
│ context)     │ Section nav (Overview → … → Settings)     │
│              ├───────────────────────────────────────────┤
│              │ Section body (layout intent: Workspace;   │
│              │ readiness/next-action prefer Reading)     │
└──────────────┴───────────────────────────────────────────┘
```

| Shell rule | Why |
| --- | --- |
| Event name + status always visible | Answers “Where am I?” |
| Section nav inside content, not a second CMS sidebar | Hide platform complexity |
| One primary CTA in event chrome (context-sensitive) | One primary action |
| View public page / share as secondary | Growth without displacing ops |

---

## Context switching

| Switch | Meaning |
| --- | --- |
| Global → Event | Enter from Events list, Dashboard shortcut, or deep link |
| Event → Global | Back to Events / Dashboard via shell |
| Section → Section | Same event; preserve event chrome |

Never require re-selecting the event for every section. Never open a parallel “manager” product for ops.

---

## Sections

Canonical order (IA-aligned):

```text
Overview → Details → Schedule → Venue → Images → Tickets
→ Orders → Attendees → Messages → Marketing → Analytics
→ Publishing → Settings
```

### Overview

Mission: readiness + next action + live pulse. Compositional Home — not a second Dashboard of the whole business.

### Details

Core event copy and facts organisers expect to edit. Progressive disclosure for advanced fields.

### Schedule

Dates/times clarity; timezone honesty; multi-session only when the product supports it without CMS jargon.

### Venue

Place and accessibility essentials; reuse organiser venues from Settings when helpful.

### Images

Media selection in organiser language (“Event image”), not media browser CMS vocabulary.

### Tickets

Sellable options. Advanced inventory tools nest here. Commerce products/variations stay backstage.

### Orders

Event-filtered sales. Deliberate refund entry. State matches Commerce truth.

### Attendees

Guest operations. **Door Mode** lives here as a mode — not a global product.

### Messages

Event-scoped send with clear audience. Brand/templates may link to global Messages hub.

### Marketing

Boost, share, embed for **this** event. Spend/state clear before purchase.

### Analytics

This event’s performance; text alternatives for critical figures.

### Publishing

Readiness, visibility, blockers with reasons and recovery. Honest publish.

### Settings

Event-level preferences (not global organiser Settings). Dangerous toggles confirmed.

---

## Readiness model

| Principle | Detail |
| --- | --- |
| Honest | Green only when capability is real |
| Actionable | Each blocker names fix + link |
| Separated | Distinct from field validation |
| Visible | Overview + Publishing surfaces |

Fake readiness destroys trust ([01](01-vendor-studio-vision.md)).

---

## Publishing philosophy

- Publish is deliberate, not accidental
- Blockers explain **why** and **how to fix**
- Unpublish / visibility changes state consequences clearly
- UI never invents “live” when access or ticket capability is false
- High risk: confirm against repository publish matrix before changing rules

---

## Autosave philosophy

| Surface | Behaviour |
| --- | --- |
| Long builder sections (where established) | Autosave + Saving / Saved / Error |
| Money, refunds, Stripe, publish | **Explicit confirm** — no silent commit |
| Failure | Visible error + retry; never claim Saved |

Detail: [07-interaction-guidelines.md](07-interaction-guidelines.md).

---

## Live event philosophy

When an event is live or door-imminent:

- Status is unmistakable
- Attendees / Orders / Door Mode rise in practical priority
- Avoid burying live ops under builder sections
- Celebrations stay quiet; ops stay calm

---

## Door Mode philosophy

Door Mode is **guest ops under stress**.

| Rule | Why |
| --- | --- |
| Maximise list/search; minimise chrome | Speed |
| Large targets; one-handed primary | Mobile stress |
| Failure/offline messaging unmistakable | Trust |
| Prefer controls over shortcut memorisation | Accessibility under pressure |
| Remains under Attendees | IA integrity ([02](02-information-architecture.md)) |

Offline-first enhancements are v2 ([20](20-vendor-studio-v2-vision.md)).

---

## Event lifecycle

```text
Draft → Ready → Published / Live → Door → Aftermath → Archive
```

| Stage | Design emphasis |
| --- | --- |
| Draft | Next step + readiness |
| Ready | Publish confidence |
| Live | Sales + guest pulse |
| Door | Door Mode excellence |
| Aftermath | Orders, refunds, analytics clarity |
| Archive | Read-only calm; clear exit |

---

## Success criteria

1. No Studio/Manager dual nav  
2. Overview always shows next step  
3. Publish blockers explained  
4. Autosave never silently mutates money  
5. Door Mode usable on phone under time pressure  
6. Organiser language throughout  

Metrics: [18](18-product-success-metrics.md).

---

## Future evolution

- Predictive readiness and AI assistant: [20](20-vendor-studio-v2-vision.md)
- Multi-event operations: parking lot [A03](appendices/A03-future-ideas-parking-lot.md)
- Section order may gain research-driven compaction on mobile; default remains full clarity

---

## Design implications

- New event tools nest under the correct section; they do not earn a global nav item by default
- Implementation must preserve server-side ownership/access — UI is not security
- Field inventories stay in VX2 screen specs; this file owns philosophy

## Future considerations

- Collaborative editing presence
- Stronger offline Door Mode
- Cross-event bulk ops (explicitly not v1)

## Related references

- [02](02-information-architecture.md) · [06](06-workspace-patterns.md) · [07](07-interaction-guidelines.md) · [08](08-mobile-guidelines.md) · [09](09-drupal-mapping.md) · [DDR-002](decisions/DDR-002-event-workspace.md) · [10](10-roadmap.md) Phase 4
