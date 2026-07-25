# Vendor Studio Visual Language — Implementation Guide

**Sprint:** VS1  
**Status:** ✅ Ready for **VL implementation** — design frozen (PO 2026-07-25)  
**Date:** 2026-07-25  
**Authority:** [03-option-b5.md](03-option-b5.md) · [07-workspace-zones.md](07-workspace-zones.md) · PDS · Catalogue  
**Rule:** One VL phase at a time; PO approval before the next phase. No CSS in this document.

---

## Purpose

Tell implementers **what changes visually**, **what stays frozen**, and **how** Hero, Mission Control, Launch Centre, forms, and cards express B.5 — without prescribing CSS. Composition follows **Workspace Zones** (first-class).

---

## Preconditions

1. Option **B.5** and **Workspace Zones** are PO-approved and frozen.
2. PDS remains authority for IA, components, and DoD.
3. No change to publish eligibility, Commerce, access, or routes in a “visual language” PR.
4. Cite PDS + Zones + B.5 + catalogue in the PR; include **Zone Gate** map before screenshots.
5. Build order: Choose zone → Reuse component → Apply B.5 → Implement.

---

## Frozen (do not redesign)

| Freeze | Meaning |
| --- | --- |
| Hero product contract | CTA ownership via `resolveAuthoritativePrimaryCta`; one primary; modes Continue / Publish / Share (and future Past when approved) |
| Mission Control structure | Home briefing model; checklist truth from readiness; not a second Launch Centre |
| Launch Centre architecture | Band order Ready → Checklist → Controls → Aftercare; no second Publish; Settings ≠ Launch |
| Shell IA / section nav model | Workspace Foundation |
| Brand token names | Extend expression; do not invent parallel colour systems |
| Enforcement | `PublishEligibilityEvaluator` / gates / CSRF / concurrency |

**Visual language changes presentation of frozen structures — not their product rules.**

---

## What changes visually (when implementation opens)

| Area | Visual change under B.5 |
| --- | --- |
| Canvas | Warm Cream continuity; reduce admin-grey feel |
| Elevation | Fewer shadows; soft panels with budget |
| Typography | Larger band titles; quieter meta |
| Spacing | More air between bands; Reading width for narrative |
| Borders | Softer, scarcer |
| Buttons | Hierarchy: one filled primary in Hero for go-live verbs |
| Chips | Calmer; always labelled |
| Illustrations | Empty/success only |
| Motion | Fast feedback; reduced-motion safe |

---

## Existing components — visual vs frozen

### Change visually (presentation layer)

| Component / surface | Visual work |
| --- | --- |
| Workspace shell background / content well | Cream canvas, gutters, air |
| Topbar / Hero chrome styling | Type weight, chip calm, button hierarchy, border softness |
| Mission Control Twig/CSS presentation | One soft panel; Soft Sky next-action strip; row density |
| Launch Centre / publishing hub presentation | Flat narrative bands; checklist panel; remove “settings dump” chrome when product allows |
| Publish success / handoff presentation | Editorial success + aftercare |
| Empty states across Studio | Helper tone + optional illustration |
| Vendor theme forms (inputs, labels, focus) | Soft inputs; coral focus; narrative grouping |
| Cards / panels / metric tiles | Elevation budget; kill nesting |
| Section nav active state | Quiet emphasis |
| Status chips | Soft semantic pairing |

### Stay frozen (behaviour / structure)

| Component / concern | Stay frozen |
| --- | --- |
| Hero CTA resolver & publish AJAX contract | Yes |
| Mission Control data model / readiness wiring | Yes |
| Launch Centre band order & dual-Publish ban | Yes |
| `data-mel-publish-*` endpoint behaviour | Yes |
| Eligibility / Stripe / vendor gates | Yes |
| Locked public theme heroes / public event cards | Out of scope (public brand) |
| Commerce checkout / payments UI | Out of scope unless separate ticket |

---

## How Hero changes visually

