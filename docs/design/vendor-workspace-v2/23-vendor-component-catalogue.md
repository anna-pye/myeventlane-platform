# Vendor Workspace — Component Catalogue

**Status:** 🔒 Freeze ledger (Product Owner endorsed 2026-07-25; VS1.1 Zones + B.5 frozen)  
**Authority:** Status & freeze board for Event Workspace / Vendor Studio surfaces  
**Not:** A style guide, token book, or second PDS component library  

**Contracts live in:** [PDS 05 — Component Library](../vendor-studio/05-component-library.md)  
**This document answers:** *May I change this component?*

---

## How to use

| Status | Meaning | Rule |
| --- | --- | --- |
| 🔒 **Frozen** | PO-approved; composition/contracts locked | Do not redesign. Bugfixes and a11y-only only. Unfreeze requires PO + note here. |
| 🔒 **Reusable** | Stable pattern; extend, don’t fork | Reuse existing Twig/SCSS/VM contracts. New variants need a catalogue row. |
| 🚧 **In progress** | Active sprint slice | Implement only against approved design docs for that slice. |
| 🚧 **Next** | Queued; design exists or is imminent | Do not invent ad-hoc replacements ahead of the sprint. |
| ✅ **Implemented; acceptance pending** | Repository implementation and contract evidence exist; final experience acceptance is incomplete | Do not mark frozen or expand the design. Complete bounded acceptance and record the result. |
| 🧪 **Experimental** | Local / unapproved | Must not ship to organisers without catalogue promotion. |
| ⛔ **Deprecated** | Do not use on new surfaces | Prefer listed replacement. |

**Precedence:** Catalogue freeze **overrides** opportunistic polish in PRs. PDS philosophy still applies; this board is the freeze ledger for Workspace v2.

---

## Catalogue

| Component | Status | Surface / owner | Design refs | Runtime seed |
| --- | --- | --- | --- | --- |
| **Hero** (event chrome + primary CTA) | 🔒 Frozen | Workspace shell | `09` · `03` · PDS 05 §1 | `mel-event-studio-topbar` · `resolveAuthoritativePrimaryCta` |
| **Mission Control** | 🔒 Frozen | Overview / chrome card | `10` · `04` · Phase 2B | `mel-event-studio-mission-control` · `EventWorkspaceOverviewBuilder` |
| **Launch Centre** | 🔒 Frozen (composition) | Publishing section | `18` · `22` · Sprint 3C.1 | `mel-event-studio-launch-centre` · `buildPublishingHub` |
| **Launch Success** | ✅ Implemented; acceptance pending | Post-publish handoff | `20` Alternative A · Sprint 3C.2 | `buildPublishSuccessHandoff` · shell feedback |
| **Ticket workspace hierarchy** | ✅ Implemented; acceptance pending | Tickets section | PDS · Workspace Zones · B.5 | ticket forms and app · `TicketTierDeletionGuard` · ticket hierarchy SCSS |
| **Launch checklist** | 🔒 Reusable | Launch Centre (and aligned lists) | `18` · `17` | Launch Centre checklist band; readiness items from facade/service |
| **Workspace section header** | 🔒 Reusable | Section chrome | PDS 05 · `09` | `mel_event_studio_section` / workspace section wrappers |
| **Empty state** | 🔒 Reusable | Any section | PDS 05 · empty-state builder | `EventStudioEmptyStateBuilder` |
| **Success panel** | 🚧 Next | Inline success (non-Launch) | `19` · `20` | Prefer Launch Success A pattern; generalise after 3C.2 |
| **Error panel** | 🚧 Next | Inline failure / recovery | `19` | Publish AJAX codes exist; dedicated panel composition deferred |
| **Hero publish hint** | 🔒 Reusable | Launch Centre / MC publish mode | `18` · MC hint pattern | “Use Publish… in the header” — never a second Publish button |
| **Visibility disclosure** | 🔒 Reusable | Launch Centre band | `18` · 3C.1 | `EventLaunchVisibilityForm` in `<details>` |
| **After you publish** (info band) | 🔒 Reusable | Launch Centre | `18` · 3C.1 | Informational only — not Launch Success |
| **Primary CTA (authoritative)** | 🔒 Frozen (resolver) | Hero | `17` | `resolveAuthoritativePrimaryCta` — single owner |
| **Readiness / eligibility** | 🔒 Frozen (services) | Backend truth | `15` | `EventReadinessService` · `PublishEligibilityEvaluator` — no parallel calculators |

---

## Freeze ledger (Workspace v2)

