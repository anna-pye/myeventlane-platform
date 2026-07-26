# Discovery Evidence Refresh — 26 July 2026

| Field | Value |
| --- | --- |
| Status | Repository and DDEV evidence |
| Initiative | [TRACE-NOW-01](../../product/initiatives/TRACE-NOW-01-discovery-research.md) |
| Environment | Local DDEV, Drupal 11.4.4 |
| Site URI | `https://myeventlane.ddev.site` |
| Code changes | None |

## Scope

This record refreshes technical evidence for public discovery. It does not establish user need, approve a design direction or authorise implementation.

## Safety and environment

| Check | Result |
| --- | --- |
| Repository | `/Users/anna/myeventlane` |
| Branch | `docs/governance-baseline` |
| DDEV web | OK |
| Database | Connected |
| Drupal bootstrap | Successful |
| Drupal version | 11.4.4 |

The branch was updated with the latest `origin/main` before this evidence was recorded. No cache rebuild, configuration import, content change or runtime write was performed.

## Confirmed current owners

| Responsibility | Repository owner |
| --- | --- |
| Primary event browse displays | `config/sync/views.view.upcoming_events.yml` |
| Discovery query hygiene | `PublicEventDiscoveryQueryAlter` |
| Category URLs | `EventCategoryUrlService` |
| Event-card merchandising signals | `EventMerchandisingPresenter` |
| Popular-event ranking | `PopularEventsService` |
| Homepage and popular-display ranking bridge | `HomepageMerchandisingQueryAlter` |
| Discovery attribution vocabulary | `DiscoveryAttributionSources` |
| Public discovery analytics mapping | `DiscoverySurfaceAnalyticsService` |

These owners were confirmed by current class and service-registration evidence.

## Configured View paths

Current exported configuration contains:

| Path | Display |
| --- | --- |
| `/events` | `page_events` |
| `/events/category/{slug}` | `page_category` |
| `/events/free` | `page_free` |
| `/events/hidden-gems` | `page_hidden_gems` |
| `/events/popular` | `page_popular` |
| `/events/this-weekend` | `page_this_weekend` |
| `/events/today` | `page_today` |

There is no dedicated configured `page_nearby` or `page_online` display.

## Anonymous route probes

The following paths returned HTTP 200 in the current DDEV environment:

| Path | Matched Drupal route |
| --- | --- |
| `/events` | `view.upcoming_events.page_events` |
| `/events/free` | `view.upcoming_events.page_free` |
| `/events/hidden-gems` | `view.upcoming_events.page_hidden_gems` |
| `/events/popular` | `view.upcoming_events.page_popular` |
| `/events/this-weekend` | `view.upcoming_events.page_this_weekend` |
| `/events/today` | `view.upcoming_events.page_today` |
| `/events/nearby` | `view.upcoming_events.page_events` |
| `/events/online` | `view.upcoming_events.page_events` |
| `/calendar` | `view.events_calendar.page_calendar` |
| `/search` | `mel_search.view` |

### Nearby and online interpretation

The older discovery audit described `/events/nearby` and `/events/online` as broken because it found no dedicated route owner.

Current evidence requires a more precise conclusion:

- both paths return HTTP 200;
- both match the generic `view.upcoming_events.page_events` route;
- neither has a dedicated View display;
- neither has a matching path alias;
- both render the generic discovery page title; and
- repository evidence does not confirm distinct nearby or online filtering.

They are not current 404s, but distinct nearby and online product behaviour is not proven. A 200 response must not be treated as evidence that either user promise is fulfilled.

## Signal ownership correction

The older [Discovery Signal Ownership Map](../../audits/discovery-signal-ownership-map.md) recorded a fake node-ID-derived “Popular/Trending/Just added” event-card chip.

Current repository evidence shows that this placeholder has been removed. A source comment records:

- the node-ID-derived chip was fake;
- `EventMerchandisingPresenter` is the sole discovery signal and badge owner; and
- real booking urgency and capacity signals are separate and remain.

`PopularEventsService` and its consumers remain present for engagement-backed popular-event ranking.

## Earlier audit findings

| Earlier finding | Current classification |
| --- | --- |
| `/events/nearby` and `/events/online` are likely 404s | Stale in exact HTTP terms; both return 200 |
| Nearby and online have no distinct route/filter owner | Still supported |
| `/events/hidden-gems` absent from primary inventory | Stale; configured and returns 200 |
| Fake node-ID-derived event-card chip exists | Stale; removed |
| `PopularEventsService` owns real popularity ranking | Still supported |
| Discovery is primarily owned by `upcoming_events` Views displays | Still supported |

Historical audits remain useful evidence of past repository state. They were not edited.

## User and operational evidence

Repository search found references to proposed usability tests and support-ticket measures, but no confirmed:

- organiser interview record;
- attendee interview record;
- observed discovery usability study;
- participant recruitment or consent record;
- quantified discovery-related support demand; or
- current research synthesis.

**I cannot confirm this evidence exists from the repository.**

## Research questions

The next research activity should answer:

1. What event information do people need before deciding to open an event page?
2. How do people express place, time, price, accessibility and event-type intent?
3. Do people understand the difference among Popular, Hidden Gems, Featured and other discovery labels?
4. What do people expect “Nearby” and “Online” to do?
5. Where do first-time attendees hesitate between event detail, RSVP and paid booking?
6. Which empty states help people recover, and which create a dead end?
7. What discovery problems generate support requests or organiser complaints?
8. Which needs differ for people using mobile devices, keyboards, screen readers or magnification?

## Proposed participant coverage

Subject to a separate consent and recruitment plan:

- first-time attendees;
- returning attendees;
- free-community-event organisers;
- paid-event organisers;
- people searching by location or accessibility need;
- mobile-primary users; and
- people using assistive technology.

This is coverage guidance, not evidence that participants have been recruited.

## Evidence needed before a product decision

- Observed completion of representative discovery tasks
- Participant language and comprehension
- Current support-demand themes
- Representative content and empty states
- Accessibility and mobile observations
- Target-environment analytics with clear definitions and privacy controls
- A documented problem statement that does not presume a feature

## Outcome

Technical discovery ownership is sufficiently mapped for research planning. User need and support demand are not sufficiently evidenced for an implementation decision.

The safe next action is to prepare the research protocol and evidence-collection plan. It is not to fix routes, filters, labels or ranking.