| Change | Do | Don’t |
| --- | --- | --- |
| Typography | Clear event title; quiet date/venue | Shrink title to fit more chrome junk |
| Primary CTA | One purple fill; loading label “Publishing…” | Add body Publish that matches weight |
| Secondary | Ghost/text View · Share when needed | Equal-weight button row of four fills |
| Status | Soft chip + plain language nearby | Chip rainbow |
| Surface | Soft separation from body | Heavy admin toolbar / dark bar |
| Motion | Fast hover/focus | Decorative hero animation |

**Product frozen:** which CTA appears; publish POST; share destination.

---

## How Mission Control changes visually

| Change | Do | Don’t |
| --- | --- | --- |
| Chrome | Single soft panel | Multi-card MC |
| Next action | Soft Sky strip inside panel | Nested “Next” card |
| Checklist | Scannable rows; fix links; blockers first | Raw severity wall |
| Illustration | None by default | Coach character every visit |
| Copy tone | Human (“Here’s what needs you”) | CMS jargon |

**Product frozen:** readiness source; Home placement; Stripe Connect exception rules already approved.

---

## How Launch Centre changes visually

| Change | Do | Don’t |
| --- | --- | --- |
| Ready band | Flat editorial title + sentence | Card wrapping the headline |
| Checklist | One panel or hairline list | Checklist + Settings form stack |
| Controls | Status text; Hero owns Publish | Second “Publish now” primary |
| Aftercare | Soft Sky; Share first | Boost as primary |
| Success | Editorial live + share | Fireworks blocking shell |

**Product frozen:** band order (`18`); state model (`17`); UX rules (`19`).  
**Product dependency:** removing embedded Settings form is a **composition** change already directed by Launch Centre docs — visual PR should align with that product direction, not reintroduce the dump.

---

## How forms change visually

| Change | Do | Don’t |
| --- | --- | --- |
| Layout | Label above; Reading/Form width | Full-bleed admin vertical tabs look |
| Grouping | Headings on canvas; soft panel for long groups | Every field in its own card |
| Focus | Coral-family visible ring | Outline removed |
| Errors | Icon + text; fail loud | Colour-only |
| Launch vs Settings | Launch progressive; Settings holds danger zone | Unpublish + visibility dominating Launch |

---

## How cards change visually

| Change | Do | Don’t |
| --- | --- | --- |
| Default | Flat content | Card every section |
| Soft panel | One job, shadow-sm or soft border | Panel inside panel |
| Metrics | ≤4 | KPI wallpaper |
| Lists | Table or single wrapper | Each cell elevated |
| Mobile rows | One-level stack cards OK | Double wrap |

---

## VL implementation phases (PO roadmap)

Design documentation is **complete**. Implement visual language in carefully scoped passes. Each phase: one visual layer only · preserve frozen behaviour · independently reviewable · **PO approval before next phase**.

| Phase | Scope | Status |
| --- | --- | --- |
| **VL-1** | Global Vendor Studio canvas, spacing, typography, elevation, and **page rhythm** (zone breath) | ✅ **Approved** (PO 2026-07-25) |
| **VL-2** | Hero visual refresh (**presentation only**) | ✅ **Approved** (PO 2026-07-25) |
| **VL-3** | Mission Control visual refresh (**presentation only**) | 🔒 **Frozen** (PO 2026-07-25) |
| **VL-4** | Launch Centre visual refresh (**presentation only**) | 🔒 **Frozen** (PO 2026-07-25) |
| **VL-5** | Launch Success + shared outcome panels first; then forms, tables, empty/error states | ✅ **May open** — precise boundary (PO 2026-07-25) |
| **VL-6** | Workspace-wide consistency audit and polish | Pending PO |

### VL-1 deliverables (runtime)

| Area | Where |
| --- | --- |
| Warm Cream canvas `#FFF7EE` | `tokens/_colors.scss` · `_root-tokens.scss` · shell main |
| Soft elevation budget | `tokens/_shadows.scss` · `--mel-shadow-*` · `--mel-es-panel-*` |
| Editorial type + meta | `tokens/_typography.scss` |
| Zone spacing tokens + rhythm | `tokens/_spacing.scss` · `layout/_zones.scss` |
| Soft Sky next-action strip | `.mel-event-studio-mission-control__next` · `.mel-zone__sky` |

**Frozen preserved:** Hero CTA resolver · Mission Control structure · Launch Centre composition · eligibility.

