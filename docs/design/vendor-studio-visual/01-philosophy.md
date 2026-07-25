# Vendor Studio Visual Language — Philosophy

**Sprint:** VS1 — Vendor Studio Visual Language  
**Status:** ✅ **APPROVED · FROZEN** (Product Owner 2026-07-25)  
**Date:** 2026-07-25  
**Complete with:** [Workspace Zones](07-workspace-zones.md) (first-class) · B.5  
**Constraint:** Design stable — implementation only via VL phases; no further design expansion.

---

## Why this sprint exists

Vendor Studio architecture has improved — Event Workspace, Mission Control, Launch Centre, authoritative Hero CTA, readiness vs eligibility separation. The Product Owner has paused implementation because the **visual expression** still reads as a traditional admin dashboard.

Architecture stays. Presentation must change.

This pack defines the visual identity that will guide every remaining Workspace page — without redesigning product structure.

---

## Authority and freeze

| Layer | Authority | This sprint may… |
| --- | --- | --- |
| Product Design System (PDS) | `docs/design/vendor-studio/` — FROZEN | Cite; not rewrite IA or philosophy |
| Brand tokens | `docs/brand/` · PDS `11-design-tokens.md` | Extend Studio visual expression within brand DNA |
| Hero contract | Workspace Foundation / PDS | Change **visual expression only** — not CTA ownership, layout role, or freeze |
| Mission Control | Workspace v2 — FROZEN structure | Change **visual expression only** — not checklist logic or Home narrative |
| Launch Centre | Wireframes `18` · UX rules `19` · state model `17` | Change **visual expression only** — not band order or dual-Publish rules |
| Runtime / Commerce / access | Repository | Untouched |

**Golden Rule (unchanged):** If the organiser cannot answer “What should I do next?” within five seconds, the screen has failed.

---

## Problem diagnosis (visual, not structural)

| Symptom | Likely visual cause |
| --- | --- |
| Feels like Drupal admin | Dense borders, form chrome dominance, CMS-adjacent surfaces |
| Feels like generic SaaS | Card walls, equal-weight panels, metric strips without narrative |
| Feels like corporate CRM | Cool greys, sharp grids, status chips everywhere |
| Feels like Eventbrite | Marketplace loudness, promo density, competing CTAs |
| “Cards everywhere” | Nested surfaces, every block elevated, no flat reading bands |

These are **presentation debts**. Eligibility, routes, and CTA ownership remain sound.

---

## Target feeling

Vendor Studio should feel:

| Quality | Meaning for organisers |
| --- | --- |
| **Modern** | Contemporary ops UI — not 2014 admin themes |
| **Premium** | Restraint, typography, space — not luxury badges |
| **Calm** | One narrative per screen; soft surfaces; no FOMO |
| **Community-first** | Warm cream DNA; local / human tone — not enterprise cold |
| **Operational** | Clear next action; money and publish treated soberly |
| **Enjoyable** | Worth opening daily — progress celebrated without theatre |

It must **not** feel like Drupal admin, generic SaaS dashboards, corporate CRM, Eventbrite, or card wallpaper.

---

## Product personality (visual translation)

From PDS poster: **Warm · Capable · Local · Calm · Honest**

| Trait | Visual implication |
| --- | --- |
| Warm | Warm Cream canvas; soft lavender/sky support; coral focus — not sterile white or dark mode |
| Capable | Structured columns; clear primary CTA; sober money/publish treatment |
| Local | Soft community cues; Guide tone at empty/success — not mascot spam |
| Calm | Generous whitespace; few borders; one elevation language |
| Honest | Semantic colour + text + icon; no fake “all green” confidence |

---

## Non-negotiables

1. **Architecture frozen** — Hero, Mission Control, Launch Centre product contracts stand.
2. **One primary action** — Visual hierarchy must reinforce Hero authority; never invent a second primary of the same verb.
3. **Cards earn their size** — Flat bands and typography first; elevate only interactive or decision units.
4. **Brand tokens only** — Primary Purple, Lavender, Coral, Discovery Gold (rare), Warm Cream, Soft Sky — no parallel palette.
5. **Australian English** — Copy stays human; no CMS vocabulary in UI.
6. **Accessibility first** — WCAG AA contrast, visible focus, ≥44px touch targets, `prefers-reduced-motion`.
7. **Mobile 390 first** — Visual language must work at 390px before desktop polish.
8. **Money and publish stay sober** — Warmth never replaces precision on Stripe, refunds, or go-live.

---

## Relationship to Option directions

Sprint VS1 explores three complete visual directions, then synthesises a recommended MEL direction:

| Option | Codename | Spine |
| --- | --- | --- |
| **A** | Editorial Workspace | Open · typographic · minimal cards |
| **B** | Soft Command Centre | Operational · structured · soft surfaces |
| **C** | Guided Studio | Assistant-led · illustrated · optimistic |
| **B.5** | **Vendor Studio Visual Language v1** (recommended) | Layout from B · Warmth from C · Type/space from A |

Full comparisons: [02-visual-directions.md](02-visual-directions.md)  
Recommended system: [03-option-b5.md](03-option-b5.md)

---

## Deliverable map

| Doc | Purpose |
| --- | --- |
| [01-philosophy.md](01-philosophy.md) | This document |
| [02-visual-directions.md](02-visual-directions.md) | Options A, B, C complete |
| [03-option-b5.md](03-option-b5.md) | Recommended MEL Visual Language v1 |
| [04-component-examples.md](04-component-examples.md) | Annotated component recipes (no CSS) |
| [05-before-after.md](05-before-after.md) | Admin → Studio visual shifts |
| [06-implementation-guide.md](06-implementation-guide.md) | What changes visually vs what stays frozen |

---

## Success criterion

Product Owner approved **B.5** and **Workspace Zones**. Design system is **complete and frozen**. Implementation proceeds only via VL-1…VL-6 with PO approval between phases — no further design documentation expansion.

**Next gate:** Product Owner opens **VL-1**.
