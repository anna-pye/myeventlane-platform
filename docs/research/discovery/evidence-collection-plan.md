# Public Discovery Evidence-Collection Plan

| Field | Value |
| --- | --- |
| Status | Draft plan; collection not started |
| Protocol | [Public Discovery Research Protocol](research-protocol.md) |
| Initiative | [TRACE-NOW-01](../../product/initiatives/TRACE-NOW-01-discovery-research.md) |
| Date | 2026-07-26 |

## Purpose

This plan defines what evidence to collect, how to distinguish sources and what is required before a discovery problem is ready for a product decision.

## Evidence sources

| Source | What it can establish | What it cannot establish alone |
| --- | --- | --- |
| Moderated task sessions | Behaviour, comprehension, hesitation, recovery and participant language | Population prevalence |
| Context interviews | Prior experience, expectations and decision factors | Actual product behaviour without observation |
| Accessibility sessions | Barriers in real assistive-technology and device conditions | Conformance across every surface |
| De-identified support themes | Repeated operational friction and affected outcomes | Cause without investigation |
| Current repository inspection | Routes, owners, services, configuration and implementation facts | User need or usability |
| DDEV or target-environment probes | Current response, rendering and state evidence in that environment | Production behaviour unless production is tested |
| Governed analytics | Aggregate navigation and outcome patterns | Motivation, comprehension or individual intent |

Evidence sources should be triangulated. Analytics or technical evidence must not be used to overrule an observed accessibility barrier.

## Participant coding

Use non-identifying session codes:

```text
DISC-A01
DISC-A02
DISC-O01
```

- `A` may denote attendee research.
- `O` may denote organiser research.
- The number is sequential and carries no demographic meaning.

The mapping between a participant and a session code must not be stored in the repository.

## Task scenarios

Scenarios describe a goal and context, not interface instructions.

### TASK-D01 — Broad exploration

> You would like to do something in the next few weeks, but you have not decided what. Find an event you would consider attending.

Observe:

- starting point;
- information used to choose a result;
- interpretation of discovery labels;
- whether the person can explain the result scope; and
- confidence in the next step.

### TASK-D02 — Time and price

> Find something suitable for this weekend that does not require a paid ticket.

Observe:

- understanding of weekend and free/RSVP language;
- filter discovery and state;
- result relevance;
- recovery when results are limited; and
- whether “free” consequences are understood.

### TASK-D03 — Location intent

> Find an event that would be practical for you to attend nearby.

Observe:

- participant definition of nearby;
- expected location controls and precision;
- reaction to current `/events/nearby` behaviour;
- whether the interface over-promises location relevance; and
- privacy expectations for location use.

Do not claim that a nearby feature exists.

### TASK-D04 — Online intent

> Find an event you could attend online.

Observe:

- expected online or hybrid indicators;
- reaction to current `/events/online` behaviour;
- information needed to trust format and access; and
- recovery if a distinct online result set is not evident.

Do not teach or imply that the path has dedicated filtering.

### TASK-D05 — Label comprehension

> Explain what you expect Popular, Hidden Gems and Featured events to mean, and how those labels would affect your choice.

Observe:

- perceived ranking basis;
- perceived payment or editorial influence;
- fairness and trust expectations;
- whether labels suggest popularity, quality, locality or exclusivity; and
- desired explanation.

### TASK-D06 — Event decision

> Choose one result and decide whether you have enough information to take the next step.

Observe:

- continuity from listing to event detail;
- price, date, place, format, accessibility and organiser trust;
- CTA comprehension;
- back-navigation and preserved context; and
- distinction between RSVP and paid booking.

### TASK-D07 — Empty or unsuitable results

> Imagine none of the current results suit you. Show what you would do next.

Observe:

- recognition of the empty or unsuitable state;
- recovery paths;
- nearby, online, date, category or search expectations;
- whether the next action broadens or loses context; and
- abandonment signals.

### TASK-D08 — Participant-led task

Ask the participant to find an event using a real but non-sensitive goal of their own.

Observe differences between the planned scenarios and natural intent. Do not ask for private location, health or account information.

## Observation record

For each task record:

