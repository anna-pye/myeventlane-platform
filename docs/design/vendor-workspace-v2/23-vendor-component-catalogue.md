# Vendor Workspace — Component Catalogue

**Status:** Living catalogue (Product Owner endorsed 2026-07-25)  
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
| **Launch Success** | 🚧 In progress | Post-publish handoff | `20` Alternative A · Sprint 3C.2 | `buildPublishSuccessHandoff` · shell feedback (extend) |
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
| Phase A / Foundation | Shell + Hero CTA ownership | Frozen |
| Phase 2B | Mission Control compact progressive disclosure | Frozen |
| Sprint 3C.1 | Launch Centre composition | Frozen (composition) |
| Sprint 3C.2 (approved) | Launch Success Alternative A | In progress → freeze on PO accept |

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
| Implement Sprint **3C.2** Launch Success (Alternative A only) | Engineering (when PO says start) |
| On 3C.2 accept → set Launch Success to 🔒 Frozen | Catalogue update in same PR |
| After 3C.2 → promote Success Panel / Error Panel design if still needed | Product + design |

---

## Related

- [20-launch-success-experience.md](20-launch-success-experience.md) — Alternative A (approved for 3C.2)  
- [22-sprint-3c1-launch-centre-implementation.md](22-sprint-3c1-launch-centre-implementation.md)  
- [PDS 05 Component Library](../vendor-studio/05-component-library.md) — behavioural contracts  
