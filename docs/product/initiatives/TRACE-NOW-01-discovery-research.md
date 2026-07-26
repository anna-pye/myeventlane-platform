# Initiative Brief: Discovery and research evidence refresh

| Field | Value |
| --- | --- |
| Initiative | TRACE-NOW-01 — Discovery and research |
| Status | Deferred after evidence refresh |
| Product Owner approval | Evidence refresh completed; further research deferred to prioritise the organiser experience |
| Date | 2026-07-26 |

## Product surface

Public event discovery, including browse, search, date, price, category, merchandising and recovery paths.

## User

- People trying to find an event
- First-time and returning attendees
- Organisers relying on appropriate discovery
- Support and product staff learning where people become uncertain

## Human outcome

MyEventLane understands what people are trying to find, where discovery becomes unclear and which failures create avoidable support demand before deciding what to change.

## Why it exists

The roadmap places discovery and research in “Now”, but existing repository evidence is mostly technical audit material. Several older findings have changed or require reinterpretation:

- `/events/hidden-gems` now has a configured View display.
- `/events/nearby` and `/events/online` return HTTP 200 but match the generic `page_events` display rather than distinct product surfaces.
- the fake node-ID-derived event-card signal has been removed.

Current organiser interviews, attendee interviews, observed tasks and support-demand evidence were not found.

## Manifesto alignment

- Build with communities rather than making assumptions.
- Start with the organiser's or attendee's goal.
- Measure outcomes, not activity.
- Make the next step visible.
- Keep one source of truth.

## Strategic goal

[Years 1-2: Establish trust and coherence](../../governance/02-product-strategy.md#years-1-2-establish-trust-and-coherence), including dependable discovery and measurable support needs.

## Requirement reference

- [Discovery](../../governance/04-product-requirements.md#discovery)
- [Events](../../governance/04-product-requirements.md#events)
- [Analytics](../../governance/04-product-requirements.md#analytics)

## Existing system owner

Confirmed repository owners include:

- `upcoming_events` Views displays for primary browse routes
- `PublicEventDiscoveryQueryAlter` for allow-listed discovery query hygiene
- `EventCategoryUrlService` for category URLs
- `EventMerchandisingPresenter` for event-card merchandising signals
- `PopularEventsService` for engagement-ranked popular events
- `HomepageMerchandisingQueryAlter` for homepage and popular-display ranking integration
- `DiscoveryAttributionSources` and `DiscoverySurfaceAnalyticsService` for attribution vocabulary and public discovery analytics mapping

The [dated evidence refresh](../../research/discovery/2026-07-26-evidence-refresh.md) records the current paths and limitations.

Research planning:

- [Public Discovery Research Protocol](../../research/discovery/research-protocol.md)
- [Public Discovery Evidence-Collection Plan](../../research/discovery/evidence-collection-plan.md)

## In scope

- Refresh repository ownership evidence
- Verify anonymous public route behaviour in the current DDEV environment
- Identify where older audits are stale or still useful
- Define research questions and evidence gaps
- Identify evidence required before a product decision
- Preserve findings without proposing implementation

## Out of scope

- Creating or changing routes, Views or filters
- Location or online-event modelling
- Search, ranking or merchandising changes
- Navigation, copy, theme or component changes
- Analytics instrumentation changes
- User recruitment or interviews without a separate research plan and consent process
- Roadmap movement

## Dependencies

- Representative organiser and attendee participation
- Access to support-demand evidence
- Current content representative of real discovery conditions
- Public design-authority decision where current authority is unclear
- Privacy-conscious research and analytics practices

## Risks

- Technical evidence may be mistaken for evidence of user need.
- HTTP 200 responses may conceal misleading product behaviour.
- Test content may not represent real event variety, location or availability.
- Older audit labels may persist after the underlying repository has changed.
- Research may over-represent confident or experienced users.

## Accessibility considerations

Research must include keyboard, screen magnification, screen reader, cognitive-load and mobile considerations. Recruitment should include people with disabilities where possible and must not treat automated checks as user evidence.

## Security and privacy considerations

Collect only research information needed for a defined question. Establish consent, retention, access and de-identification before recording interviews or support examples. Do not place personal information or support transcripts in the repository.

## Commerce considerations

Discovery must present price and availability truthfully, but this initiative does not change Commerce, tickets, orders, payments, capacity or checkout. Any future proposal affecting ranking through sales or paid promotion requires Commerce and fairness review.

## Success criteria

- Current discovery owners and route behaviour are evidence-backed.
- Stale audit findings are identified without rewriting historical records.
- Research questions are tied to organiser or attendee outcomes.
- Required participants and evidence sources are defined.
- No implementation work is implied or authorised.
- A later product decision can distinguish user evidence from technical evidence.

## Evidence required before implementation

- Approved research questions
- Representative participant plan and consent approach
- Observed organiser and attendee task evidence
- Support-demand evidence
- Current route and content evidence in the target environment
- Accessibility and mobile evidence
- Product decision identifying the bounded problem
- Approved initiative brief for the resulting delivery slice

## Product Owner approval

| Decision | Name | Date | Evidence |
| --- | --- | --- | --- |
| Approved for discovery and evidence refresh only | Product Owner | 2026-07-26 | Direction to proceed to the next governed step |

This approval does not authorise implementation.

## Deferral note

On 26 July 2026, the Product Owner deferred further public discovery research planning because it is not part of the current organiser-experience scope. The completed technical evidence refresh remains valid repository evidence. Recruitment, research collection and implementation remain unauthorised.
