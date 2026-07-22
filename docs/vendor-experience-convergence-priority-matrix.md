# Vendor Experience Convergence — Priority Matrix

**Status:** Prioritisation authority (documentation only)  
**Date:** 2026-07-22  
**Scoring:** 1–5. Higher = better for impact columns; higher = harder for Complexity.

---

## Epic scores

| Epic | Business impact | Support reduction | Revenue impact | Complexity | Dependencies | Quick wins | Launch priority |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **VX2-00 Trust** | 5 | 5 | 3 | 2–3 | Access, routes, strings | Many | **P0** |
| **VX2-01 Onboarding** | 5 | 4 | 4 | 3 | OnboardingManager, Stripe | Progress UI, copy | **P1** |
| **VX2-02 Dashboard** | 5 | 4 | 3 | 2 | Existing view model | Action queue first | **P1** |
| **VX2-03 Workspace** | 5 | 5 | 5 | 4 | Studio + Manager nav | Rename/merge shell | **P1** |
| **VX2-04 Tickets** | 5 | 4 | 4 | 4 | mel_ticket_type, advanced | Hide Product labels | **P2** |
| **VX2-05 Attendees** | 5 | 5 | 3 | 5 | Attendees, RSVP, check-in | Filters + Door Mode entry | **P2** |
| **VX2-06 Messages** | 4 | 4 | 3 | 4 | Comms, Pro, Studio messaging | Single entry CTA | **P2** |
| **VX2-07 Payments** | 5 | 4 | 5 | 4 | Stripe, payouts, refunds, finance | Status card | **P2** |
| **VX2-08 Analytics** | 4 | 3 | 4 | 3–5 | Analytics, reporting, gating | Free pulse + rename | **P1 / P3** |
| **VX2-09 Marketing** | 4 | 2 | 4 | 2 | Boost, growth | Rename Grow → Marketing | **P1** |
| **VX2-10 Settings** | 3 | 3 | 1 | 3 | Settings, venues, branding | Hub IA only | **P3** |

---

## Work-item matrix (selected)

| ID | Improvement | Biz | Support | Revenue | Complexity | Deps | Quick win | Phase |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| C-01 | Fix check-in permission drift | 5 | 5 | 2 | 2 | Door Mode | Yes | P0 |
| C-02 | Fix ticket list / resend permission drift | 5 | 5 | 3 | 2 | Tickets access | Yes | P0 |
| C-03 | Draft-choice on all Create CTAs | 4 | 4 | 4 | 2 | Create gateway | Yes | P0 |
| C-04 | 301 singular `/vendor/event/*` | 4 | 5 | 2 | 3 | Plural routes | Partial | P0 |
| C-05 | Remove Commerce/Product/Variation organiser copy | 5 | 4 | 3 | 3 | Studio + tickets | Partial | P0 |
| C-06 | Rename Studio group Commerce → Tickets & sales | 4 | 3 | 1 | 1 | Section plugins | Yes | P0 |
| C-07 | Shell nav → Convergence IA | 5 | 4 | 3 | 3 | VendorNavBuilder | No | P1 |
| C-08 | Dashboard action queue first | 5 | 4 | 3 | 2 | Dashboard VM | Yes | P1 |
| C-09 | One Event Workspace nav | 5 | 5 | 5 | 4 | Studio + tabs | No | P1 |
| C-10 | Free per-event Analytics pulse | 4 | 3 | 4 | 3 | Gating | No | P1 |
| C-11 | Pro lock screens with upgrade CTA | 4 | 3 | 5 | 2 | Pro | Yes | P1 |
| C-12 | Converge check-in → Door Mode | 5 | 5 | 3 | 5 | C-01 | No | P2 |
| C-13 | Attendee workspace unification | 5 | 5 | 3 | 5 | RSVP/waitlist | No | P2 |
| C-14 | Tickets app (advanced collapsed) | 5 | 4 | 4 | 4 | Ticket manager | No | P2 |
| C-15 | Payments hub | 5 | 4 | 5 | 4 | Stripe+payouts | No | P2 |
| C-16 | Messages unification | 4 | 4 | 3 | 4 | Comms+Pro | Partial | P2 |
| C-17 | Global Orders index | 4 | 3 | 3 | 3 | Event orders | No | P2 |
| C-18 | Analytics depth (charts, compare) | 4 | 2 | 4 | 5 | C-10 | No | P3 |
| C-19 | Settings hub consolidation | 3 | 3 | 1 | 3 | Branding routes | No | P3 |
| C-20 | Celebration system | 4 | 2 | 2 | 3 | Design system | No | P3 |
| C-21 | Series UX in Workspace | 3 | 2 | 3 | 5 | Legacy series | No | P4 |
| C-22 | AI assist clarity | 3 | 2 | 2 | 3 | Studio AI | No | P4 |

---

## Priority decision rules

1. **Ship P0 before marketing** any Convergence story.  
2. Prefer **support + trust** fixes that unblock revenue paths (Stripe, publish, tickets).  
3. Prefer **one-home merges** over net-new features until fragmentation drops.  
4. Quick wins may ship inside larger epics without waiting for full epic completion.  
5. Do not start P4 while P0 items remain open.
