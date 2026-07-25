# Vendor Studio — Definition of Done

**Version:** RC1.1  
**Status:** Design authority (documentation only)

## Purpose

Define **completion criteria** for every Vendor Studio feature. A feature is not done when it “looks finished” — it is done when every applicable gate below is satisfied.

## Scope

Mandatory review gates for organiser-facing Vendor Studio work and for Design OS documentation changes that affect those surfaces. Detailed YES/NO items live in [16-design-review-checklist.md](16-design-review-checklist.md). This document owns the **gate list and done rule**.

## Audience

Authors, reviewers, Product Owner, Design Authority, Technical Authority.

## Related documents

- [16-design-review-checklist.md](16-design-review-checklist.md) — detailed checklist
- [ADR-0001](decisions/ADR-0001-design-authority.md) — constitution
- [23-governance-lifecycle.md](23-governance-lifecycle.md)
- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)

---

## Why a Definition of Done

Without a shared done bar, teams ship inconsistent surfaces and call them complete. This gate list protects organiser experience, accessibility, Drupal/Commerce integrity, and long-term maintainability.

**Rule:** Only features satisfying every **applicable** criterion are considered complete. Mark N/A only with rationale (e.g. docs-only PR, no Commerce touch).

---

## Mandatory review gates

Copy into the PR. Check each applicable gate.

### Design and product

- [ ] **Design review** — Design Authority (or delegate) reviewed experience against OS docs cited in the PR
- [ ] **Information Architecture** — Fits [02](02-information-architecture.md); no Studio/Manager fork; organiser labels
- [ ] **Three Question Framework** — Where am I? What needs me? What is the next useful action? ([01](01-vendor-studio-vision.md))
- [ ] **Golden Rule** — Next step clear within five seconds ([01](01-vendor-studio-vision.md))
- [ ] **Component Library compliance** — Extends [05](05-component-library.md); no parallel component names ([DDR-004](decisions/DDR-004-component-philosophy.md))
- [ ] **Design Tokens compliance** — Spacing, type, colour, intents per [11](11-design-tokens.md) / [03](03-layout-system.md)

### Accessibility and interaction

- [ ] **Accessibility (WCAG AA)** — Contrast, semantics, severity not colour-only
- [ ] **Mobile** — Usable at ~390px; [08](08-mobile-guidelines.md) / [DDR-005](decisions/DDR-005-mobile-first.md)
- [ ] **Keyboard** — Primary task completable; focus visible ([07](07-interaction-guidelines.md))

### Content and states

- [ ] **Copywriting** — [15](15-copywriting-guide.md); no CMS/Commerce jargon in UI
- [ ] **Loading states** — No fake metrics; appropriate busy/skeleton patterns
- [ ] **Empty states** — Honest reason + next step
- [ ] **Error states** — Reason + recovery; no dead ends
- [ ] **Success states** — Confirmation + next useful action

### Platform integrity

- [ ] **Security** — Access server-side; no PII/secrets leakage; UI hide ≠ security
- [ ] **Drupal architecture** — Logic not in Twig; cache considered; mapping per [09](09-drupal-mapping.md)
- [ ] **Commerce architecture** — States not invented; refunds/payouts deliberate; risk called out
- [ ] **Performance** — No vanity heavy queries; JS scoped to routes that need it

### Documentation and process

- [ ] **Documentation updated** — OS mapping notes / CHANGELOG / cross-refs as needed; PR cites OS docs
- [ ] **Design Review Checklist completed** — [16-design-review-checklist.md](16-design-review-checklist.md) filled (YES/NO/N/A + rationale)

---

## Applicability guide

| Work type | Typical N/A |
| --- | --- |
| Docs-only OS change | Commerce runtime, performance runtime |
| Pure SCSS token alignment | Commerce, if no state UI |
| Refunds / payouts UI | None — all money gates apply |
| Door Mode | None for mobile/a11y/security |

When unsure, **do not mark N/A** — ask Technical Authority or Design Authority.

---

## Relationship to checklist and lifecycle

```text
Governance lifecycle (23)
    → Implementation
        → Definition of Done gates (this file)
            → Detailed YES/NO (16)
                → Merge / release
```

---

## Design implications

- “LGTM” without gates is insufficient for Vendor Studio
- Incomplete DoD blocks merge for organiser-facing surfaces

## Future considerations

- PR template may embed this gate list after v1.0 freeze
- Phase-specific annexes (e.g. Payments) must not weaken core gates

## Related references

- [16](16-design-review-checklist.md) · [ADR-0001](decisions/ADR-0001-design-authority.md) · [23](23-governance-lifecycle.md) · [18](18-product-success-metrics.md) · [19](19-anti-patterns.md)
