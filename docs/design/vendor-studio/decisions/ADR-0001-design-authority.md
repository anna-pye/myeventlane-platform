# ADR-0001 — Design Authority (Constitution)

**Status:** Accepted  
**Date:** 2026-07-25  
**Pack version:** 1.0 (FROZEN)  
**Owners:** Product Owner · Design Authority · Technical Authority

---

## Purpose

This is the **constitutional document** for the **Vendor Studio Product Design System (PDS)** (also known as the Vendor Studio Design Operating System).

It establishes why the PDS exists, who owns it, how decisions are made, and what happens when documents disagree.

All other documents in this pack are subordinate to this constitution and to the precedence hierarchy below.

---

## Why the Product Design System exists

Vendor Studio must grow for years without losing its identity.

Without a governed product standard:

- Screens are designed in isolation
- Navigation forks reappear (Studio vs Manager)
- Tokens and max-widths multiply
- Drupal/Commerce vocabulary leaks into organiser UI
- Accessibility and money honesty become optional

The PDS exists so every feature is built against a **clear product standard**, not against the last screen someone liked.

It is a long-lived product asset — comparable in intent to mature design systems (Polaris, Atlassian Design System, Material) — expressed uniquely for MyEventLane’s organiser operations.

---

## Who owns it

| Role | Owns |
| --- | --- |
| **Product Owner** | Organiser outcomes; roadmap priority; whether a change is in scope; major version bumps |
| **Design Authority** | Experience consistency (IA, visual, interaction, copy); DDR approval for design decisions; pack integrity |
| **Technical Authority** | Drupal 11 / Commerce 3 feasibility; mapping honesty in [09](../09-drupal-mapping.md); access, payments, cache, and security risk |

Roles are seats that must be filled on material reviews. Named individuals may be recorded in project ops docs without rewriting this constitution.

Lifecycle of changes: [23-governance-lifecycle.md](../23-governance-lifecycle.md).  
Contributor practice: [CONTRIBUTING.md](../CONTRIBUTING.md).

---

## How design decisions are made

1. **Idea** — captured; large ideas may park in [A03](../appendices/A03-future-ideas-parking-lot.md) or [20](../20-vendor-studio-v2-vision.md)
2. **Proposal** — documentation PR states the authoritative home being changed
3. **Design Decision Record (DDR)** — required for foundational experience decisions (see [README](../README.md) / [CONTRIBUTING](../CONTRIBUTING.md))
4. **Review** — Design Authority; Technical Authority when Drupal/Commerce contracts change; Product Owner for scope/roadmap
5. **Approval** — explicit acceptance in PR / DDR status
6. **Implementation** — cites OS docs; never invents contradicting patterns
7. **Validation** — [21-definition-of-done.md](../21-definition-of-done.md) and [16-design-review-checklist.md](../16-design-review-checklist.md)
8. **Documentation update** — mapping notes, CHANGELOG, cross-refs
9. **Release** — phase ship per [10](../10-roadmap.md); OS version bump when the standard itself changes

---

## Document precedence

When two documents disagree, **higher wins**. Lower documents must be amended to restore alignment — never the reverse by silent implementation.

```text
ADR-0001 Design Authority (this constitution)
    ↓
Mission / Vision / Design Principles          → 01, Poster
    ↓
Information Architecture                      → 02 (+ DDR-001, DDR-002, …)
    ↓
Layout System                                 → 03 (+ DDR-003)
    ↓
Design Tokens                                 → 11
    ↓
Design Language / Visual Identity / Copy      → 04, 14, 15
    ↓
Component Library                             → 05 (+ DDR-004)
    ↓
Interaction / Mobile                          → 07, 08 (+ DDR-005)
    ↓
Workspace Patterns / Hub Philosophies         → 06, 12, 13
    ↓
Anti-patterns / Maturity / Metrics / DoD      → 19, 17, 18, 21
    ↓
Implementation Mapping                        → 09
    ↓
Roadmap                                       → 10
    ↓
v2 Vision / Parking lot                       → 20, A03  (non-binding on current work)
```

### Precedence rules

1. **No implementation may contradict higher-order documents.**
2. Runtime code and VX2 historical docs do not outrank this OS on **design philosophy**. Runtime **facts** (routes, fields, states) must still be confirmed in the repository before coding.
3. Public MEL brand (`docs/brand/`, `DESIGN_SYSTEM.md`) outranks Studio for **public discovery** surfaces; Studio outranks brand docs for **organiser console** chrome and ops patterns, while still extending brand tokens rather than forking them.
4. Commerce/payment/access safety rules in MEL engineering governance outrank UI convenience — honesty and security are non-negotiable even if a lower design doc is silent.
5. [20](../20-vendor-studio-v2-vision.md) and [A03](../appendices/A03-future-ideas-parking-lot.md) never override [10](../10-roadmap.md) or higher docs until promoted through the lifecycle.

---

## What happens if two documents disagree

| Situation | Action |
| --- | --- |
| Lower doc contradicts higher | Fix the lower doc; do not “split the difference” in code |
| Two peers conflict (same level) | Design Authority decides; update both; add DDR if foundational |
| OS contradicts confirmed runtime safety (payments/access) | Stop; Technical Authority + Product Owner; amend OS or halt the feature |
| OS contradicts VX2 delivery note on philosophy | **OS wins** for design; update VX2 references or note obsolescence |
| Implementation contradicts OS | Defect — not an implicit redesign |

---

## Relationship to DDRs

| Record type | Role |
| --- | --- |
| **ADR-0001** | Constitution — authority, precedence, ownership |
| **DDR-nnn** | Product design decisions (shell, workspace, intents, components, mobile, hubs) |

DDRs must not violate this ADR. Changing precedence or ownership requires amending **ADR-0001** with Product Owner + Design Authority + Technical Authority approval.

---

## Consequences

- Every Vendor Studio implementation PR cites relevant OS documents
- Checklists and Definition of Done are gates, not suggestions
- Ambiguity is resolved upward, then documented downward
- The pack can freeze as v1.0 and evolve via governed revisions

---

## Future review triggers

- Reorganisation of Product / Design / Technical authority seats
- Proposal to split Vendor Studio into multiple products
- Conflict with a new locked platform contract that cannot be reconciled without precedence change
