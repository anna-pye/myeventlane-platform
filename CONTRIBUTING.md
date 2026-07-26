# Contributing to MyEventLane

MyEventLane contributions must protect product intent, repository safety and the trust placed in the platform.

## Start here

Before proposing or changing anything, read:

1. [Documentation guide](docs/README.md)
2. [Organiser Manifesto](docs/governance/00-organiser-manifesto.md)
3. [Product Constitution](docs/governance/01-product-constitution.md)
4. [Engineering Principles](docs/governance/06-engineering-principles.md)

The Organiser Manifesto is the highest product authority. Implementation does not create product policy.

## Contribution standard

Every material contribution must identify:

- the human outcome it serves;
- the applicable Manifesto and requirement;
- the existing product, design and technical authority;
- the evidence supporting the change;
- accessibility, trust, privacy, security, mobile and Commerce effects;
- the success measure; and
- the validation needed to support the claim.

Roadmap position does not authorise implementation. Approved scope and acceptance criteria are required.

## Safe repository practice

Before work:

```bash
pwd
git branch --show-current
git rev-parse HEAD
git status --short
git fetch --prune origin
```

Do not discard unrelated work. Do not commit directly to `main`, rewrite shared history, expose secrets, or use destructive Git or Drupal commands without explicit approval.

Isolated feature worktrees are permitted and are the standard feature workflow. Follow [`docs/DEVELOPMENT_WORKFLOW.md`](docs/DEVELOPMENT_WORKFLOW.md). The contrary prohibition in [`docs/operations/DEV_GIT_RULES.md`](docs/operations/DEV_GIT_RULES.md) is superseded and retained only for traceability.

## Product and design decisions

Use the templates in [`docs/templates/`](docs/templates/) for new product decisions and initiatives. Do not edit an approved or frozen design authority to resolve a conflict silently. Record the conflict and escalate it through [`docs/GOVERNANCE.md`](docs/GOVERNANCE.md).

Vendor Studio work must also follow its frozen design authorities, subject to the repository-wide constitutional hierarchy:

- [`docs/design/vendor-studio/`](docs/design/vendor-studio/)
- [Workspace Zones](docs/design/vendor-studio-visual/07-workspace-zones.md)
- [Visual Language B.5](docs/design/vendor-studio-visual/03-option-b5.md)
- [Vendor Component Catalogue](docs/design/vendor-workspace-v2/23-vendor-component-catalogue.md)

## Drupal and Commerce

Use Drupal 11 and Drupal Commerce 3 supported patterns. Preserve clear ownership among event content, sellable ticket variations, orders, order items, payments and tickets. Access, payment state, capacity, ownership, refunds and payouts are integrity-sensitive.

Do not alter runtime behaviour as part of documentation-only work.

## Review and validation

Keep changes small and coherent. State exactly what changed, what was deliberately left unchanged and which checks were run. A build, lint command or cache rebuild proves only that check; it does not prove a complete user journey.

Do not commit or push unless the approved task explicitly includes those actions.