| When | What | Decision |
| --- | --- | --- |
| Phase A / Foundation | Shell + Hero CTA ownership | 🔒 Frozen |
| Phase 2B | Mission Control compact progressive disclosure | 🔒 Frozen |
| Sprint 3C.1 | Launch Centre composition | 🔒 Frozen (composition) |
| Sprint 3C.2 (approved) | Launch Success Alternative A | ✅ Implemented; acceptance pending → freeze only on PO accept |
| VS1 / VS1.1 (PO 2026-07-25) | **Visual Language B.5** + **Workspace Zones** | 🔒 Frozen — no further design expansion; VL implementation next |
| VS1.1 governance | **Zone Gate** on Workspace PRs | 🔒 Required — zone map before screenshots |
| **VL-1** (PO 2026-07-25) | Global canvas, spacing, typography, elevation, zone rhythm | ✅ Approved — theme tokens + `layout/_zones.scss` |
| **VL-2** (PO 2026-07-25) | Hero (Identity) visual refresh | ✅ Approved — `components/_mel-event-studio-hero.scss` |
| **VL-3** (PO 2026-07-25) | Mission Control (Guidance) visual expression | 🔒 Frozen — `components/_mel-event-studio-mission-control.scss` |
| **VL-3 supporting** (PO 2026-07-25) | Hero specificity · mobile sticky &lt;768 off · Workspace Boost visual demotion | 🔒 Frozen as presentation corrections — does **not** reopen VL-2 · Boost business logic unchanged |
| **VL-4** (PO 2026-07-25) | Launch Centre (Work) visual expression | 🔒 Frozen — `components/_mel-event-studio-launch-centre.scss` |
| **VL-4 approved set** | Warm Cream narrative · one checklist surface · flat visibility · Soft Sky aftercare · wizard-nav suppress in LC · Hero sole dominant publish action | 🔒 Frozen with VL-4 — do not reopen presentation |
| **VL-5A** | Launch Success Alternative A | ✅ Implemented; visual, mobile and assistive-technology acceptance pending |
| **VL-5B** | Shared outcome-state presentation | ✅ Implemented; acceptance pending |
| **Ticket workspace refinement** | Ticket hierarchy and protected tier deletion | ✅ Merged in `0be717f24`; organiser-experience acceptance pending |

**Also frozen (PO):** Workspace Foundation · Mission Control structure · Launch Centre Composition · this Catalogue · Visual Language · Workspace Zones · **VL-1** · **VL-2** · **VL-3** · **VL-4** presentation baselines (Hero behaviour remains VL-2; MC structure/ViewModel remain frozen; LC composition remains frozen).

**VL-4 tech debt (non-blocking — do not reopen VL-4):** Remove obsolete wizard presentation classes from `EventLaunchVisibilityForm`; nested/duplicate landmarks Twig a11y; product revisit of aftercare preview while blocked (ViewModel/content).

---

## Rules for new components

1. **Search this catalogue** before adding a Twig/SCSS pattern.  
2. If a frozen component almost fits — **extend presentation props**, do not fork.  
3. New organiser-facing chrome needs a **catalogue row** in the same PR as the first implementation.  
4. Status promotions (🚧 → 🔒) require **Product Owner** acknowledgement (chat or review note).  
5. Do not invent a parallel “success toast system” while Success Panel is 🚧 Next — follow `19` / `20`.

---

## Explicit non-goals of this catalogue

- Colour tokens, type scales, or button recipes → PDS `11` / `05`  
- Full Drupal field mapping → `05-drupal-mapping.md` / PDS `09`  
- Accepting DDRs or unfreezing PDS 1.0 → ADR/DDR process  

---

## Next actions

| Action | Owner |
| --- | --- |
| **VL-1** approved — baseline locked | — |
| **VL-2** approved — Hero presentation locked | — |
| **VL-3** Mission Control presentation — 🔒 Frozen | — |
| **VL-4** Launch Centre presentation — 🔒 Frozen | — |
| Complete bounded acceptance for Launch Success, shared outcome states and the ticket workspace refinement | Product Owner + review |
| Record desktop, mobile, keyboard, reduced-motion and relevant assistive-technology evidence | Review |
| On Product Owner accept → set accepted rows to 🔒 Frozen | Catalogue update |
| If acceptance identifies a defect → create a bounded defect brief; do not redesign in the acceptance pass | Product + Engineering |
| After VL phases → polish only; **no new design packs** without DDR + PO | All |

---

## Related

- [Workspace Zones](../vendor-studio-visual/07-workspace-zones.md) — first-class composition · Zone Gate  
- [Visual Language B.5](../vendor-studio-visual/03-option-b5.md) — look  
- [20-launch-success-experience.md](20-launch-success-experience.md) — Alternative A (approved for 3C.2)  
- [22-sprint-3c1-launch-centre-implementation.md](22-sprint-3c1-launch-centre-implementation.md)  
- [24-current-state-catalogue-reconciliation.md](24-current-state-catalogue-reconciliation.md)
- [Vendor Studio acceptance initiative](../../product/initiatives/TRACE-NOW-02-vendor-studio-acceptance.md)
- [PDS 05 Component Library](../vendor-studio/05-component-library.md) — behavioural contracts  