**VL-1 approved** — do not reopen canvas/token/zone-rhythm scope without DDR + PO.

### VL-2 deliverables (runtime)

| Area | Where |
| --- | --- |
| Identity chrome surface | `components/_mel-event-studio-hero.scss` |
| Editorial title + quiet meta | Same |
| Soft status chips | Same |
| Hero primary = brand purple | `.mel-event-studio-topbar__actions .mel-btn--primary` only |
| Coral focus on Hero actions | Same |
| Sticky mobile CTA wash → Warm Cream | Same |

**Frozen preserved:** Twig markup · `data-mel-*` · CTA resolver · publish POST · Share destinations.

**VL-2 approved** — do not reopen Hero presentation without DDR + PO.

### VL-3 deliverables (runtime)

| Area | Where |
| --- | --- |
| Guidance soft panel | `components/_mel-event-studio-mission-control.scss` |
| Soft Sky next-action strip | `.mel-event-studio-mission-control__next` |
| Checklist row rhythm | Same |
| Secondary CTA only (no second Publish) | `.mel-event-studio-mission-control__cta` |
| Coral focus | details summary + CTA |

**Frozen preserved:** Mission Control Twig · readiness ViewModel · `data-mel-mc-*` · Stripe Connect exception · progressive disclosure structure.

**VL-3 frozen** — do not reopen Mission Control presentation without DDR + PO. Supporting fixes (Hero specificity, mobile sticky &lt;768 off, Boost visual demotion) are presentation corrections only — Hero behaviour remains VL-2 frozen; Boost business logic unchanged.

### VL-4 deliverables (runtime)

| Area | Where |
| --- | --- |
| Work column flat on cream | `components/_mel-event-studio-launch-centre.scss` |
| Ready narrative editorial band | `.mel-launch-centre__ready` |
| One checklist soft panel | `.mel-launch-centre__checklist` |
| Quiet visibility disclosure | `.mel-launch-centre__visibility` |
| Soft Sky aftercare band | `.mel-launch-centre__after` |
| Publishing section card flatten | `[data-current-section-id='publishing'] .mel-es-card` |
| Wizard steps nav suppress in LC | `.mel-launch-centre__visibility-body .mel-event-studio-wizard-nav` |
| Coral focus on LC controls | Same |

**Frozen preserved:** Launch Centre Twig · band order · ViewModel · visibility form · checklist open logic · no second Publish · Hero CTA ownership.

**VL-4 frozen** — do not reopen Launch Centre presentation without DDR + PO. Wizard-nav suppress is accepted presentation containment; removing obsolete wizard classes from `EventLaunchVisibilityForm` is tech debt (not a VL-4 reopen).

### VL-5 boundary (PO 2026-07-25)

**Start with:** Launch Success Alternative A presentation + shared outcome-state visual language (success / error panels).  
**Then:** forms/field grouping · tables/operational lists · empty states — not a broad “polish every form” pass first.

**Must not change:** publishing enforcement · Hero CTA ownership · Mission Control · Launch Centre composition · Commerce · Workspace routes · lifecycle logic.

---

## Validation checklist (when code exists)

- [ ] 390px: one primary; Launch readable without Settings dump
- [ ] Desktop: MC one panel; ≤4 metrics
- [ ] Contrast AA on cream / Soft Sky / lavender
- [ ] Focus visible; reduced motion OK
- [ ] Publish still single Hero primary on Launch
- [ ] No Twig inventing eligibility
- [ ] Australian English copy
- [ ] PDS DoD / checklist `16` / `21`

---

## Explicit non-goals of visual language PRs

- Redesigning Mission Control product model  
- Redesigning Hero CTA logic  
- New readiness calculator  
- Scheduled publish  
- Public site hero/card system changes  
- Dark mode default  

---

## Document control

| Field | Value |
| --- | --- |
| Visual Language | v1 = Option B.5 |
| Approval | Product Owner |
| Amendments | DDR → review → version bump (align with PDS governance) |

---

## Status

B.5 + Workspace Zones **approved and frozen**. **VL-1…VL-4 approved/frozen**. **VL-5 may open** — begin with Launch Success Alternative A + shared outcome-state presentation (PO boundary 2026-07-25).
