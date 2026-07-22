# Vendor Experience Convergence — Roadmap

**Status:** Prioritised product roadmap  
**Date:** 2026-07-22  
**Runtime:** VX2 Sprint 2 on `feature/vx2-event-workspace`; Sprint 1 merged (PR #701)  
**Related:** [`vendor-experience-convergence.md`](vendor-experience-convergence.md), [`vendor-experience-convergence-priority-matrix.md`](vendor-experience-convergence-priority-matrix.md), [`vendor-experience-convergence-implementation-plan.md`](vendor-experience-convergence-implementation-plan.md)

---

## Launch phases

| Phase | Meaning |
| --- | --- |
| **P0** | Trust / access / journey-blockers — before marketing Convergence |
| **P1** | Spine: nav, dashboard, workspace shell, onboarding, language |
| **P2** | Unified ops & money: tickets, attendees, messages, payments |
| **P3** | Depth: analytics, settings hub, marketing polish, celebrations |
| **P4** | Advanced: series, AI delight |

---

## Outcome sequencing

```text
P0 Trust & journey integrity
  Language leaks · permission dead ends · legacy redirects · draft choice
       ↓
P1 Convergence spine
  One nav · Dashboard action-first · One Event Workspace shell · Onboarding polish
       ↓
P2 Unified ops & money
  Tickets app · Attendees · Door Mode · Messages · Payments hub
       ↓
P3 Depth & delight
  Analytics depth · Settings consolidation · Marketing · Celebrations
       ↓
P4 Advanced
  Series · AI assist clarity
```

---

## Epic roadmap

| Epic | Name | Phase | One-line outcome |
| --- | --- | --- | --- |
| **VX2-00** | Trust & integrity | P0 | Organisers never hit known dead ends or Commerce jargon on critical paths — **Sprint 1 implemented** |
| **VX2-01** | Onboarding | P1 | Guided, celebratory setup; Stripe as “get paid” |
| **VX2-02** | Dashboard | P1 | Action queue first; business KPIs |
| **VX2-03** | Workspace | P1 | One Event Workspace replaces Studio vs Manager duality |
| **VX2-04** | Tickets | P2 | One ticket application; advanced collapsed |
| **VX2-05** | Attendees | P2 | One guest workspace + Door Mode |
| **VX2-06** | Messages | P2 | One Messages product |
| **VX2-07** | Payments | P2 | One Payments hub |
| **VX2-08** | Analytics | P1 pulse / P3 depth | One Analytics product |
| **VX2-09** | Marketing | P1–P2 | Boost + share under Marketing |
| **VX2-10** | Settings & Support | P3 | Consolidated settings; warm support |

---

## Current vs future journey

| Stage | Current experience | Future experience |
| --- | --- | --- |
| Register → organiser | Mixed vendor/organiser language | Organiser language throughout |
| Stripe | Connect works; status can feel opaque | Payments health with why + fix |
| Create event | Draft ambiguity; dual editors | Explicit draft choice → one Workspace |
| Tickets | Studio + Advanced + Commerce labels | Tickets app only |
| Publish | Strong readiness; uneven explanation | Publishing section + celebration |
| Promote | Grow / Boost / Messaging split | Marketing + Messages |
| Sales | Analytics vs Insights | Analytics |
| Attendees | Many lists & check-ins | Attendees + Door Mode |
| Repeat | Settings satellites | Create from previous + Settings hub |

---

## Revenue levers

1. **Faster first publish** (draft choice + Workspace) → more live events → GMV  
2. **Stripe completion** (Payments clarity) → paid tickets unlocked  
3. **Free Analytics pulse + honest Pro** → conversion without trust damage  
4. **Marketing / Boost clarity** → paid promotion  
5. **Fewer abandoned refunds/check-in failures** → retention and reviews  

## Support levers

1. Permission dead ends (tickets, check-in)  
2. Legacy singular routes and placeholders  
3. Fragmented attendees / messaging / analytics “where do I click?”  
4. Commerce language confusion  

---

## Definition of Convergence launch-ready (minimum)

- Known organiser 403s on invite-into surfaces fixed or gated with recovery — *partially deferred (C-01/C-02)*  
- Create event always offers resume-or-new when a draft exists — **Sprint 1: Create CTAs use gateway**  
- Shell nav matches Convergence IA (no Commerce/Content/Media) — **Sprint 1 done**  
- Studio “Commerce” group renamed; Ticket Product labels gone from organiser UI — **Sprint 1 done**  
- Event Workspace has one nav model (even if routes alias underneath) — **VX2-03**  
- Analytics free pulse available without bare deny — **VX2-08**  
- Door Mode documented as canonical check-in; others redirect or staff-only — **VX2-05** (shell Check-in removed in Sprint 1)  

### Sprint 1 behaviour notes

- **Payments** shell item currently opens existing Payouts (`/vendor/payouts`) until VX2-07 hub exists.  
- **Orders** remains event-scoped (disabled without event context) until C-17 hub.  
- **Messages** still lands on messaging brand settings until VX2-06 unifies send UIs.  
- Refund requests and Check-in removed from shell; event/ops entry points remain.

---

## Relationship to prior VX2 roadmap

This Convergence roadmap **absorbs and supersedes sequencing** in [`vendor-experience-v2-roadmap.md`](vendor-experience-v2-roadmap.md) for product planning. Engineering may still map ticket IDs R-01…R-30 into Convergence epics (see implementation plan).
