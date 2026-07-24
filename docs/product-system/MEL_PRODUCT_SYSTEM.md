# MEL Product System

**MyEventLane 1.0 — Product bible**  
**Status:** Complete  
**Date:** 2026-07-24  
**Type:** Documentation only — no runtime changes  
**Audience:** Product, design, engineering, support

Everything already exists. This document makes every future feature feel like it belongs to the same product.

---

## Mission

MyEventLane exists to help organisers create successful events.

Organisers should think about:

- their event
- their attendees
- their tickets
- their revenue

They should **never** think about Drupal, Commerce, stores, products, variations, media entities, taxonomy, paragraphs, or entity references.

---

## Product law (do not invent another language)

This bible **unifies** — it does not replace — the Convergence pack and MEL Style Guide:

1. MEL Style Guide — `DESIGN_SYSTEM.md` + `docs/brand/`
2. Vendor Experience Convergence — `docs/vendor-experience-convergence.md`
3. Convergence Implementation Plan — `docs/vendor-experience-convergence-implementation-plan.md`
4. Language Guide — `docs/vendor-experience-convergence-language-guide.md`
5. Screen Specifications — `docs/vendor-experience-convergence-screen-specifications.md`
6. Information Architecture — `docs/vendor-experience-convergence-information-architecture.md`

Companion audits in this folder cover patterns, components, microcopy, interaction, accessibility, design debt, and launch readiness.

---

# Stage 1 — VX2 sprint review

Evidence base: Convergence pack (2026-07-22), Sprint 1–4 + VX2-02A + Event Home redesign, VX2-06 Messages Hub, VX2-07 Payments Hub (2026-07-24), layout UX scores in `vx2-02a-workspace-layout-convergence.md`.

## Dashboard

| Lens | Finding |
| --- | --- |
| **Excellent** | Action-first framing in spec; layout tokens (1280 dashboard); Stripe health chip concept; VX2-02A score ~8.8 |
| **Inconsistent** | Action queue vs KPI visual weight still varies by content density; Pro value card can compete with primary queue |
| **Still Drupal** | Residual risk of console/header chrome reading as CMS dashboard when empty states are thin |
| **Unfinished** | Full VX2-02 dashboard epic depth (refund attention, draft attention) not fully declared “done” vs layout pass; instrumentation baselines still deferred |

## Workspace (Event Workspace / Home)

| Lens | Finding |
| --- | --- |
| **Excellent** | One Event Workspace shell; Home compositional redesign (Event Ready + Next Action + KPI rows + activity); human readiness; Publishing + Marketing sections; layout 1200 |
| **Inconsistent** | “Overview” vs “Home” label drift across Convergence docs vs shipped nav; Studio module CSS widths still partly outside vendor tokens |
| **Still Drupal** | Schedule/Venue still share information form in places; builder chrome can still feel form-admin adjacent |
| **Unfinished** | Activity timeline missing messages/system changes; visual QA screenshots backlog; Schedule/Venue dedicated forms deferred |

## Tickets

| Lens | Finding |
| --- | --- |
| **Excellent** | One Tickets app; card UX; Advanced Ticket Tools collapsed; Commerce autocomplete admin-only; GA+VIP without Product language (Sprint 3) |
| **Inconsistent** | Card chrome vs live-ops cards slight variation; sticky Add Ticket vs desktop primary placement |
| **Still Drupal** | Low residual risk in Advanced inventory/sync tools if demotion incomplete on edge paths |
| **Unfinished** | Inventory sync depth; widget/access-code polish; delete reserved vs archive clarity for organisers |

## Attendees

| Lens | Finding |
| --- | --- |
| **Excellent** | One Attendee Workspace; search/filters/cards; Door Mode as mode; Message/Export/Refund entry; AU empty states (Sprint 4) |
| **Inconsistent** | Dense (≥100) vs card layout switch needs ongoing QA; filter chip density on 390px |
| **Still Drupal** | Legacy waitlist/check-in URL redirects still exist (good product, but path grammar debt remains) |
| **Unfinished** | Manual QA checklist largely unchecked in docs; instrumentation pipeline deferred; bulk actions depth; global Attendees hub productisation |

## Payments

| Lens | Finding |
| --- | --- |
| **Excellent** | Payments Hub; health states; AU trust copy; no Gateway/Commerce/Store in hub UI; layout hierarchy score ~8.9; Settings CTA into hub |
| **Inconsistent** | Hub overview vs deep payouts page depth; refund surfaces still partly parallel (ownership review noted) |
| **Still Drupal** | Settings residual stored-status phase strings called out as follow-up |
| **Unfinished** | Live payout history table optional; analytics collector deferred; parallel refund route retirement pending |

## Messages (adjacent P2 — reviewed for consistency)

| Lens | Finding |
| --- | --- |
| **Excellent** | Messages Hub; Event Workspace panel; compose restored; message types; AU trust copy |
| **Inconsistent** | Audience filters marked Soon; schedule edit/cancel/retry incomplete; Pro template deep links |
| **Still Drupal** | Residual admin/platform messaging jargon outside organiser surfaces |
| **Unfinished** | Schedule UI; advanced audience; analytics collector |

