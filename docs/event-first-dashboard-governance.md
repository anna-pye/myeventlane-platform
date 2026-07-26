# Event-First Dashboard Governance

> **Status:** Historical implementation governance
>
> **Current design authority:** [Vendor Studio Dashboard Philosophy](design/vendor-studio/12-dashboard-philosophy.md)
>
> **Classification decision:** [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md)
> Retained for traceability. This document does not authorise current product or design changes.

## Canonical Dashboard Hierarchy

1. Priority Attention
2. Current Event Hero
3. Quick Metrics Strip
4. Quick Actions
5. Activity Feed
6. Secondary Guidance
7. Expandable Operational Panels

## Priority Attention

The dashboard shows one visible priority card sourced from `VendorActionQueueBuilder`.

Rules:

- Render only the first priority action as the primary dashboard alert.
- Keep remaining action queue items in a secondary expandable area.
- Do not duplicate priority logic in Twig or a new service.
- Do not stack alerts above the event hero.
- Keep action URLs sourced from existing route/access logic.

## Current Event Hero

The current event is the primary dashboard surface. It is selected from the existing event rows built by `VendorDashboardViewModelBuilder`.

Rules:

- Show one event focus only.
- Use existing event image, title, date, visibility state, booking state, attendee summary, and event links.
- Prefer human-readable operational copy over readiness percentages.
- Do not show readiness rings, gamified completion meters, or giant setup scores in the hero.
- If there are no events, the hero becomes the existing empty state.

## Quick Metrics Strip

Metrics stay lightweight and operational.

Allowed dashboard metrics:

- Bookings
- Attendees
- RSVPs
- Revenue
- Capacity or similarly direct operational state

Rules:

- Use existing event metrics or existing decorated dashboard KPI rows.
- Do not introduce charts or analytics dashboards on the organiser dashboard homepage.
- Keep detailed analytics on analytics pages or event-specific analytics surfaces.

## Quick Actions

Quick actions are event-first.

Canonical actions:

- Edit event
- View attendees
- Open check-in
- Share event
- Promote event
- Open support

Rules:

- Reuse existing route generation and route access checks.
- Do not hardcode permission decisions in Twig.
- Do not create duplicate route names or placeholder workflow engines.

## Activity Feed

The dashboard may show lightweight activity if it is sourced from existing systems.

Allowed sources:

- Existing dashboard activity rows.
- Existing event summaries.
- Existing operational state from the event view model.
- Existing metric summaries.

Rules:

- Do not invent a fake feed.
- Do not create a new activity service in this refactor.
- If no activity exists, show a calm empty message.

## Progressive Disclosure

Readiness, lifecycle guidance, operational guidance, growth suggestions, and secondary event lists are secondary dashboard surfaces.

Rules:

- Use native details/accordion-style disclosure or existing MEL disclosure primitives.
- Keep guidance discoverable but not visually dominant.
- Keep mobile initial view focused on priority, current event, metrics, and quick actions.

## Contextual Intelligence Boundaries

Dashboard:

- Current operational state.
- Current event.
- Important next action.

Event editor:

- Publishing guidance.
- Visibility guidance.
- Ticket setup guidance.

Attendee page:

- Attendee readiness.
- Check-in readiness.
- Attendee momentum.

Promote event page:

- Discovery guidance.
- Banner suggestions.
- Promotion guidance.
- Momentum guidance.

## Visual Hierarchy Rules

- Use white surfaces and subtle borders for core dashboard regions.
- Use pastel accents for states, actions, and alerts only.
- Avoid stacked pastel containers.
- Keep one primary action visible per region.
- Preserve accessible focus states and touch-friendly action sizing.

## Operational Surface Boundaries

The organiser dashboard is not an analytics product, onboarding engine, lifecycle engine, notification centre, Stripe console, Commerce console, or AI retrieval surface.

It is a calm operational starting point for the organiser's current event.
