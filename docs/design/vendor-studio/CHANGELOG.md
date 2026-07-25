# Vendor Studio Product Design System (PDS) — Changelog

Also known as: Vendor Studio Design Operating System.

All notable changes to this Product Design System are recorded here.

Versioning follows the Versioning Policy in [README.md](README.md#versioning-policy).

---

## [1.0.2] — 2026-07-25 — Workspace Zones + Visual Language freeze

### Accepted (Product Owner)

- **Workspace Zones** elevated to a **first-class** Vendor Studio design principle ([`../vendor-studio-visual/07-workspace-zones.md`](../vendor-studio-visual/07-workspace-zones.md)).
- **Visual Language B.5** approved and frozen as Vendor Studio Visual Language v1.
- **Zone Gate** — every Event Workspace PR must include Identity / Guidance / Work / Outcome map **before screenshots** ([16](16-design-review-checklist.md) · [21](21-definition-of-done.md)).
- Build order: PDS → Workspace Zones → Visual Language → Component Catalogue → Implementation ([DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) · [INDEX.md](INDEX.md)).
- **VL-1 approved** — global canvas, spacing, typography, elevation, zone rhythm (`myeventlane_vendor_theme` tokens + `layout/_zones.scss`).
- **VL-2 approved** — Hero Identity presentation (`components/_mel-event-studio-hero.scss`).
- **VL-3 approved · frozen** — Mission Control Guidance presentation (`components/_mel-event-studio-mission-control.scss`). Supporting presentation corrections (do not reopen VL-2; Boost business logic unchanged): Hero primary specificity; mobile sticky disabled below 768px; Workspace Boost banner/CTA demotion + hidden sidebar overlay correction.
- **VL-4 approved · frozen** — Launch Centre Work presentation (`components/_mel-event-studio-launch-centre.scss`): Warm Cream editorial narrative; one checklist surface; flattened visibility; Soft Sky aftercare; wizard-step nav suppress in LC; Hero sole visually dominant publish action. Wizard-class cleanup on visibility form = tech debt (non-blocking).
- **VL-5 may open** — start with Launch Success Alternative A + shared outcome-state presentation; then forms/tables/empty/error panels. Must not reopen Hero CTA, Mission Control, Launch Centre composition, eligibility, Commerce, or routes.

### Freeze set (no further design expansion)

Workspace Foundation · Mission Control structure · Launch Centre Composition · Component Catalogue · Visual Language B.5 · Workspace Zones · VL-1 baseline · VL-2 Hero presentation · VL-3 Mission Control presentation · VL-4 Launch Centre presentation.

### Notes

- Compatible governance clarification under PDS **1.0** freeze (implementation governance). Next work is **VL-5…VL-6** presentation implementation only, within PO VL-5 boundary.

---

## [1.0.1] — 2026-07-25 — Foundation governance

### Accepted

- **DDR-008** — Canonical Event Workspace (path & shell): organiser product on `mel_event_studio_workspace`; transitional `/studio` paths; path unification deferred; Mission Control + Hero CTA contract documented against shipped Foundation.
- **DDR-009** — Workspace Navigation: `EventStudioSectionManager` sole organiser nav source; Attendees before Orders; Door Mode under Attendees (direction); Home/Details/Images labels match runtime.

### Notes

- PDS design philosophy remains **1.0 FROZEN**; this entry records accepted DDRs for Vendor Workspace Foundation merge preparation.
- Path rename (`/vendor/events/{id}` without `/studio`) and Door Mode chrome nesting remain post-Foundation work.

---

## [1.0] — 2026-07-25 — FROZEN

### Declared

**Vendor Studio Product Design System (PDS) v1.0** is frozen as the governing product standard for all Vendor Studio implementation work.

| Field | Value |
| --- | --- |
| **Product name** | Vendor Studio Product Design System (PDS) |
| **Also known as** | Vendor Studio Design Operating System |
| **Version** | 1.0 |
| **Status** | **FROZEN** |
| **Released** | 2026 |
| **Authority** | Required for all Vendor Studio implementation work |

### Freeze rules

- No continual ad-hoc edits to the frozen standard
- Future changes follow: DDR → Review → Approval → Version update ([23](23-governance-lifecycle.md))
- Every Vendor Studio implementation PR must cite PDS documents (see [CONTRIBUTING.md](CONTRIBUTING.md) and repository PR template)
- Prefer label `design-system` on Vendor Studio PRs

### Includes

- Full RC1 content (vision through v2 parking)
- RC1.1 governance completion (ADR-0001, DoD, lifecycle, CONTRIBUTING, ARCHITECTURE, INDEX, health, DDR-006/007)
- Canonical naming: **Product Design System (PDS)**

---

## [RC1.1] — 2026-07-25

### Added — governance completion

- ADR-0001 — constitutional design authority and document precedence
- 21-definition-of-done.md — mandatory feature completion gates
- 22-design-system-health.md — executive health and publication readiness
- 23-governance-lifecycle.md — Idea → Release lifecycle
- CONTRIBUTING.md — contributor and reviewer guide
- ARCHITECTURE.md — visual OS and product overview
- INDEX.md — contributor landing page
- DDR-006 — Payments hub
- DDR-007 — Marketing ≠ Analytics

### Changed

- README Versioning Policy and Document Governance expanded
- Pack positioned as publishable product standard

---

## [RC1] — 2026-07-25

### Added

- README, QUICK_REFERENCE, DESIGN_PRINCIPLES_POSTER
- Documents 11–20
- DDR-001 through DDR-005
- Appendices A01–A03
- RC1 updates to documents 01–10

---

## [0.9] — 2026-07 (initial draft)

### Added

- Documents 01–10 initial set

---

## Future revisions

| Version | Trigger examples |
| --- | --- |
| 1.x | Compatible clarifications, examples, non-breaking token additions, mapping updates after implementation |
| 2.0 | Breaking IA or philosophy change; new DDR set; Product Owner approval |

Out of current scope: [20-vendor-studio-v2-vision.md](20-vendor-studio-v2-vision.md) · [appendices/A03-future-ideas-parking-lot.md](appendices/A03-future-ideas-parking-lot.md).