## Cross-sprint verdict

| What already feels excellent | What still feels inconsistent | What still feels like Drupal | What still feels unfinished |
| --- | --- | --- | --- |
| Event Workspace Home composition | Overview/Home naming | Shared Schedule/Venue forms | Analytics product merge (Insights) |
| Layout container intents | Card header treatments | Studio CSS width islands | Global Orders hub |
| Tickets card + Advanced collapse | Messages/Marketing scan scores (8.4) | Residual Settings payment strings | Instrumentation pipelines |
| Payments health framing | Interaction shells (modal/drawer owners) | Legacy path grammar | Onboarding celebration depth |
| Language purge on critical paths | Empty-state governance coverage gaps | Drupal Form API dialogs in places | C-01/C-02 permission drift follow-up |

---

# Design principles

1. **Organiser first** — Every screen answers: what is the organiser trying to achieve right now?
2. **Hide complexity** — Commerce and CMS stay backstage.
3. **One primary decision** — One primary action per screen; secondary never competes.
4. **Always show the next step** — Empty, error, blocked, and success states all answer “now what?”
5. **Always explain why** — Blocks include reason + recovery CTA.
6. **Celebrate progress** — Publish, Stripe connect, first ticket, first sale feel earned — not clinical.
7. **Guide, don’t gatekeep** — Progressive disclosure; Advanced collapsed until needed.
8. **Mobile-first** — 390px baseline; Door Mode and sales monitoring work on a phone.
9. **Warm · Community-focused · Australian English** — Grassroots organiser tone.
10. **One product spine** — Global organiser workspace ↔ Event Workspace. Never Studio vs Manager as two products.

---

# Interaction principles

1. **Intentional states** — Hover, focus, loading, saving, success, error are designed — never accidental browser defaults alone.
2. **Confirm destructive money actions** — Refund, cancel event, take offline, delete where irreversible — clear consequence copy.
3. **Saving is visible** — Saving / Saved / Failed with recovery; no silent failure.
4. **Publishing is ceremonial** — Blocked = checklist; success = celebrate + share.
5. **One interaction authority preferred** — Prefer MEL interaction shells (`mel-modal`, `mel-drawer`, governed loading) over parallel dialog stacks for new work.
6. **Respect reduced motion** — Decorative motion yields to `prefers-reduced-motion`.
7. **Keyboard equals mouse** — Every primary path completable without a pointer.
8. **No surprise navigation** — Redirects preserve mental model (Messages stays Messages, not Events).

---

# Layout principles

From VX2-02A — extend, do not fork:

| Intent | Max width | Use |
| --- | --- | --- |
| Form / reading | 800px | Forms, support prose, constrained status |
| Workspace | 1200px | Event ops boards, tickets, attendees |
| Dashboard | 1280px | Global dashboard |
| Marketing / wide | 1400px | Boost / marketing grids |

Rules:

- Full-width **shell**; centred **content**.
- Whitespace creates hierarchy — not decorative emptiness.
- Cards earn their size — no five equal full-bleed status cards.
- Scan path: title → primary action → status → next → supporting.
- Never hardcode content widths in Twig — layout classes + tokens.

---

# Component principles

1. **One card system** — Extend `.mel-card` (+ status / primary / stack / grid utilities). No parallel card chrome.
2. **One button system** — Primary / secondary / ghost via shared button tokens.
3. **One empty state pattern** — Why empty · one primary CTA · optional learn link (`mel_empty_state` where governed).
4. **One status language** — Ready / Needs attention / Incomplete / Failed — severity not colour-only.
5. **Metric cards are summaries with CTAs** — Not navigation dumps.
6. **Tables are operational** — Dense, scannable, mobile-aware; cards preferred for guest lists unless density demands table.
7. **Forms are reading-width** — Labels clear; help text blame-free; errors adjacent and specific.
8. **Prefer reuse over variation** — See `MEL_COMPONENT_AUDIT.md`.

---

# Hierarchy principles

1. **Business health over CMS chrome** — Dashboard is action queue + money/ops health, not entity lists.
2. **Event Ready + Next Action dominate Home** — Supporting KPIs below.
3. **Progressive disclosure** — Advanced Ticket Tools, Pro depth, merch/series deferred from top nav.
4. **Shell ≤ 10 top-level items** — Convergence IA.
5. **Danger zone last and labelled** — Cancel / archive explained.

---

# Copy principles

Authority: Language Guide + `docs/brand/copy-guidelines.md`.

1. Human copy = **Organiser**; machine/URLs may remain `vendor`.
2. Prefer verbs organisers use: Create, Publish, Share, Message, Refund, Check in.
3. Australian English: organise, favour, cancelled.
4. Banned in organiser UI: Drupal, Commerce, Store, Product, Variation, Gateway, Media entity, Taxonomy, Paragraph, Node, Entity.
5. Warm, specific, blame-free — never FOMO, exclusivity theatre, or “VIP/members only” brand pressure.
6. Pro gates show **value + upgrade**, never bare deny.
7. Money language: Payments, Payouts, Refunds, Get paid with Stripe.