| Field | Guidance |
| --- | --- |
| Session code | Non-identifying code |
| Task | Scenario identifier |
| Device and access method | Participant-described, only as needed |
| Starting point | Where the participant began |
| Observable actions | Factual sequence |
| Outcome | Completed, completed with assistance, stopped or not completed |
| Hesitation | Where and what was observable |
| Participant language | Short de-identified phrase where consent permits |
| Barrier | Specific blocked or impaired outcome |
| Recovery | How the participant continued |
| Researcher interpretation | Clearly labelled interpretation |
| Confidence | High, medium or low, with reason |

Do not record unnecessary personal detail.

## Outcome measures

Use measures to support interpretation, not to manufacture precision:

- task outcome;
- assistance required;
- ability to explain current scope;
- ability to identify the next step;
- confidence in relevance;
- confidence in trust and consequences;
- recovery success; and
- accessibility barrier severity.

Time may be recorded as context where it helps explain hesitation. It is not a productivity target and should not be used to pressure participants.

## Finding severity

| Severity | Definition |
| --- | --- |
| Blocker | Prevents a core discovery outcome with no reasonable recovery |
| Major | Creates material misunderstanding, loss of trust or repeated failed recovery |
| Moderate | Causes hesitation or extra work but the participant can recover |
| Minor | Local friction with limited effect on outcome |
| Observation | Relevant behaviour or preference without demonstrated harm |

An accessibility, privacy or trust barrier may warrant high severity from a single credible observation. Frequency is not the only measure of importance.

## Support evidence

Support evidence should be aggregated into themes:

- task the person was trying to complete;
- product surface;
- point of confusion or failure;
- outcome blocked;
- frequency band rather than identifiable case details;
- available recovery; and
- whether the issue remains current.

Raw tickets and personal information must not be copied into the repository.

The support system, taxonomy, owner and permitted aggregation process are currently `Unknown`.

## Analytics evidence

Use existing governed measures only after confirming:

- event name and definition;
- collection purpose;
- consent and privacy basis;
- source and destination;
- date range;
- known data-quality limitations; and
- whether the measure reflects an outcome or merely activity.

Do not add tracking as part of this research plan. Any instrumentation gap requires a separate privacy-conscious decision.

Potential questions, subject to data-quality confirmation:

- Which discovery surfaces lead to an event detail?
- Which filters are used and retained?
- Where do people encounter no results?
- Do nearby or online links lead to distinct outcomes?
- Does a person return to discovery after viewing an event?

## Evidence matrix

The synthesis should use:

| Problem statement | Participant observations | Accessibility evidence | Support evidence | Analytics evidence | Repository evidence | Confidence | Contradictory evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| [Neutral problem] | [Session codes] | [Evidence] | [Aggregate] | [Measure] | [Path/owner] | High/Medium/Low | [Evidence] |

A blank column means evidence is absent, not that the problem does not exist.

## Decision-readiness rules

A problem may be ready for Product Owner consideration when:

- it is expressed as a human outcome rather than a requested feature;
- current behaviour and ownership are confirmed;
- participant evidence demonstrates the barrier or unmet need;
- contradictory evidence is visible;
- accessibility, trust, privacy, mobile and community effects are assessed;
- support or analytics evidence is included where relevant and available;
- the affected requirement is identified; and
- success can be observed without relying on clicks alone.

A proposal is not ready merely because:

- one participant requested a feature;
- a competitor has the feature;
- an old audit recommended it;
- a route returns an unexpected status;
- analytics show high or low traffic; or
- implementation appears easy.

## Planned outputs

1. De-identified session evidence
2. Evidence matrix
3. Research synthesis
4. Problem register
5. Accessibility barrier register
6. Decision-readiness recommendation

Raw research data remains outside Git in the approved research-data system.

## Collection readiness

| Gate | Status |
| --- | --- |
| Protocol drafted | Complete |
| Task scenarios drafted | Complete |
| Product Owner approval of protocol | Pending |
| Recruitment owner | Unknown |
| Consent wording | Unknown |
| Compensation | Unknown |
| Accessible participation process | Unknown |
| Research-data storage | Unknown |
| Retention and deletion | Unknown |
| Support aggregation authority | Unknown |
| Analytics definitions and privacy review | Unknown |

Evidence collection must not start until the required operational gates are resolved.

