# Public Discovery Research Protocol

| Field | Value |
| --- | --- |
| Status | Deferred draft; recruitment not authorised |
| Initiative | [TRACE-NOW-01](../../product/initiatives/TRACE-NOW-01-discovery-research.md) |
| Owner | Product Owner |
| Version | 0.1 |
| Date | 2026-07-26 |
| Governing parent | [Organiser Manifesto](../../governance/00-organiser-manifesto.md) |

## Purpose

This protocol defines how MyEventLane will learn what organisers and attendees need from public event discovery without presuming a feature or treating technical behaviour as user evidence.

It governs moderated research planning. It does not authorise recruitment, analytics changes, implementation or collection of personal information before the outstanding operational decisions are completed.

Further work under this protocol was deferred by the Product Owner on 26 July 2026 to prioritise the organiser experience. Preserve this document as a reusable draft; do not treat it as an active delivery commitment.

## Research objectives

The research should establish:

1. what information people use to decide whether an event is relevant;
2. how people express place, time, price, accessibility and event-type intent;
3. whether discovery labels communicate their intended meaning;
4. what people expect from “Nearby” and “Online”;
5. where people hesitate between discovery, event detail, RSVP and paid booking;
6. how people recover from empty, unavailable or unexpected results;
7. which barriers are amplified on mobile or with assistive technology; and
8. which observed problems align with support demand or product evidence.

## Research questions

### Relevance and comprehension

- What tells a person that an event is worth opening?
- Which event details must be visible before they will continue?
- How do they interpret labels such as Popular, Hidden Gems, Featured and Free?
- Can they distinguish editorial, community and paid-promotional signals?

### Location and format

- What does “nearby” mean to the participant?
- Do they expect distance, suburb, postcode, travel time or a chosen area?
- What does “online” mean, and what information makes an online event trustworthy?
- How should hybrid events appear?

### Journey and recovery

- Can the participant move from an intent to a relevant event?
- Do filters and result states make the current scope understandable?
- What does the participant do when no event matches?
- Is the next useful action clear without forcing a booking decision?

### Trust and accessibility

- Can the participant understand price, availability, organiser identity and event format?
- Which language or interaction creates uncertainty?
- Are discovery tasks operable using the participant's usual device and assistive technology?
- Does the experience preserve orientation after filtering, navigation and backtracking?

## Method

Use moderated, task-based qualitative sessions supported by a short contextual interview.

- Conduct sessions using representative public discovery content.
- Ask participants to think aloud only when comfortable; silence is not failure.
- Observe behaviour before asking for explanation.
- Use neutral follow-up questions.
- Do not teach the interface during a task unless the participant chooses to stop.
- Record facts, interpretations and recommendations separately.

Repository audits, aggregate support evidence and existing analytics may contextualise findings. They do not replace participant research.

## Participant coverage

The initial wave should seek 8-12 participants across the coverage below. This is a planning range for qualitative learning, not a statistically representative sample or a completion target by itself.

Coverage should include:

- people attending their first event through MyEventLane or a comparable service;
- returning event attendees;
- organisers of free or grassroots events;
- organisers of paid events;
- people who primarily use a mobile device;
- people who search by place, accessibility or transport need; and
- people who use a keyboard, screen reader, magnification or another assistive technology.

No single participant needs to represent more than their own experience. Recruitment should avoid relying only on technically confident, metropolitan or frequent event-platform users.

## Recruitment gates

Recruitment must not begin until the Product Owner confirms:

- recruitment owner;
- participant source;
- inclusion and exclusion criteria;
- accessibility accommodations;
- compensation approach;
- consent wording;
- recording approach;
- research-data storage;
- retention and deletion period; and
- contact and withdrawal process.

Unknown values must remain `Unknown`; they must not be filled by assumption.

## Consent and participant care

Before a session, participants must receive plain-language information covering:

