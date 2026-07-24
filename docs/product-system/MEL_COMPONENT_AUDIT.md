# MEL Component Audit

**Status:** Complete  
**Date:** 2026-07-24  
**Type:** Documentation only  
**Goal:** Merge duplicates · reduce variations · one component everywhere

---

## Audit method

Reviewed Convergence inventory, VX2-02A card/layout utilities, `DESIGN_SYSTEM.md` contracts, interaction authority audit, empty-state governance, and VX2 hub implementations. No new component invented.

---

## Canonical components (prefer these)

| Component | Canonical home | Notes |
| --- | --- | --- |
| Card | `.mel-card` + status/primary/stack/grid | Public lock in `DESIGN_SYSTEM.md`; vendor extends |
| Button / CTA | Shared button system (`.mel-btn` family) | No page-local button kits |
| Layout container | `.mel-layout--form\|reading\|workspace\|dashboard\|wide` | VX2-02A |
| Empty state | `mel_empty_state` / governed slots | Prefer MelReadinessHelper vocabulary |
| Modal | `mel_modal` (+ confirmation) | MELInteractionSystem |
| Drawer | `mel_drawer` | Prefer over feature-local drawers for new work |
| Loading / saving | `mel_loading_state` · processing · saving | Governed async |
| Disclosure | `mel-disclosure` | Prefer over one-off accordion JS |
| Status / health | Status card pattern | Ready / Needs attention / Incomplete |
| Metric card | Metric card pattern | Confirmed metrics only |
| Nav shell | Convergence shell ≤10 | One nav builder story |
| Focus / motion | Token focus rings · `_motion` / reduced-motion | Brand + vendor tokens |

---

## Duplicate / parallel inventory

| Area | Variants found | Verdict |
| --- | --- | --- |
| **Cards** | `.mel-card`, live-ops card chrome, ad hoc empty cards | **MERGE** toward `.mel-card` + utilities; polish residual header variance |
| **Dialogs** | `mel_modal`, native `<dialog>`, `role="dialog"` panels, Drupal AJAX dialog, `window.confirm` | **REDUCE** — new organiser flows → `mel_modal`; keep native confirm only for minimal legacy guards; staff may differ |
| **Drawers** | `mel_drawer`, mobile details drawer, studio drawer, AI escalation drawer | **REDUCE** — new work → `mel_drawer`; theme mobile drawer OK; migrate feature-local over time |
| **Loading** | Governed async, skeletons, feature spinners, studio `is-loading` | **REDUCE** — skeletons for content paint; governed saving for mutations |
| **Empty states** | Governed `mel_empty_state`, class-only placeholders, Twig paragraphs | **MERGE** high-traffic organiser lists to governed pattern |
| **Analytics products** | Analytics, Insights, Charts, Reporting labels | **MERGE language** → Analytics product (runtime merge still epic VX2-08) |
| **Messaging products** | Hub, compose, Pro stubs, brand settings | **KEEP hub** as product; deep links only — no second app name |
| **Check-in** | Door Mode + legacy stacks | **REDIRECT** → Door Mode (Sprint 4 direction) |
| **Payments** | Hub + payouts deep + residual finance routes | **KEEP hub**; deep pages; redirect finance |
| **Page max-widths** | Historical 1080 / 1120 / 1200 / 1320 / 1440 | **CONVERGED** via layout intents — do not reintroduce hardcodes |
| **Vendor vs Organiser labels** | Mixed historical | **RENAME** per Language Guide (ongoing residual) |

---

## Variation budget (allowed)

| Component | Allowed variants | Not allowed |
| --- | --- | --- |
| Button | Primary, secondary, ghost, destructive | Page-unique colours/radii |
| Card | Default, status, primary, metric, ticket, guest | New shadow/radius systems |
| Badge (public) | Approved brand badges only; one per card | VIP/exclusive theatre |
| Form density | Default form; Door Mode dense | Third “admin compact” kit |
| Table vs cards | Breakpoint-driven choice | Two different guest models |

---

## Merge decisions (product)

| Decision | Detail |
| --- | --- |
| One Event Workspace | Studio + Manager chrome → single shell |
| One Tickets app | Advanced collapsed — not a second manager product |
| One Attendees home | Paid + RSVP + waitlist via filters |
| One Door Mode | Canonical check-in |
| One Messages product | Hub + event panel + compose |
| One Payments product | Hub owns money health |
| One Analytics product | Pulse + Pro depth (language now; surface merge VX2-08) |
| One empty pattern | Why + CTA + optional learn |

---

## Component scorecard

| Component | Consistency | Action |
| --- | --- | --- |
| Layout containers | High (post VX2-02A) | Guard against Twig hardcodes |
| Cards | Medium-high | Unify header treatments |
| Buttons | High on new surfaces | Spot-check legacy console |
| Empty states | Medium | Continue governance coverage |
| Modals/drawers | Medium | Reduce parallel owners |
| Tables | Medium | Shared density + empty |
| Status chips | Medium-high | Shared vocabulary |
| Forms | High when layout--form applied | Apply to residual wide forms |
| Skeletons/spinners | Medium | Document when to use which |

---

## Rule for new work

1. Find the canonical component above.  
2. Extend with a modifier — do not fork.  
3. If genuinely new, add a row here **before** shipping CSS.  
4. Update `MEL_DESIGN_DEBT.md` if shipping leaves a temporary duplicate.

---

## Explicit non-goals

- Do not restyle public locked hero (`.mel-event-hero--featured-style`).
- Do not create a second token system for vendor vs public — align tokens.
- Do not unify staff Control Centre into organiser components blindly.
