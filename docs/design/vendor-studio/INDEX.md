# Vendor Studio Product Design System (PDS) — Index

**Also known as:** Vendor Studio Design Operating System  
**Version:** 1.0.3
**Status:** **FROZEN**  
**Authority:** Required for all Vendor Studio implementation work  
**Landing page for contributors**

---

**Repository-wide parent authority:** [Organiser Manifesto](../../governance/00-organiser-manifesto.md) → [Product Constitution](../../governance/01-product-constitution.md) → [PDR-001 canonical hierarchy](../../product-decisions/PDR-001-governance-baseline-authority.md). The stack below is the local Vendor Studio build stack and does not outrank repository-wide product governance.

## Mission

Help organisers create successful events with calm confidence — from first draft to door check-in to payout.

Vendor Studio is the event operating system organisers enjoy opening every day.

---

## Start here

| Step | Document |
| --- | --- |
| 1 | [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) |
| 2 | [01-vendor-studio-vision.md](01-vendor-studio-vision.md) |
| 3 | [ARCHITECTURE.md](ARCHITECTURE.md) |
| 4 | [QUICK_REFERENCE.md](QUICK_REFERENCE.md) |
| 5 | [CONTRIBUTING.md](CONTRIBUTING.md) |
| 6 | [appendices/A01-glossary.md](appendices/A01-glossary.md) |

Full reading paths: [README.md](README.md) · [CONTRIBUTING.md](CONTRIBUTING.md)

---

## Complete design system (v1 — FROZEN)

PO-approved stack. **No further design expansion** — implement against this stack (VL phases).

| Order | Layer | Role | Home |
| --- | --- | --- | --- |
| 1 | **Product Design System** | What the product is · behaviour · IA | This pack (`docs/design/vendor-studio/`) |
| 2 | **Workspace Zones** | How pages are composed (design test) | [`../vendor-studio-visual/07-workspace-zones.md`](../vendor-studio-visual/07-workspace-zones.md) |
| 3 | **Visual Language (B.5)** | How it looks | [`../vendor-studio-visual/03-option-b5.md`](../vendor-studio-visual/03-option-b5.md) |
| 4 | **Component Catalogue** | What may change | [`../vendor-workspace-v2/23-vendor-component-catalogue.md`](../vendor-workspace-v2/23-vendor-component-catalogue.md) |
| 5 | **Implementation** | Faithful delivery (VL-1…VL-6) | [`../vendor-studio-visual/06-implementation-guide.md`](../vendor-studio-visual/06-implementation-guide.md) |

**Build rule:** Choose zone → reuse component → apply B.5 → implement.

**Zone Gate:** Workspace PRs must include a zone map before screenshots ([16](16-design-review-checklist.md) · [21](21-definition-of-done.md)).

---

## Document categories

### Governance

| Document | Role |
| --- | --- |
| [ADR-0001 Design Authority](decisions/ADR-0001-design-authority.md) | Constitution · precedence |
| [README.md](README.md) | Purpose · map · versioning · governance summary |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to contribute and review |
| [23-governance-lifecycle.md](23-governance-lifecycle.md) | Idea → Release lifecycle |
| [21-definition-of-done.md](21-definition-of-done.md) | Completion gates |
| [16-design-review-checklist.md](16-design-review-checklist.md) | Mandatory PR checklist |
| [22-design-system-health.md](22-design-system-health.md) | Executive health assessment |
| [CHANGELOG.md](CHANGELOG.md) | Version history |

### Core design

| Document | Owns |
| --- | --- |
| [01 Vision](01-vendor-studio-vision.md) | Mission · principles · Golden Rule · Three Questions |
| [02 Information architecture](02-information-architecture.md) | Navigation · hierarchy |
| [03 Layout system](03-layout-system.md) | Shell · intents structure |
| [04 Design language](04-design-language.md) | MEL extension philosophy |
| [11 Design tokens](11-design-tokens.md) | Visual token source of truth |
| [14 Visual identity](14-visual-identity.md) | Feel · differentiation |
| [15 Copywriting guide](15-copywriting-guide.md) | Voice · terminology |

