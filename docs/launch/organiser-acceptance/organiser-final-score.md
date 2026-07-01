# Organiser Experience — Final Score & Go/No-Go

**Date:** 2026-06-26 · **Environment:** DDEV live (authenticated Pro + non-Pro probes) ·
**Benchmark:** Eventbrite / Humanitix / Meetup · **Code changes:** none.

## Executive summary

MyEventLane gives organisers a **consolidated, benchmark-credible experience**. Event Studio is the
verified canonical surface (legacy routes redirect into `/studio/*`), the vendor dashboard is a
genuine command centre that answers "how am I doing / what needs attention / what next" **for free**,
onboarding is guided and resumable, check-in is launch-grade (PWA offline + QR), and money/refund/
payout integrity is already verified. The spine of the journey — **sign up → sell → run → get paid**
— is solid end-to-end.

It is held below "world-class" by a **small, well-bounded set of off-spine issues**: a broken Event
Insights page (500), a 404 on the attendee-messaging route, Pro lock-points that don't sell the
upgrade, a restricted/duplicate analytics surface, and **unverified** accessibility/mobile (no axe/
device lab in this environment).

## Scores

| Area | Score | Basis |
| --- | :-: | --- |
| **Overall organiser readiness** | **8.0 / 10** | weighted across tasks + defects + unverified gates |
| Task completion | 7.7 | most tasks complete; 2 broken (insights 500, comms 404), 1 missing (duplicate) |
| Accessibility | 6.8* | primitives present; full WCAG AA **Unable to verify** |
| Mobile | 6.7* | responsive + PWA; on-device completion **Unable to verify** |
| Trust | 7.8 | Stripe, payouts (refund-netted), refund workflow, BAS |
| Pro experience | 6.9 | gating correct + secure; lock-point conversion copy weak |
| Event Studio | 8.5 | canonical, consolidated, consistent shell |
| Dashboard | 8.6 | command-centre IA; answers the four questions free |
| Visual consistency | 8.6 | unified "Event Studio" shell + design system |
| Business value | 8.5 | sell/run/get-paid all supported |

`*` Accessibility/Mobile are **unverified**, not proven-poor — a live pass is expected to lift them.

## Contribution to overall launch readiness

The prior customer programme set verified readiness at **8.3/10**. This organiser programme finds
**no P0 organiser blockers** and confirms a strong organiser spine, sustaining the overall trajectory.
Organiser-side contribution: **8.0/10**, lifting to ~**9.3** once OB-1/2/3 land and OV-1/2/3 pass.

## Path to ≥ 9.5 (required for "world-class" sign-off)

| Step | Lifts | From → To (est.) |
| --- | --- | --- |
| OB-1 fix Event Insights 500 | Analytics, task completion | insights task 4.1 → ~8.3 |
| OB-2 restore attendee messaging | Operations, day-of | message task 4.7 → ~8.3 |
| OB-3 Pro upgrade CTA at lock points | Pro, conversion | discover-Pro 6.0 → ~8.5 |
| OB-6 dashboard h1 + OV-1 WCAG AA | Accessibility | a11y 6.8* → ~9.0 |
| OV-2 on-device mobile pass | Mobile | mobile 6.7* → ~9.0 |
| OB-4/5/7 fast-follow | polish, parity | — |

Projected overall after Wave 1 (P1) + verification gates: **≈ 9.5 / 10.**

## Go / No-Go

# ✅ GO WITH CONDITIONS

**Basis:** No P0 organiser blockers. Core organiser capability — onboard, connect Stripe, create,
ticket, publish, promote, sell, manage attendees, check in, refund, see revenue, get paid — is
present and, where money is involved, verified safe. The detractors are off-spine (insights,
messaging route, Pro-conversion copy) and/or **unverified** (a11y/mobile), not journey-breaking.

**Conditions:**
1. Land **OB-1, OB-2, OB-3** (P1) or risk-accept with explicit owner sign-off.
2. Pass verification gates **OV-1 (WCAG AA), OV-2 (mobile device), OV-3 (empty states)** — required
   to certify the ≥9.5 "world-class" bar; without them, the experience is launch-ready but not yet
   certified world-class.
3. Re-check dblog is free of organiser-route 500s (OD-1, OD-7) post-fix.

No evidence supports **NO-GO**: nothing breaks an organiser's ability to run and monetise an event.

> Discipline note: findings are evidence-based (authenticated runtime probes + code/route/service
> inspection). No code was changed in this programme. Items that could not be confirmed in this
> environment are explicitly marked **Unable to verify** and carried as verification gates, not
> asserted as pass.
