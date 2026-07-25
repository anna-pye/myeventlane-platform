# Vendor Studio Product Design System (PDS)

**Also known as:** Vendor Studio Design Operating System  

| Field | Value |
| --- | --- |
| **Version** | **1.0** |
| **Status** | **FROZEN** |
| **Released** | 2026 |
| **Authority** | Required for all Vendor Studio implementation work |
| **Language** | Australian English |
| **Landing index** | [INDEX.md](INDEX.md) |

---

## Purpose

This **Product Design System (PDS)** is the permanent product standard for **Vendor Studio** — MyEventLane’s organiser console.

It exists so Vendor Studio can grow for years without losing its identity.

Every redesign of Dashboard, Events, Event Workspace, Orders, Attendees, Messages, Payments, Analytics, Marketing, and Settings must begin by citing the relevant documents in this pack — not by inventing new patterns.

**Constitution:** [decisions/ADR-0001-design-authority.md](decisions/ADR-0001-design-authority.md)  
**Contributor guide:** [CONTRIBUTING.md](CONTRIBUTING.md)  
**Architecture overview:** [ARCHITECTURE.md](ARCHITECTURE.md)

---

## Vision

Vendor Studio is the event operating system organisers enjoy opening every day.

It helps organisers create, promote, and run successful events with calm confidence — from first draft to door check-in to payout — without ever forcing them to think about Drupal, Commerce stores, products, variations, or CMS vocabulary.

**Success criterion:** Contributors ship coherent Vendor Studio surfaces by following this PDS, Decision Records, Definition of Done, and the review checklist.

---

## Freeze policy (v1.0)

This standard is **frozen**.

- No continual ad-hoc edits
- Changes require: **DDR → Review → Approval → Version update** ([23-governance-lifecycle.md](23-governance-lifecycle.md))
- Compatible clarifications may ship as **1.x** with Design Authority approval
- Breaking philosophy/IA changes require **2.0** and Product Owner approval

---

## Mandatory PR references

Every Vendor Studio implementation PR must include:

```markdown
## Vendor Studio Design System References
This implementation follows:
- 02-information-architecture.md
- 05-component-library.md
- 11-design-tokens.md
- 16-design-review-checklist.md
- 21-definition-of-done.md
- ADR-0001
- DDR-003
```

(Adjust the list to the documents actually followed.)

Repository aids:

- [`.github/PULL_REQUEST_TEMPLATE.md`](../../../.github/PULL_REQUEST_TEMPLATE.md)
- Prefer GitHub label: **`design-system`**

---

## Reading order

### For every new contributor

1. [INDEX.md](INDEX.md) — one-page map  
2. [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) — culture  
3. [01-vendor-studio-vision.md](01-vendor-studio-vision.md) — mission, principles, Golden Rule  
4. [ARCHITECTURE.md](ARCHITECTURE.md) — product shape  
5. [QUICK_REFERENCE.md](QUICK_REFERENCE.md) — day-to-day aid  
6. [appendices/A01-glossary.md](appendices/A01-glossary.md) — vocabulary  
7. [CONTRIBUTING.md](CONTRIBUTING.md) — how to change and cite  