### Product surfaces

| Document | Owns |
| --- | --- |
| [12 Dashboard philosophy](12-dashboard-philosophy.md) | Dashboard mission |
| [13 Event Workspace philosophy](13-event-workspace-philosophy.md) | Workspace specification |
| [06 Workspace patterns](06-workspace-patterns.md) | Hub composition patterns |
| [05 Component library](05-component-library.md) | Component contracts |
| [Workspace component catalogue](../vendor-workspace-v2/23-vendor-component-catalogue.md) | Freeze / status ledger (not a style guide) |
| [Workspace Zones](../vendor-studio-visual/07-workspace-zones.md) | **First-class** composition · Identity → Guidance → Work → Outcome |
| [Visual Language B.5](../vendor-studio-visual/03-option-b5.md) | Vendor Studio look · FROZEN with Zones |
| [07 Interaction guidelines](07-interaction-guidelines.md) | Behaviour |
| [08 Mobile guidelines](08-mobile-guidelines.md) | Mobile ops |
| [19 Anti-patterns](19-anti-patterns.md) | What not to do |

### Implementation guidance

| Document | Owns |
| --- | --- |
| [09 Drupal mapping](09-drupal-mapping.md) | Theme · routes · Commerce boundaries |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Visual overview |
| [10 Roadmap](10-roadmap.md) | Phased application |
| [17 Design maturity model](17-design-maturity-model.md) | Levels 1–5 |
| [18 Product success metrics](18-product-success-metrics.md) | Success measures |

### Decision Records

| Record | Decision |
| --- | --- |
| [ADR-0001](decisions/ADR-0001-design-authority.md) | Design authority constitution |
| [DDR-001](decisions/DDR-001-shell-navigation.md) | Single global shell |
| [DDR-002](decisions/DDR-002-event-workspace.md) | One Event Workspace |
| [DDR-003](decisions/DDR-003-layout-intents.md) | Layout intents |
| [DDR-004](decisions/DDR-004-component-philosophy.md) | Component philosophy |
| [DDR-005](decisions/DDR-005-mobile-first.md) | Mobile-first ops |
| [DDR-006](decisions/DDR-006-payments-hub.md) | Payments hub |
| [DDR-007](decisions/DDR-007-marketing-analytics-separation.md) | Marketing ≠ Analytics |
| [DDR-008](decisions/DDR-008-canonical-event-workspace.md) | Canonical Event Workspace shell · transitional `/studio` |
| [DDR-009](decisions/DDR-009-workspace-navigation.md) | Workspace section nav · Attendees before Orders |

### Appendices

| Document | Role |
| --- | --- |
| [A01 Glossary](appendices/A01-glossary.md) | Vocabulary |
| [A02 vs Humanitix](appendices/A02-design-principles-vs-humanitix.md) | Philosophy contrast |
| [A03 Parking lot](appendices/A03-future-ideas-parking-lot.md) | Excluded-from-v1 ideas |
| [20 v2 vision](20-vendor-studio-v2-vision.md) | Long-term non-binding vision |

### Culture and quick aids

| Document | Role |
| --- | --- |
| [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) | One-page culture |
| [QUICK_REFERENCE.md](QUICK_REFERENCE.md) | One-/two-page implementation aid |

### Definition of Done · Roadmap

| Document | Role |
| --- | --- |
| [21-definition-of-done.md](21-definition-of-done.md) | Feature completion gates |
| [10-roadmap.md](10-roadmap.md) | Implementation sequencing |

---

## Golden Rule (reminder)

If the organiser cannot answer “What should I do next?” within five seconds, the screen has failed.

---

## Pack home

[README.md](README.md) — full purpose, freeze policy, versioning, governance.

Every Vendor Studio PR: cite PDS docs · complete DoD (`21`) + checklist (`16`) · prefer label `design-system`.
