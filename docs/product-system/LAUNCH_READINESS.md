# Launch Readiness

**MyEventLane 1.0 — Organiser product foundation**  
**Status:** Assessment  
**Date:** 2026-07-24  
**Type:** Documentation only  
**Metrics authority:** [`docs/vendor-experience-convergence-success-metrics.md`](../vendor-experience-convergence-success-metrics.md)

Statuses: **Ready** · **Needs work** · **Blocked** · **Not started**

---

## How to read this

- **Ready** — Convergence exit criteria met for the area; residual items are Low polish or verification.  
- **Needs work** — Shipped spine exists; High/Medium debt or unverified QA remains.  
- **Blocked** — Critical trust/access/money issue unresolved.  
- **Not started** — Epic or hub not meaningfully present for organisers.

Success metrics prove outcomes over time; this checklist declares **launch-ready vs successful**. Do not declare Convergence “successful” on ship day.

---

## North-star alignment

| North star | Launch posture |
| --- | --- |
| Successful events published | Needs work — path strong; blockers/QA remain |
| Organiser retention 30/90 | Not started (measurement) — product spine Needs work |
| GMV | Needs work — Payments Ready-ish; Stripe completion Needs work |
| Support contacts per organiser | Needs work — jargon/IA reduced; Analytics/Orders debt remains |

---

## Product areas

| Area | Status | Evidence | Remaining to Ready |
| --- | --- | --- | --- |
| **Trust & language (VX2-00)** | Needs work | Sprint 1 shipped; C-01/C-02 deferred | Close permission drift; residual string sweeps |
| **Onboarding (VX2-01)** | Needs work | Spine exists; reduced shell | Celebration polish; Stripe “get paid” consistency |
| **Dashboard (VX2-02)** | Needs work | Layout converged; action-first spec | Attention depth; live QA vs empty/active states |
| **Layout system (VX2-02A)** | Ready | Tokens + containers shipped; scores ≥8.5 core | Guard hardcodes; Studio CSS alias (Medium) |
| **Event Workspace (VX2-03)** | Needs work | One shell; Home redesign | Schedule/Venue forms; Home naming; activity depth |
| **Tickets (VX2-04)** | Needs work | One Tickets app shipped | Edge Advanced demotion; archive clarity; QA |
| **Attendees + Door Mode (VX2-05)** | Needs work | One workspace shipped | **Sign off QA/a11y**; bulk depth |
| **Messages (VX2-06)** | Needs work | Hub + compose shipped | Audience filters; schedule UI; collector |
| **Payments (VX2-07)** | Needs work | Hub shipped; strong trust copy | Residual settings strings; refund route ownership; QA |
| **Analytics (VX2-08)** | Needs work | Hub shipped as Event Intelligence Centre; Insights/Exports redirect; free pulse unlocked | Collector wiring; Audience/Boost depth; QA sign-off |
| **Marketing (VX2-09)** | Needs work | Boost + Marketing sections | Scan/hierarchy polish; share tools cohesion |
| **Settings & Support (VX2-10)** | Needs work | Settings/Support live | Branding satellite consolidation |
| **Global Orders hub** | Not started | Event-scoped only | C-17 hub |
| **Instrumentation / metrics pipeline** | Not started | Hooks logged/marked; collector deferred | Wire collector; baselines |
| **Series / AI delight (P4)** | Not started | Roadmap P4 | Out of 1.0 minimum |

---

## Funnel readiness (from success metrics)

| Metric theme | Status | Notes |
| --- | --- | --- |
| Registration → profile complete | Needs work | Onboarding polish |
| Stripe start → complete | Needs work | Hub helps; completion UX polish |
| First event created | Ready | Create gateway + draft choice Sprint 1 |
| First event published | Needs work | Readiness strong; celebration/QA |
| Time to first ticket | Needs work | Tickets app helps; measure |
| Draft-choice clarity | Ready | Create CTAs through gateway |
| Commerce jargon impressions | Needs work | Critical paths purged; residual edges |
| Nav ≤10 | Ready | Sprint 1 Convergence IA |
| Duplicate surfaces live | Needs work | Messages/Payments/Analytics converged; Check-in residual |
| Door Mode task success | Needs work | Pending moderated test |
| A11y critical on shell + Door Mode | Needs work | Pattern review done; live zero-critical not proven |
| 403 dead-ends on known P0 | Blocked → Needs work | Partial; C-01/C-02 |

---

## Convergence launch-ready minimum (roadmap checklist)

| Criterion | Status |
| --- | --- |
| Known organiser 403s fixed or gated with recovery | Needs work (partial) |
| Create event resume-or-new when draft exists | Ready |
| Shell nav matches Convergence IA | Ready |
| Commerce group renamed; Ticket Product gone on critical UI | Ready |
| Event Workspace one nav model | Ready |
| Analytics free pulse without bare deny | Ready (VX2-08 hub; QA pending) |
| Door Mode canonical; others redirect | Ready (verify leftovers) |

---

## Design system foundation

| Deliverable | Status |
| --- | --- |
| Product bible (`MEL_PRODUCT_SYSTEM.md`) | Ready |
| UX patterns | Ready |
| Component audit | Ready |
| Microcopy audit | Ready |
| Interaction audit | Ready |
| Accessibility review (pattern) | Ready |
| Design debt register | Ready |
| This launch checklist | Ready |

Runtime implementation of remaining debt is **out of scope** for this documentation sprint.

---

## Launch confidence (product design)

| Dimension | Rating | Rationale |
| --- | --- | --- |
| Product maturity | **7.8 / 10** | Spine shipped through Payments/Messages; Orders/Analytics depth open |
| Design maturity | **8.2 / 10** | Layout + Workspace Home + hubs coherent |
| Interaction maturity | **7.8 / 10** | Intentional on new surfaces; parallel shells remain |
| Consistency | **8.0 / 10** | Strong post-VX2; card/Studio CSS edges |
| Accessibility | **7.7 / 10** | Patterns sound; live Door Mode/sign-off open |
| **Launch confidence** | **7.5 / 10** | Ship foundation yes; declare “Convergence successful” no — clear Critical/High first |

---

## Go / no-go guidance

**Go for:** treating the Product System pack as permanent law for all future organiser features.

**No-go for:** marketing “fully converged Humanitix-level organiser suite” until Critical debt cleared, Door Mode QA signed, Analytics naming/surfaces converged, and Orders hub at least scoped.

**MyEventLane Product System is complete.**
