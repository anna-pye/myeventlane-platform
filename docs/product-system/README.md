# MyEventLane Product System

**Status:** Complete — product foundation pack  
**Date:** 2026-07-24  
**Scope:** Documentation only. No runtime changes.

This pack is the permanent product foundation for MyEventLane 1.0 organiser experience. It **unifies** existing authority; it does not invent a second design language.

## Authority (product law)

| # | Document | Path |
| --- | --- | --- |
| 1 | MEL Style Guide | [`DESIGN_SYSTEM.md`](../../DESIGN_SYSTEM.md) · [`docs/brand/`](../brand/) |
| 2 | Vendor Experience Convergence | [`docs/vendor-experience-convergence.md`](../vendor-experience-convergence.md) |
| 3 | Convergence Implementation Plan | [`docs/vendor-experience-convergence-implementation-plan.md`](../vendor-experience-convergence-implementation-plan.md) |
| 4 | Language Guide | [`docs/vendor-experience-convergence-language-guide.md`](../vendor-experience-convergence-language-guide.md) |
| 5 | Screen Specifications | [`docs/vendor-experience-convergence-screen-specifications.md`](../vendor-experience-convergence-screen-specifications.md) |
| 6 | Information Architecture | [`docs/vendor-experience-convergence-information-architecture.md`](../vendor-experience-convergence-information-architecture.md) |

**Measurement:** [`docs/vendor-experience-convergence-success-metrics.md`](../vendor-experience-convergence-success-metrics.md)

## Pack contents

| File | Role |
| --- | --- |
| [`MEL_PRODUCT_SYSTEM.md`](MEL_PRODUCT_SYSTEM.md) | Product bible — principles + Stage 1 VX2 review |
| [`MEL_UX_PATTERNS.md`](MEL_UX_PATTERNS.md) | Reusable UX patterns |
| [`MEL_COMPONENT_AUDIT.md`](MEL_COMPONENT_AUDIT.md) | Component audit — merge / reduce variations |
| [`MEL_MICROCOPY_AUDIT.md`](MEL_MICROCOPY_AUDIT.md) | Organiser-facing microcopy audit |
| [`MEL_INTERACTION_AUDIT.md`](MEL_INTERACTION_AUDIT.md) | Interaction states audit |
| [`MEL_ACCESSIBILITY_REVIEW.md`](MEL_ACCESSIBILITY_REVIEW.md) | WCAG AA pattern review |
| [`MEL_DESIGN_DEBT.md`](MEL_DESIGN_DEBT.md) | Prioritised design debt register |
| [`LAUNCH_READINESS.md`](LAUNCH_READINESS.md) | Launch checklist by product area |

## How to use

1. **Before any organiser UI change** — read `MEL_PRODUCT_SYSTEM.md` principles + the relevant pattern in `MEL_UX_PATTERNS.md`.
2. **Before new components** — check `MEL_COMPONENT_AUDIT.md`; extend one canonical component.
3. **Before shipping copy** — Language Guide + `MEL_MICROCOPY_AUDIT.md`.
4. **Before claiming polish** — clear Critical/High items in `MEL_DESIGN_DEBT.md` for that surface.
5. **Before launch claims** — update `LAUNCH_READINESS.md` against success metrics.

## Related runtime docs (not replaced)

- VX2 implementation notes under `docs/implementation/vx2-*`
- Layout: `docs/implementation/vx2-02a-workspace-layout-convergence.md`
- Brand tokens: `docs/brand/design-tokens.md`
- Interaction authority: `docs/adoption/mel-interaction-authority-audit.md`
