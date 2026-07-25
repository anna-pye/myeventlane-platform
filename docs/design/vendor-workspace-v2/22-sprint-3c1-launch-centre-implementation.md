# Sprint 3C.1 — Launch Centre Composition (implementation note)

**Status:** Implemented · Product Owner approved (2026-07-25) — composition **Frozen**  
**Date:** 2026-07-25  
**Branch:** `feature/vendor-workspace-foundation`  
**Scope:** Publishing section composition only  
**Catalogue:** [23-vendor-component-catalogue.md](23-vendor-component-catalogue.md) — Launch Centre 🔒 Frozen (composition)  

## What changed

Replaced the Publishing Hub (checklist markup + full `EventSettingsForm` including publish card) with the approved **Launch Centre** hierarchy:

1. Ready to Launch (narrative + Hero hint)  
2. Launch checklist (expand when blocked, collapse when ready)  
3. Who can find this? (progressive disclosure + slim visibility form)  
4. After you publish (informational only)

## Explicit non-goals (deferred)

- Launch Success (3C.2)  
- Failure / retry / toast UX  
- Dual-Publish removal beyond removing the card from this section (Hero unchanged)  
- Mission Control / Hero redesign  
- Eligibility / readiness rule changes  

## Architecture confirmation

| Concern | Still owned by |
| --- | --- |
| Eligibility | `PublishEligibilityEvaluator` |
| Mutation | `EventStudioSaveService::setNodePublishedState` |
| Readiness data | `EventReadinessService` |
| Hero CTA | `resolveAuthoritativePrimaryCta` |
| Publish UI | Hero only |

## Files

See git status / final report.