- why the research is being conducted;
- what participation involves;
- expected duration;
- what information will be collected;
- whether audio, video or screen activity will be recorded;
- who may access the information;
- how findings will be de-identified and used;
- storage, retention and deletion;
- that participation is voluntary;
- that they may skip a question, pause or stop;
- how to withdraw information where practicable; and
- whom to contact with a question or concern.

Consent to participate and consent to record must be separate choices. A participant who declines recording may still participate where reliable notes can be taken.

Researchers must not ask participants to disclose payment credentials, private tickets, private messages, health information or other sensitive information not required by the research question.

## Accessibility and accommodations

- Ask participants what they need rather than assuming.
- Provide research information in an accessible format.
- Allow the participant to use their own device, browser, settings and assistive technology where practical.
- Avoid time pressure.
- Offer breaks and alternative communication methods.
- Ensure incentives and scheduling do not disadvantage participants requiring more time or support.
- Record the barrier and context, not a judgement about the participant.

An accessibility barrier affecting one participant may be material even when no other participant encounters it.

## Session structure

### 1. Welcome and consent

- Introduce the purpose and roles.
- Confirm voluntary participation and recording choice.
- Explain that the product is being evaluated, not the participant.
- Confirm any accommodation.

### 2. Context interview

Ask about recent event-finding behaviour without asking for private account details:

- Tell me about the last time you looked for something to attend.
- What did you know before you started?
- What made an event feel relevant or trustworthy?
- What made the search difficult?

### 3. Discovery tasks

Use the task scenarios in the [evidence-collection plan](evidence-collection-plan.md). Rotate scenario order where practical to reduce ordering effects.

### 4. Reflection

- What felt clear?
- Where were you uncertain?
- Was anything missing when you needed it?
- What did you expect to happen that did not happen?
- What would make you comfortable taking the next step?

### 5. Close

- Invite final comments.
- Explain how the evidence will be used.
- Restate withdrawal and contact information.
- Confirm incentive arrangements without tying them to positive feedback.

## Facilitator rules

Facilitators must:

- avoid leading language and feature suggestions;
- avoid defending or explaining the current product;
- distinguish observed behaviour from participant explanation;
- capture exact language sparingly and only with consent;
- never promise that a requested feature will be built;
- not infer ability, motivation or intent from a mistake; and
- stop a task where continued participation creates distress or privacy risk.

## Data handling

The repository may contain:

- this protocol;
- de-identified evidence matrices;
- aggregated themes;
- problem statements; and
- approved decision records.

The repository must not contain:

- participant names or contact details;
- raw recordings;
- raw transcripts;
- private support messages;
- account identifiers;
- payment or ticket credentials; or
- re-identifiable combinations of demographic information.

The approved storage system and retention period are currently **Unknown**. No recording or transcript collection is authorised until they are decided.

## Analysis standard

Analysis must:

1. separate observation, participant statement and researcher interpretation;
2. retain contradictory evidence;
3. identify participant and task context without re-identifying the person;
4. distinguish accessibility barriers from preference;
5. compare findings with repository and support evidence;
6. record confidence and evidence limits; and
7. avoid converting a requested solution directly into a requirement.

## Stopping rule

The initial wave may conclude when:

- required coverage has been attempted;
- the team can describe the main discovery problems in participant language;
- new sessions predominantly clarify known patterns rather than reveal materially new ones; and
- important contradictory or accessibility evidence is preserved.

The Product Owner decides whether further coverage is required. Participant count alone does not establish saturation.

## Outputs

- Session evidence records using de-identified participant codes
- Evidence matrix
- Research synthesis
- Ranked problem statements
- Accessibility barriers
- Unanswered questions
- Recommendation on whether a bounded product decision is ready

No output authorises implementation without the initiative delivery gate.

## Outstanding approvals

| Decision | Status |
| --- | --- |
| Research objectives and questions | Proposed |
| Recruitment owner and source | Unknown |
| Participant compensation | Unknown |
| Consent wording | Unknown |
| Recording approach | Unknown |
| Data storage | Unknown |
| Retention and deletion period | Unknown |
| Accessibility accommodations process | Unknown |
