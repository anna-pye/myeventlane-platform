# Contributing to the Vendor Studio Product Design System (PDS)

**Also known as:** Vendor Studio Design Operating System  
**Version:** 1.0  
**Status:** **FROZEN**

## Purpose

Help future contributors read, change, and apply this Product Design System correctly.

## Scope

How humans work with the pack. Constitution: [ADR-0001](decisions/ADR-0001-design-authority.md). Lifecycle: [23](23-governance-lifecycle.md).

## Audience

Designers, engineers, product managers, reviewers.

## Related documents

- [INDEX.md](INDEX.md) — navigation landing page
- [README.md](README.md) — purpose and governance summary
- [21-definition-of-done.md](21-definition-of-done.md)
- [16-design-review-checklist.md](16-design-review-checklist.md)

---

## How to read the Product Design System

Start at [INDEX.md](INDEX.md) or follow this order:

1. [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) — culture · **build stack order** · Zone Test  
2. [01-vendor-studio-vision.md](01-vendor-studio-vision.md) — mission, principles, Golden Rule  
3. [Workspace Zones](../vendor-studio-visual/07-workspace-zones.md) — **first-class** composition (Identity → Guidance → Work → Outcome)  
4. [Visual Language B.5](../vendor-studio-visual/03-option-b5.md) — look  
5. [Component Catalogue](../vendor-workspace-v2/23-vendor-component-catalogue.md) — freeze ledger  
6. [ARCHITECTURE.md](ARCHITECTURE.md) — product shape  
7. [QUICK_REFERENCE.md](QUICK_REFERENCE.md) — day-to-day aid  
8. [appendices/A01-glossary.md](appendices/A01-glossary.md) — vocabulary  
9. Then deep-dive by role (IA, tokens, Drupal mapping, etc.)

You do **not** need prior MEL tribal knowledge if you follow this path and the glossary.

**Implementation order for new features:** Choose Workspace Zone → Reuse Component → Apply B.5 → Implement. Do not invent a new page composition from scratch.

### Zone Gate (Workspace PRs)

Before screenshots, every Event Workspace PR must include:

```text
Zone map
Identity
Guidance
Work
Outcome
```

No map → not designed yet. Detail: [16-design-review-checklist.md](16-design-review-checklist.md).

---

## Reading order (by role)

| Role | Read next |
| --- | --- |
| Designer / Product | 02, 12, 13, 14, 15, 11, 19 |
| Theme / Frontend | 03, 05, 07, 08, 11, 09, 21, 16 |
| Drupal / Commerce | 09, 02, 13, ADR-0001, 21 |
| Reviewer | 21, 16, ADR-0001, 19, cited docs in the PR |

---

## How to propose changes

1. Branch from the appropriate base (never commit OS changes straight to `main` without review).  
2. Identify the **authoritative home** for the concept ([README](README.md) document map).  
3. Edit that home; replace duplicates elsewhere with references.  
4. Update cross-links and [CHANGELOG.md](CHANGELOG.md).  
5. Open a PR describing why (organiser outcome), not only what.  
6. Follow [23-governance-lifecycle.md](23-governance-lifecycle.md).

Documentation-only work must not modify PHP, Twig, SCSS, JS, YAML, or Drupal config unless a separate implementation sprint explicitly allows it.

---

## When to create a DDR

Create a **Design Decision Record** when the change:

- Alters global navigation or Event Workspace structure  
- Adds/removes a layout intent or breaks max-width contracts  
- Introduces a new component family duplicating MEL patterns  
- Changes mobile navigation or Door Mode philosophy  
- Establishes or moves a global hub (e.g. Payments, Marketing)  
- Relaxes accessibility, money honesty, or publish honesty  
- Conflicts with a locked runtime safety contract  

Template shape: Decision · Problem · Alternatives · Reason · Consequences · Future review triggers (see existing `decisions/DDR-*.md`).

Constitutional changes to ownership or precedence require amending [ADR-0001](decisions/ADR-0001-design-authority.md).

---

## When to update existing documents

| Change type | Update |
| --- | --- |
| Clarification / example | Owning doc + CHANGELOG |
| Token value addition (compatible) | [11](11-design-tokens.md) + CHANGELOG |
| New anti-pattern observed | [19](19-anti-patterns.md) |
| Runtime mapping discovered | [09](09-drupal-mapping.md) |
| Phase reorder | [10](10-roadmap.md) + Product Owner |
| Parked idea | [A03](appendices/A03-future-ideas-parking-lot.md) or [20](20-vendor-studio-v2-vision.md) |

Do not copy principles into every file — link to [01](01-vendor-studio-vision.md).

---

## When to bump versions

Follow [README.md](README.md) Versioning Policy:

| Bump | When |
| --- | --- |
| Draft / RC | Pre-freeze iteration (e.g. RC1 → RC1.1) |
| Freeze v1.0 | Product Owner + Design Authority declare freeze; tag |
| Minor (1.x) | Compatible clarifications, examples, non-breaking tokens |
| Major (2.0) | Breaking philosophy or IA; DDR set; Product Owner approval |

---

## How implementation teams should reference documents

In every Vendor Studio implementation PR:

```markdown
## Vendor Studio Design System References
This implementation follows:
- 02-information-architecture.md
- 05-component-library.md
- 11-design-tokens.md
- 16-design-review-checklist.md
- 21-definition-of-done.md
- ADR-0001
- DDR-<n> (if applicable)
```

Also apply GitHub label **`design-system`**. Repository template: `.github/PULL_REQUEST_TEMPLATE.md`.

Start from the PDS — do not invent patterns and document afterward.

---

## How reviewers should review Design PRs

### Documentation PRs

1. Is the authoritative home correct?  
2. Are duplicates removed in favour of references?  
3. Does precedence still hold ([ADR-0001](decisions/ADR-0001-design-authority.md))?  
4. Is a DDR required but missing?  
5. Is CHANGELOG updated?  
6. Australian English; glossary terms respected?

### Implementation PRs

1. Citations present and accurate  
2. [21](21-definition-of-done.md) gates + [16](16-design-review-checklist.md)  
3. No contradiction of higher docs  
4. Commerce/access/money risk explicit when applicable  
5. Anti-patterns avoided ([19](19-anti-patterns.md))

---

## Design implications

- Contribution quality is part of product quality  
- Uncited Vendor Studio PRs are incomplete  

## Future considerations

- Link this file from repository root contributing docs if desired (separate decision)  
- PR templates after v1.0 freeze  

## Related references

- [INDEX.md](INDEX.md) · [README.md](README.md) · [ADR-0001](decisions/ADR-0001-design-authority.md) · [23](23-governance-lifecycle.md) · [21](21-definition-of-done.md)