---

# Accessibility rules

Minimum: **WCAG 2.2 AA**.

1. Contrast AA for text and UI components.
2. Visible focus rings on all interactive controls.
3. Touch targets ≥ 44px on mobile operational surfaces (Door Mode, sticky CTAs).
4. Severity never colour-only — icon + text.
5. Landmarks: skip link, `<main>`, labelled regions for health/KPI boards.
6. Live regions polite for status; assertive only for critical failures.
7. Forms: associated labels, error summary + field errors.
8. Modals/drawers: focus trap, Escape, restore focus (governed shells).
9. `prefers-reduced-motion` respected for card lift, skeletons, celebrations.
10. No access by UI hiding alone — server-side ownership remains law.

---

# Motion rules

1. Motion supports hierarchy and feedback — not decoration noise.
2. Allowed: subtle card lift on hover (disabled under reduced motion); save/success micro-feedback; publish celebration (brief, dismissible).
3. Disallowed: endless animation on money/refund screens; parallax that harms Door Mode focus.
4. Loading: skeleton or governed saving/processing state — not blank freeze without message.
5. Duration short; easing calm; never blocks primary task > ~300ms without explicit progress.

---

# Trust rules

1. Money paths explain status: Connected / Needs attention / Incomplete + **why** + **fix**.
2. Refunds state irreversibility clearly.
3. Publish blocks list human reasons — not entity validation jargon.
4. Never expose order, attendee, payout, or payment data without access review.
5. Honest Pro: value-first upgrade; no deceptive gates (anti-metric in success metrics).
6. Fail loudly in product UX — organiser sees recovery, not silent failure.
7. Staff diagnostics stay staff-only.

---

# Community rules

1. MEL serves community and grassroots organisers first.
2. Discovery and belonging language on public surfaces; organiser tools stay warm but professional.
3. Hidden Gem = genuine discovery — not paid badge theatre.
4. Guide presence at decision points — not a mascot, not sarcasm.
5. Support copy matches Language Guide — no “vendor” to customers.
6. Celebrate local success (first publish, first door check-in) without corporate event-management coldness.

---

# Organiser psychology (permanent)

| Moment | Feeling to design for | Anti-pattern |
| --- | --- | --- |
| First open Dashboard | “I know what to do next” | Empty CMS dashboard |
| Create event | Confidence, not fear of breaking something | Silent draft resume |
| Add tickets | Pricing clarity | Product/variation forms |
| Publish | Earned pride | Opaque moderation jargon |
| Stripe | “Get paid” | Gateway configuration anxiety |
| Door Mode | Calm speed under pressure | Multi-app check-in chaos |
| Refund | Careful certainty | Hidden irreversible actions |
| Messages | Responsible reach | Accidental blast without audience clarity |

---

# Visual language (organiser console)

- Align to MEL brand tokens (`docs/brand/design-tokens.md`) via vendor theme tokens — **one token bridge**, no parallel palette.
- Warm cream / soft surfaces; primary purple for primary actions; coral/gold reserved for brand/discovery moments — not error.
- Radius, shadow, spacing from shared scales (VX2-02A card tokens retained).
- Public theme locks (hero featured-style, `.mel-card`) remain in `DESIGN_SYSTEM.md` — organiser console extends MEL, does not fork a second brand.

---

# States (canonical vocabulary)

| State | Meaning | Organiser cue |
| --- | --- | --- |
| Empty | No objects yet | Why + one CTA |
| Draft | Not live | Continue setup |
| Ready | Can publish / healthy payments | Primary forward CTA |
| Needs attention | Action required | Why + Fix |
| Blocked | Cannot proceed | Checklist reasons |
| Loading / Saving | Work in progress | Non-blocking status |
| Success | Goal achieved | Celebrate + next |
| Failed | Did not complete | Blame-free + retry/support |
| Sold out / Full | Capacity | Waitlist or stop sales |
| Offline / Unpublished | Not public | Take live / Publish |

---

# Definition of “belongs to MEL”

A feature belongs when it:

1. Uses Convergence IA placement.
2. Uses Language Guide terminology.
3. Uses layout intent containers.
4. Uses shared card/button/empty/status patterns.
5. Explains why + next step in every non-happy state.
6. Passes AA focus/contrast/target basics for its primary path.
7. Does not introduce a parallel Studio/Manager/Insights product name.

---

## Change control

- Product principles change only by explicit product decision — not by sprint convenience.
- New components require an audit note in `MEL_COMPONENT_AUDIT.md`.
- New organiser strings require Language Guide compliance.
- Design debt entries use `MEL_DESIGN_DEBT.md` priorities only for genuine issues.

**MyEventLane Product System is complete.**
