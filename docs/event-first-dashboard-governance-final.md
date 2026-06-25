# Event-First Dashboard Principles

The organiser dashboard is event-first, not system-first. It starts with the organiser's current or most important event, then shows only the operational signals needed to decide what to do next.

The refactor consolidates dashboard attention around existing systems: `VendorDashboardViewModelBuilder`, `VendorActionQueueBuilder`, `MelReadinessHelper`, event state resolution, existing metrics summaries, existing route/access checks, and existing MEL live-ops/card styling.

# Dashboard Hierarchy Rules

Canonical order:

1. Priority Attention
2. Current Event Hero
3. Quick Metrics Strip
4. Quick Actions
5. Activity Feed
6. Secondary Guidance
7. Expandable Operational Panels

No dashboard section may outrank the current event unless it is the single priority attention card.

> Reconciliation (2026-06-24): No section should outrank the active event
> operationally, but the organiser remains the dashboard owner and the
> accessibility H1. "Event-first" is a visual/operational dominance rule, not an
> ownership or heading-level rule — the event title is an H2. See
> `dashboard-vs-workspace-governance.md`.

# Alert Priority Rules

- Show one primary alert only.
- Source the alert from `VendorActionQueueBuilder`.
- Put additional action queue items inside secondary disclosure.
- Never rebuild priority scoring in Twig.
- Never stack dashboard alerts above the current event.

# Event Hero Rules

- Show one current event focus.
- Use existing event view-model data.
- Show title, image/banner, date, visibility state, booking state, attendee summary, and event actions.
- Use human-readable operational summaries.
- Do not return readiness rings, giant readiness percentages, or gamified completion meters to the dashboard hero.

# Metrics Strip Rules

- Keep dashboard metrics lightweight.
- Prefer event-level bookings, attendees, RSVPs, revenue, and capacity.
- Use existing metric summaries and decorated KPI rows.
- Keep graphs and deep analytics on analytics pages or event-specific analytics.

# Progressive Disclosure Rules

- Readiness details, lifecycle guidance, operational guidance, growth suggestions, event rosters, and analytics snippets are secondary.
- Use collapsible disclosure for operational intelligence.
- On mobile, the initial view must prioritise primary alert, current event, metrics, and quick actions.

# Contextual Intelligence Rules

- Dashboard shows current operational state, current event, and important next action.
- Event editor owns publishing, visibility, and ticket setup guidance.
- Attendee pages own attendee readiness, check-in readiness, and attendee momentum.
- Promote event pages own discovery, banner, promotion, and momentum guidance.

# Mobile Dashboard Rules

- Initial mobile order is priority alert, current event, metrics strip, quick actions.
- Secondary intelligence remains collapsed.
- Use existing MEL responsive grids and live-ops spacing.
- Do not introduce a new responsive framework.

# Visual Hierarchy Rules

- White surfaces and subtle borders are the default.
- Pastel accents are reserved for states, alerts, and actions.
- Avoid competing cards with equal visual weight.
- Keep spacing generous enough that the dashboard feels calm.

# Operational Surface Boundaries

The dashboard must not become:

- A full analytics dashboard.
- A notification centre.
- A Stripe or Commerce rewrite.
- A readiness engine.
- A lifecycle engine.
- An onboarding engine.
- An AI retrieval surface.

# What Was Consolidated

- Action queue items now produce one visible priority card.
- The event list now supports one primary current event hero.
- Event metrics are presented as a lightweight dashboard strip.
- Quick actions are tied to the current event.
- Activity is lightweight and sourced from existing dashboard/event summaries.

# What Was Relocated

- Readiness details moved into `Review event setup`.
- Lifecycle guidance moved into `Review event setup`.
- Secondary action queue recommendations moved into disclosure.
- Growth and promotion guidance moved into secondary panels.
- Billing/contribution and boost opportunity surfaces moved into footer micro surfaces.

# What Stayed Contextual

- Event setup guidance stays tied to event/editor surfaces.
- Attendee and check-in readiness stay tied to attendee/check-in surfaces.
- Promotion and discovery guidance stay secondary to the dashboard and should live primarily on promote/event marketing surfaces.
- Detailed analytics stay outside the dashboard homepage.

# Canonical Dashboard Owner

The canonical organiser dashboard owner remains `myeventlane_vendor.console.dashboard` and `VendorDashboardViewModelBuilder`, rendered by `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`.

# Canonical Operational Intelligence Boundaries

Operational intelligence is allowed on the dashboard only when it answers one of these questions:

- What needs attention now?
- Which event am I operating right now?
- How is that event doing at a glance?
- What action can I take immediately?

Anything broader belongs in a contextual page, secondary panel, analytics surface, help surface, settings, billing, or event workspace.

# Future Dashboard Governance

- Add new dashboard sections only when they support the canonical hierarchy.
- Prefer extending `VendorDashboardViewModelBuilder` over adding parallel dashboard builders.
- Reuse `VendorActionQueueBuilder` for prioritisation.
- Reuse `MelReadinessHelper` for readiness and lifecycle wording.
- Keep access enforcement server-side.
- Keep dashboard presentation cache-safe, translation-safe, mobile-safe, support-safe, and trust-safe.
