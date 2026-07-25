# Vendor Studio Product Design System (PDS) — Changelog

Also known as: Vendor Studio Design Operating System.

All notable changes to this Product Design System are recorded here.

Versioning follows the Versioning Policy in [README.md](README.md#versioning-policy).

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
