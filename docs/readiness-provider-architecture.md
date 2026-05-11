# Readiness Provider Architecture

Status: internal governance  
Scope: current readiness behavior and future provider migration

## Current Rule

`EventReadinessService` is production infrastructure. It remains the canonical server-side publish readiness evaluator in this phase.

The current service lives at `web/modules/custom/myeventlane_event_studio/src/Service/EventReadinessService.php` and returns `EventReadinessResult`. It is used by Studio display and publish/save enforcement paths.

This phase adds provider contracts only. It does not split the working readiness system into tagged providers yet.

Do not register readiness providers as active services for publish gating in this phase. The provider model is a future extension contract, not the current execution path.

## Enforcement Versus Presentation

Readiness has two distinct roles:

| Role | Meaning |
| --- | --- |
| Enforcement | Blocks publish or unsafe operations. Must live in PHP services and domain gates. |
| Presentation | Explains state to vendors. May summarize domain signals but must not invent enforcement logic. |

Twig and JavaScript may render readiness output. They must not duplicate readiness decisions.

## Future Provider Contract

Future providers implement `EventReadinessProviderInterface` and return `EventReadinessProviderResult`.

Provider metadata must include:

- Stable provider id.
- Owning domain.
- Related Studio section id, when applicable.
- Blocking errors.
- Warnings.
- Completed items.

Available contract primitives:

| Primitive | Purpose |
| --- | --- |
| `EventReadinessProviderInterface` | Future provider API for one domain-owned readiness evaluator. |
| `AbstractEventReadinessProvider` | Shared metadata and typed result construction for future providers. |
| `EventReadinessProviderResult` | Provider-level result DTO for future aggregation. |
| `ReadinessIssue` | Typed issue object for future provider outputs. |
| `ReadinessSeverity` | Severity enum for blocking errors, warnings, and completed signals. |

Future discovery convention:

- Provider services should use a stable service tag such as `myeventlane_event_studio.readiness_provider`.
- Tags should include provider id, domain, section id, and weight when provider orchestration is approved.
- Service discovery must not become the publish readiness source of truth until the migration has domain tests and a rollback path.

Potential future providers:

- Branding readiness provider.
- Ticket readiness provider.
- Commerce readiness provider.
- Fulfilment readiness provider.
- Scanning readiness provider.
- Analytics readiness provider.

## Migration Sequence

Do not create partial providers before their domains exist. A domain is ready for a readiness provider only when it has:

1. A domain owner service.
2. Stable persisted data.
3. Access boundaries.
4. Tests or verification paths.
5. A Studio section contract.
6. Clear enforcement versus presentation rules.

After that, `EventReadinessService` can become an aggregator over tagged providers in a controlled refactor. That migration is explicitly outside the current governance foundation.

## Provider Rules

Providers must:

- Evaluate one domain only.
- Fail loudly with logging when required data cannot be inspected.
- Return deterministic messages.
- Avoid heavy queries on workspace load.
- Add cacheability metadata when exposed through render builders.
- Avoid leaking staff-only diagnostics to vendors.

Providers must not:

- Mutate event, ticket, Commerce, or fulfilment state.
- Call Stripe or remote APIs during page render.
- Duplicate another provider's responsibility.
- Hide blocking failures as warnings.
- Depend on client-side state for server-side decisions.

## Section Participation

Sections declare readiness participation through `EventStudioSection` metadata. That metadata is not itself readiness logic. It tells the shell and future aggregators which sections may contribute readiness signals.

Examples:

| Section | Readiness role |
| --- | --- |
| `information` | Title, schedule, booking mode basics |
| `branding` | Required event image and public presentation readiness |
| `tickets` | Paid ticket presence, ticket capacity, ticket lifecycle validity |
| `settings` | Publish requirements and final review |
| `merchandise` | Future Commerce product readiness only after merchandise exists |
| `fulfilment` | Future shipping, pickup, redemption, or inventory readiness only after fulfilment exists |

## Testing Expectations

When provider aggregation is implemented later, tests must cover:

- Provider ordering.
- Duplicate message handling.
- Blocking versus warning behavior.
- Section links.
- Vendor access boundaries.
- Staff-only diagnostics exclusion.
- Published and draft event behavior.

Until then, tests should continue to verify the current `EventReadinessService` behavior and publish gates.

## Related Files

- `web/modules/custom/myeventlane_event_studio/src/Service/EventReadinessService.php`
- `web/modules/custom/myeventlane_event_studio/src/DTO/EventReadinessResult.php`
- `web/modules/custom/myeventlane_event_studio/src/Readiness/EventReadinessProviderInterface.php`
- `web/modules/custom/myeventlane_event_studio/src/Readiness/EventReadinessProviderResult.php`
- `docs/operational-readiness-governance.md`
- `docs/event-studio-section-contracts.md`
