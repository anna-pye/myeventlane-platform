# MyEventLane Engineering Principles

| Field | Value |
| --- | --- |
| Status | Permanent engineering governance |
| Highest authority | [Organiser Manifesto](./00-organiser-manifesto.md) |
| Constitutional parent | [Product Constitution](./01-product-constitution.md) |

## Purpose

Engineering makes MyEventLane dependable while allowing its technology to disappear from the user experience. These principles govern judgement; they are not a licence to redesign the product or bypass approved requirements.

Implementation serves approved product intent. It may provide evidence, expose constraints and inform a decision, but it may not silently create or override product policy.

## Drupal principles

- Use Drupal 11-supported APIs and established extension points.
- Keep domain behaviour in accountable services rather than templates or incidental hooks.
- Use dependency injection, explicit access checks, cache-aware rendering, configuration schema and translatable user-facing language.
- Keep content, configuration and operational state distinct.
- Do not expose entity, module, route or workflow terminology to users without a genuine user need.
- Prefer one canonical capability over parallel custom implementations.

## Commerce principles

- Keep event content, sellable ticket variations, order items, orders, payments and ticket ownership conceptually distinct.
- Treat prices, adjustments, capacity, order state, payment state, refunds and payouts as integrity-sensitive.
- Make operations idempotent where retries are possible.
- Preserve an auditable financial history; do not rewrite inconvenient states.
- Enforce customer, organiser and staff ownership at every boundary.
- Never infer payment success from navigation, messages or an incomplete callback.

## Architecture

Architecture must have clear ownership, boundaries and sources of truth. Decisions are recorded when they are costly to reverse, cross product areas or affect security, privacy, commerce or operations.

Prefer established project patterns and the smallest coherent change. New abstractions require evidence of repeated need. New services, stores, workflows or state machines must not duplicate existing authority.

## Performance

Performance is a user outcome. Set budgets around the journeys people must complete, especially mobile discovery, event pages, checkout, ticket access and event-day work. Measure representative content and realistic conditions.

Use caching deliberately without weakening permissions, freshness or state accuracy. Regressions are prioritised by blocked outcomes, not benchmark novelty.

## Accessibility

Accessibility is included in design, acceptance criteria, implementation and review. Use semantic structure, keyboard operability, visible focus, sufficient contrast, meaningful names and errors, reduced-motion support and usable touch targets.

Automated checks are useful but insufficient. Critical journeys require proportionate manual and assistive-technology verification.

## Security

Use least privilege, secure defaults, layered access control, CSRF protection, validated input, safe output, protected secrets and dependency management. Treat order, ticket, payout, personal and organiser-owned data as sensitive.

Security findings are assessed by plausible impact and reachability. Fixes must preserve product integrity and receive regression coverage.

## Testing

Tests prove the claim being made:

- unit tests for isolated rules;
- kernel or integration tests for services, storage and framework behaviour;
- functional tests for permissions, workflows and state transitions;
- browser tests for critical user journeys; and
- manual verification for accessibility, visual behaviour and real operational conditions where automation cannot provide evidence.

Payment, refund, ticket ownership, capacity and access changes require negative and failure-path testing. A build or cache clear alone is not product verification.

## Documentation

Code explains local mechanics. Governance, requirements, architecture decisions and runbooks explain intent, authority and operation. Documentation changes with behaviour and identifies assumptions that cannot be verified.

Do not document speculative implementation as current fact.

## Git workflow

- Work on a focused branch and begin with a fresh status and diff inspection.
- Preserve unrelated work.
- Make commits coherent, reviewable and free of secrets.
- Do not rewrite shared history or bypass required review.
- Record configuration and dependency changes explicitly.
- Keep generated artefacts governed by established repository rules.

## Design-first development

Implementation follows an approved outcome, journey and acceptance criteria. Inspect canonical payloads, components and services before changing behaviour. Resolve unclear product decisions before code makes them expensive.

Design-first does not mean appearance-first. It includes language, state, consequence, accessibility, failure, recovery, mobile use and operational ownership.

## Small PR philosophy

Prefer the smallest safe change that fully delivers one coherent outcome. A pull request should have one understandable purpose, bounded risk and validation proportional to that risk.

Avoid opportunistic refactoring, architecture changes and unrelated formatting. When a correct outcome requires a larger change, divide it by safe boundaries rather than concealing its scope.

## Repository standards

- Follow repository instructions and existing conventions.
- Keep custom Drupal modules and themes in their established locations.
- Use the project's supported Composer, DDEV and frontend workflows.
- Never commit secrets, production data, local environment state or unexplained generated files.
- Add dependencies only with a documented need, compatible constraint and security review.
- Keep linting, static analysis, tests and builds reproducible.
- Treat configuration exports as reviewed product changes.
- Mark superseded documents clearly; do not create competing sources of truth.

## Engineering decision record

A material engineering proposal records:

1. the user outcome and Manifesto alignment;
2. current evidence and constraints;
3. affected sources of truth;
4. security, privacy, accessibility, performance and commerce effects;
5. alternatives considered;
6. failure, migration and recovery behaviour;
7. validation required; and
8. the accountable approver.

## Recommended `/docs` structure

```text
docs/
├── governance/
│   ├── 00-organiser-manifesto.md
│   ├── 01-product-constitution.md
│   ├── 02-product-strategy.md
│   ├── 03-product-roadmap.md
│   ├── 04-product-requirements.md
│   ├── 05-operations.md
│   └── 06-engineering-principles.md
├── design/
│   ├── product-design-principles/
│   ├── workspace-zones/
│   ├── visual-language/
│   ├── component-catalogue/
│   └── product-design-system/
├── architecture/
│   └── decisions/
├── product/
│   ├── discovery/
│   ├── requirements/
│   └── research/
├── operations/
│   ├── runbooks/
│   ├── incidents/
│   └── releases/
├── engineering/
│   ├── standards/
│   ├── testing/
│   └── workflows/
├── audits/
├── adoption/
└── archive/
```

This structure separates permanent authority from evolving evidence and operational records. Existing documents should be moved only through a separately approved information-architecture review; this document does not authorise a bulk reorganisation.
