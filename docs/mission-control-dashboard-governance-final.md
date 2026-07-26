# Mission Control Principles

> **Status:** Historical implementation governance
>
> **Current design authority:** [Vendor Studio Dashboard Philosophy](design/vendor-studio/12-dashboard-philosophy.md)
>
> **Classification decision:** [PDR-001](product-decisions/PDR-001-governance-baseline-authority.md)
> “Final” is part of the historical filename and does not establish current authority.

Mission Control is the organiser's operational home. It gives calm awareness, one current priority, one current event focus, lightweight multi-event visibility, sparse activity intelligence, and fast movement into contextual surfaces.

Mission Control is not an analytics wall, onboarding wizard, notification centre, admin console, lifecycle dashboard, Stripe dashboard, Commerce dashboard, refund dashboard, or AI retrieval surface.

# Dashboard Hierarchy Rules

Canonical hierarchy:

1. Priority Attention
2. Current Event Hero
3. Organiser Overview Strip
4. Quick Metrics
5. Quick Actions
6. Operational Activity Stream
7. Upcoming Events
8. Expandable Operational Panels

No section may outrank Priority Attention or the Current Event Hero unless product governance changes this hierarchy.

# Event-First Rules

- The dashboard has one primary event focus.
- The current event is selected by `VendorDashboardViewModelBuilder`.
- Twig must not rank events or duplicate event selection logic.
- Current-event details may show title, date, status, booking state, attendee summary, event media, quick actions, and lightweight metrics.
- Readiness percentages, readiness walls, analytics graphs, and lifecycle walls must not return to the hero.

# Multi-Event Awareness Rules

- Multi-event awareness is compact and operational.
- The organiser overview strip may show live events, draft events, upcoming events, bookings, priority items, and payout readiness when sourced from existing payloads.
- Multi-event awareness must not become a large event grid, analytics dashboard, or alert centre.
- The dashboard must not invent refund, payout, booking, attendee, or event query systems.

# Operational Stream Rules

- Operational stream items are sparse and meaningful.
- Items may be derived from existing event summary payloads, readiness state, and existing dashboard-safe activity payloads.
- The stream must remain short and homepage-safe.
- No real-time polling, infinite timelines, social-feed framing, or notification spam.

# Activity Feed Rules

- If a canonical activity service exists, use it.
- If no canonical activity service exists, reuse event summary payloads.
- Do not invent fake activity systems.
- Do not expose private support, staff, refund, payout, or AI detail in dashboard activity.

# Upcoming Event Rules

- Upcoming events use the existing dashboard event row payload.
- Cards remain compact and horizontally scannable.
- Each card may show title, date, status, lightweight state chips, metric label, and an existing event link.
- Do not duplicate event teaser systems or create heavy grids.

# Progressive Disclosure Rules

- Readiness, operational readiness, lifecycle guidance, secondary action queue items, promotion guidance, event roster detail, billing nudges, contribution prompts, and boost surfaces stay secondary.
- Use existing details/accordion systems and MEL live-ops styles.
- Deep intelligence belongs in contextual surfaces.

# Mobile Mission Control Rules

Mobile starts with the operational minimum: priority alert, current event, organiser overview, quick actions, and activity stream. Secondary sections must be collapsed, compact, or horizontally scrollable.

Do not stack giant cards into a long-scroll dashboard.

# Visual Hierarchy Rules

- Use white surfaces, subtle borders, and restrained pastel accents.
- Pastels indicate action, alert, or active state.
- Avoid oversized pastel blocks, competing containers, oversized cards, and giant whitespace gaps.
- Density should improve awareness without reviving dashboard fatigue.

# Contextual Intelligence Boundaries

- Event editor owns publishing guidance, visibility guidance, and ticket setup guidance.
- Attendees owns check-in readiness, attendee momentum, and door guidance.
- Promote event owns discovery guidance, banner suggestions, and momentum guidance.
- Analytics owns deep metrics, graphs, and trend analysis.
- Payouts/settings/Stripe surfaces own payout detail.
- Event workspace refund requests and escalation refund summaries own refund detail.
- Dashboard owns operational awareness only.

# What Must Never Return To Dashboard

- Readiness percentages or readiness score walls.
- Giant lifecycle dashboards.
- Large analytics panels, charts, graphs, or trend analysis.
- Fake activity feeds.
- Notification spam.
- New onboarding systems.
- New activity engines.
- New refund or payout engines.
- Stripe, Commerce, AI, support, or access rewrites.

# What Was Consolidated

- The dashboard now keeps one primary action from `VendorActionQueueBuilder`.
- Multi-event awareness is consolidated into a compact organiser overview strip.
- Operational activity is consolidated into a short stream sourced from existing event/readiness payloads.
- Upcoming event awareness is consolidated into a compact row instead of a heavy roster.
- The current event hero was visually tightened while retaining current-event clarity.

# What Became Contextual

- Readiness detail remains collapsed in operational panels.
- Lifecycle guidance remains collapsed.
- Event roster detail remains secondary.
- Promotion and boost guidance remain secondary or contextual.
- Billing and contribution prompts remain footer micro surfaces.
- Refund detail remains on event workspace refund surfaces.
- Payout detail remains on payout/settings/Stripe surfaces.
- Deep analytics remain on analytics surfaces.

# Canonical Dashboard Owner

The canonical dashboard owner remains `myeventlane_vendor.console.dashboard`, `VendorDashboardViewModelBuilder`, and `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`.

# Operational Awareness Boundaries

Mission Control may answer:

- What needs attention now?
- Which event is the current focus?
- What is happening across my organiser workspace?
- What lightweight activity changed recently?
- Where should I go next?

Mission Control must not answer deep analytics, refund investigation, payout reconciliation, staff support, AI retrieval, Commerce accounting, or Stripe account-management questions.

# Future Mission Control Governance

- Extend the canonical view model rather than adding dashboard builders.
- Reuse existing summaries and access-checked links.
- Add dashboard sections only when they fit the canonical hierarchy.
- Keep access enforcement server-side.
- Keep dashboard presentation Drupal 11 compliant, cache-safe, translation-safe, mobile-safe, accessible, onboarding-safe, AI-safe, trust-safe, and support-safe.
