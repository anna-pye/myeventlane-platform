# MEL Accessibility Review

**Status:** Complete  
**Date:** 2026-07-24  
**Standard:** WCAG 2.2 AA minimum  
**Type:** Documentation only — pattern review, not a live axe lab certification

---

## Scope

Core organiser patterns: Dashboard, Event Workspace, Tickets, Attendees / Door Mode, Payments, Messages, forms, cards, tables, empty/success/error states. Cross-checked against VX2-02A a11y notes, hub QA checklists, interaction authority audit, and brand a11y rules.

**Not claimed:** Full automated axe pass or screen-reader certification on device — those remain launch verification items.

---

## Global rules (product law)

1. Contrast AA for text and essential UI.  
2. Visible focus on all controls.  
3. Touch targets ≥ 44px on operational mobile (Door Mode, sticky CTAs, filter chips).  
4. Severity not colour-only.  
5. Landmarks: skip link, `<main>`, labelled health/KPI regions.  
6. Labels on inputs; errors tied to fields.  
7. Modals/drawers: focus trap, Escape, restore (governed shells).  
8. `prefers-reduced-motion` for decorative motion.  
9. Access enforced server-side — UI hiding is not a11y or security.  
10. Live regions: polite default; assertive only for critical failures.

---

## Pattern review

| Pattern | AA expectations | Evidence / gap |
| --- | --- | --- |
| **Dashboard** | Queue keyboardable; KPI `aria-label`s; Stripe chip text status | Layout a11y retained VX2-02A; verify live labels |
| **Workspace Home** | Heading hierarchy; Next Action focusable; readiness disclosure | Strong structure; QA screenshots backlog |
| **Cards** | If clickable, role/button or link semantics; focus ring | Lift respects reduced motion |
| **Status cards** | Text + icon for Ready/Needs attention | Hub implementations include headings |
| **Metric cards** | Accessible names for numbers | Payments/Messages KPI aria-labels documented |
| **Tables** | Headers; caption or aria-label; empty not blank | Prefer cards on mobile attendees |
| **Search** | Labelled; results region | Sprint 4 checklist includes SR list region |
| **Filters** | `aria-pressed` on chips; clear control | Documented in Sprint 4 QA |
| **Forms** | Labels, errors, reading width | Form layout 800 — readability win |
| **Confirmation panels** | Focus trap; labelled dialog | Prefer mel-modal; native dialog restore gap noted |
| **Empty states** | `role="status"` polite when governed | Wave B + MelComponentAccessibilityHelper |
| **Payments hub** | Health region heading; keyboard CTAs | In VX2-07 QA checklist |
| **Messages hub** | Section headings; compose radios | In VX2-06 QA checklist |
| **Marketing hub** | Jump nav; share controls; QR alt; copy live region | In VX2-09 QA checklist |
| **Door Mode** | Large targets; SR check-in feedback; no colour-only | Critical path — must pass manual QA before launch claims |
| **Publish blocked** | Checklist items as text links/buttons | Human readiness strip |

---

## Known accessibility risks

| Risk | Priority | Notes |
| --- | --- | --- |
| Multiple dialog/drawer implementations → inconsistent focus restore | High | Interaction audit |
| Competing `aria-live` regions | Medium | Unify per surface |
| Filter chip density on 390px — target size | Medium | Attendees |
| Studio drawer not on governed contract | Medium | Parallel pattern |
| Reduced motion on all skeletons not systematically proven | Medium | Verify |
| Colour contrast of pastel status chips | Medium | Spot-check Needs attention / Failed |
| Landing/public skip-link vs vendor shell parity | Low–Medium | Public acceptance notes |
| Manual QA checklists mostly unchecked in VX2 docs | High for launch confidence | Process debt |

---

## Door Mode (special)

Door Mode is the highest a11y + stress surface:

- One-handed / outdoor glare tolerance in visual design  
- Search + check-in without precision mouse  
- Announcements that don’t flood the queue  
- Failure states loud enough for staff, calm enough for guests nearby  

Treat Door Mode regressions as **Critical** design debt.

---

## Accessibility score

| Area | Score /10 |
| --- | --- |
| Structural / landmarks (new hubs) | 8.5 |
| Focus & keyboard | 8.0 |
| Forms & errors | 8.0 |
| Status / non-colour | 8.0 |
| Live regions consistency | 7.0 |
| Modal/drawer consistency | 6.5 |
| Door Mode verified | 7.0 (docs strong; live QA open) |
| **Overall** | **7.7** |

---

## Definition of done (per surface)

A surface is a11y-ready when:

1. Keyboard completes the primary task.  
2. Focus is always visible.  
3. Status is available to SR.  
4. Errors are announced and associated.  
5. Touch targets met on 390px for primary actions.  
6. Reduced-motion checked for that surface’s motion.  
7. No critical contrast fails on primary UI.
