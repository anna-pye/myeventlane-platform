# Vendor Studio — Design Governance Lifecycle

**Version:** RC1.1  
**Status:** Design authority (documentation only)

## Purpose

Explain **how the Design Operating System evolves** — the official lifecycle for all future Vendor Studio design work.

## Scope

Process from idea through release. Does not replace Drupal release engineering. Constitution: [ADR-0001](decisions/ADR-0001-design-authority.md). Contributor how-to: [CONTRIBUTING.md](CONTRIBUTING.md).

## Audience

Everyone proposing or reviewing Vendor Studio design or OS changes.

## Related documents

- [ADR-0001](decisions/ADR-0001-design-authority.md)
- [21-definition-of-done.md](21-definition-of-done.md)
- [16-design-review-checklist.md](16-design-review-checklist.md)
- [10-roadmap.md](10-roadmap.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)

---

## Why a lifecycle

Ad-hoc redesign recreates the problems this OS was written to prevent. A single lifecycle keeps philosophy, IA, tokens, and implementation aligned as the product grows.

---

## Official lifecycle

```text
Idea
  ↓
Proposal
  ↓
Design Decision Record (when required)
  ↓
Review
  ↓
Approval
  ↓
Implementation
  ↓
Validation
  ↓
Documentation update
  ↓
Release
```

---

### 1. Idea

A need appears: organiser pain, phase work, support theme, or strategic opportunity.

| Do | Don’t |
| --- | --- |
| Capture briefly | Silently start a parallel UI pattern |
| Check [19](19-anti-patterns.md) and [20](20-vendor-studio-v2-vision.md) | Treat competitor chrome as a brief |

Large out-of-roadmap ideas → [A03](appendices/A03-future-ideas-parking-lot.md) or [20](20-vendor-studio-v2-vision.md).

---

### 2. Proposal

Open a documentation (and/or product) proposal that states:

- Problem and organiser outcome
- Authoritative OS document(s) affected
- Metrics impacted ([18](18-product-success-metrics.md))
- Maturity intent ([17](17-design-maturity-model.md))

---

### 3. Design Decision Record

Create or update a **DDR** when the change is foundational (shell, workspace, intents, components, mobile, hubs, money UX philosophy, a11y relaxation). See [CONTRIBUTING.md](CONTRIBUTING.md).

Lightweight clarifications may skip a new DDR but must update the owning document + CHANGELOG.

---

### 4. Review

| Reviewer | Focus |
| --- | --- |
| Design Authority | Experience, consistency, copy, a11y intent |
| Technical Authority | Drupal/Commerce feasibility, access, payments, cache |
| Product Owner | Scope, roadmap fit, organiser priority |

Use precedence in [ADR-0001](decisions/ADR-0001-design-authority.md) when docs conflict.

---

### 5. Approval

DDR status → Accepted (or proposal approved in PR).  
No silent “we’ll document later” for foundational changes.

---

### 6. Implementation

- Feature branch; cite OS docs in PR  
- Extend existing theme/module homes ([09](09-drupal-mapping.md))  
- **No implementation may contradict higher-order documents** ([ADR-0001](decisions/ADR-0001-design-authority.md))

---

### 7. Validation

- [21-definition-of-done.md](21-definition-of-done.md) gates  
- [16-design-review-checklist.md](16-design-review-checklist.md)  
- Theme lint/build and Drupal checks when code changes  
- Commerce/access risk explicit when applicable  

---

### 8. Documentation update

- Update authoritative homes and cross-refs  
- Add “Implemented mapping” notes under [09](09-drupal-mapping.md) when useful  
- [CHANGELOG.md](CHANGELOG.md) for OS changes  
- Bump version per [README](README.md) versioning policy when the standard changes  

---

### 9. Release

- Ship the phase/feature per engineering release process  
- Roadmap phase exit targets maturity Level 2+ ([17](17-design-maturity-model.md))  
- Do not promote parked v2 ideas in the same release without Product Owner approval  

---

## Fast path (non-foundational)

```text
Idea → Proposal (doc PR) → Review → Approval → Implementation* → Validation → Docs → Release
```

\*Implementation may be absent for docs-only clarifications.

---

## Design implications

- Skipping DDR/Approval for foundational change is a process defect
- Lifecycle applies to design OS edits and to product UI work

## Future considerations

- Automate citation reminders in PR templates after v1.0
- Record cycle time from Proposal → Release for governance health (optional ops metric)

## Related references

- [ADR-0001](decisions/ADR-0001-design-authority.md) · [21](21-definition-of-done.md) · [CONTRIBUTING.md](CONTRIBUTING.md) · [10](10-roadmap.md) · [22](22-design-system-health.md)