Role-based paths: [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Document map

### Governance

| Doc | Owns |
| --- | --- |
| [ADR-0001](decisions/ADR-0001-design-authority.md) | Constitution, precedence, ownership |
| [23 Governance lifecycle](23-governance-lifecycle.md) | Idea → Release process |
| [21 Definition of Done](21-definition-of-done.md) | Completion gates |
| [16 Design review checklist](16-design-review-checklist.md) | Detailed YES/NO PR checklist |
| [22 Design system health](22-design-system-health.md) | Executive assessment |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Contributor & reviewer practice |
| [CHANGELOG.md](CHANGELOG.md) | Version history |

### Core authority (01–10)

| Doc | Owns (authoritative home) |
| --- | --- |
| [01 Vision](01-vendor-studio-vision.md) | Mission, Ten Design Principles, Golden Rule, Three Question Framework, personality |
| [02 Information architecture](02-information-architecture.md) | Navigation philosophy, global shell IA, workspace hierarchy |
| [03 Layout system](03-layout-system.md) | Shell structure, breakpoints, layout intents (structure) |
| [04 Design language](04-design-language.md) | Relationship to public MEL; high-level visual rules |
| [05 Component library](05-component-library.md) | Component contracts |
| [06 Workspace patterns](06-workspace-patterns.md) | Page-level composition patterns per hub |
| [07 Interaction guidelines](07-interaction-guidelines.md) | Hover, focus, autosave, loading, validation, keyboard |
| [08 Mobile guidelines](08-mobile-guidelines.md) | Mobile-first ops rules, Door Mode mobile |
| [09 Drupal mapping](09-drupal-mapping.md) | Theme regions, classes, Commerce boundaries, libraries |
| [10 Roadmap](10-roadmap.md) | Phased application of this PDS |

### Product philosophy & standards (11–23)

| Doc | Owns (authoritative home) |
| --- | --- |
| [11 Design tokens](11-design-tokens.md) | Definitive visual token specification |
| [12 Dashboard philosophy](12-dashboard-philosophy.md) | What Dashboard exists to accomplish |
| [13 Event Workspace philosophy](13-event-workspace-philosophy.md) | Per-event application specification |
| [14 Visual identity](14-visual-identity.md) | How Vendor Studio should feel |
| [15 Copywriting guide](15-copywriting-guide.md) | Voice, tone, terminology translation |
| [17 Design maturity model](17-design-maturity-model.md) | Levels 1–5 |
| [18 Product success metrics](18-product-success-metrics.md) | Success measures |
| [19 Anti-patterns](19-anti-patterns.md) | Harmful patterns and replacements |
| [20 Vendor Studio v2 vision](20-vendor-studio-v2-vision.md) | Non-binding long-term ideas |
| [21 Definition of Done](21-definition-of-done.md) | Completion gates |
| [22 Design system health](22-design-system-health.md) | Health assessment |
| [23 Governance lifecycle](23-governance-lifecycle.md) | Evolution process |

### Culture and navigation aids

| Doc | Role |
| --- | --- |
| [INDEX.md](INDEX.md) | Contributor landing page |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Visual product / PDS overview |
| [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) | One-page culture document |
| [QUICK_REFERENCE.md](QUICK_REFERENCE.md) | One-/two-page implementation guide |

### Decision Records

| Record | Decision |
| --- | --- |
| [ADR-0001](decisions/ADR-0001-design-authority.md) | Design authority constitution |
| [DDR-001](decisions/DDR-001-shell-navigation.md) | Single global shell navigation |
| [DDR-002](decisions/DDR-002-event-workspace.md) | One Event Workspace |
| [DDR-003](decisions/DDR-003-layout-intents.md) | Intent-based content containers |
| [DDR-004](decisions/DDR-004-component-philosophy.md) | Extend MEL components; cards earn their size |
| [DDR-005](decisions/DDR-005-mobile-first.md) | Mobile as first-class ops surface |
| [DDR-006](decisions/DDR-006-payments-hub.md) | Payments hub |
| [DDR-007](decisions/DDR-007-marketing-analytics-separation.md) | Marketing separate from Analytics |
| [DDR-008](decisions/DDR-008-canonical-event-workspace.md) | Canonical Event Workspace shell · transitional `/studio` |
| [DDR-009](decisions/DDR-009-workspace-navigation.md) | Workspace section nav · Attendees before Orders |

### Appendices

| Doc | Role |
| --- | --- |
| [A01 Glossary](appendices/A01-glossary.md) | Shared vocabulary |
| [A02 vs Humanitix](appendices/A02-design-principles-vs-humanitix.md) | Philosophy comparison (not imitation) |
| [A03 Parking lot](appendices/A03-future-ideas-parking-lot.md) | Ideas excluded from current roadmap |

---

## Authority rules

1. **One authoritative home per concept.**
2. **Precedence is constitutional** ([ADR-0001](decisions/ADR-0001-design-authority.md)). No implementation may contradict higher-order documents.
3. **Principles live in Vision (01).**
4. **Tokens live in 11.**
5. **Dashboard depth in 12; Workspace depth in 13.**
6. **Success metrics in 18.**
7. **Anti-patterns in 19.**
8. **DoD gates in 21; checklist detail in 16.**
9. **v2 / parking in 20 and A03** — must not contaminate [10-roadmap.md](10-roadmap.md).

---

## Versioning Policy

```text
Draft
  ↓
RC (Release Candidate)
  ↓
Frozen v1.0   ← current
  ↓
Minor revision (1.x)
  ↓
Major revision (2.0)
```

| Stage | Meaning | Who decides |
| --- | --- | --- |
| **Draft** | Early incomplete pack | Design Authority |
| **RC** | Complete candidate for freeze | Design Authority + Product Owner |
| **Frozen v1.0** | Published product standard; tagged; cited by every implementation PR | Product Owner + Design Authority (+ Technical Authority for platform contracts) |
| **Minor revision (1.x)** | Compatible clarifications, examples, non-breaking tokens | Design Authority; Technical Authority if mapping/contracts |
| **Major revision (2.0)** | Breaking philosophy or IA; new DDR set | Product Owner + Design Authority + Technical Authority |

---

## Document Governance

### Roles

| Role | Responsibility |
| --- | --- |
| **Product Owner** | Organiser outcomes; roadmap; scope; major bumps; freeze declaration |
| **Design Authority** | Experience consistency; DDR approval; pack integrity |
| **Technical Authority** | Drupal/Commerce feasibility; mapping honesty; access, payments, cache, security |

### Review cadence

| Cadence | Activity |
| --- | --- |
| Every Vendor Studio PR | Cite PDS docs; complete [21](21-definition-of-done.md) + [16](16-design-review-checklist.md); label `design-system` |
| Each delivery phase | Confirm phase matches PDS; update [09](09-drupal-mapping.md) |
| Quarterly | Health review ([22](22-design-system-health.md)) |
| On major product shift | Re-open DDRs; version bump deliberately |

### How DDRs are approved

1. Draft DDR → 2. Design Authority review → 3. Technical Authority if platform risk → 4. Product Owner if scope/IA weight → 5. Status **Accepted** + CHANGELOG → 6. Implementation may proceed.

Constitutional changes amend [ADR-0001](decisions/ADR-0001-design-authority.md).

### How future documents are added

Confirm missing home → lifecycle ([23](23-governance-lifecycle.md)) → standard front/closing matter → INDEX + README map → avoid duplicates.

---

## Relationship with other systems

| System | Relationship |
| --- | --- |
| **Drupal 11** | Maps via [09](09-drupal-mapping.md). Business logic stays out of Twig. |
| **Drupal Commerce 3** | Commerce-authoritative states; organiser-facing language; honesty mandatory. |
| **MEL Brand** (`docs/brand/`) | Public brand; PDS extends for operations. |
| **Vendor theme** | Runtime under `.mel-vendor`; implements PDS; does not redefine it. |
| **Public theme** + `DESIGN_SYSTEM.md` | Discovery UI; do not fork into ops chrome. |
| **VX2 docs** | Delivery history; **PDS wins** on design philosophy; confirm runtime in repository. |

---

## Roadmap stance

- **Now:** PDS **v1.0 FROZEN** — governing authority.  
- **Next:** Implementation against the standard, starting with Dashboard (wireframes before code).  
- **Measure:** [18](18-product-success-metrics.md) · [17](17-design-maturity-model.md) · phases in [10](10-roadmap.md).

---

## Naming

| Prefer in new writing | Accepted alias |
| --- | --- |
| **Vendor Studio Product Design System (PDS)** | Vendor Studio Design Operating System |

“Operating System” describes what Vendor Studio *does* for organisers.  
“Product Design System” describes what *this pack* is.

---

## Explicit non-goals of documentation-only work

- No PHP, Twig, SCSS, JavaScript, YAML config, or theme implementation in docs-only sprints  
- No imitation of competitor chrome  
- No staff-only tooling in organiser IA  
