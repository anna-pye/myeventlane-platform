# VX2-08 — Analytics Hub (Event Intelligence Centre)

**Epic:** VX2-08 Analytics  
**Branch:** `feature/vx2-analytics`  
**Date:** 2026-07-24

## What shipped

- Organiser **Analytics** hub at `/vendor/analytics` reframed as the **Event Intelligence Centre**
- Sections: Business Health · Launch Readiness · Next action · Sales · Attendance · Revenue · Marketing · Audience · Top Events · Recent Activity · Pro / Exports
- Free organisers can open the hub (bare Pro **403 removed**)
- Pro depth explained in-product with upgrade CTAs (never a hard deny on the hub)
- Legacy **Insights** and **Export Centre** list URLs redirect into Analytics
- Per-event rows deep-link to Event Workspace Analytics (free pulse) + optional Pro deeper trends
- AU English, warm, actionable copy — not accounting software

## Architecture

```text
/vendor/analytics  ← AnalyticsDashboardController
  └─ VendorAnalyticsViewModelBuilder
       ├─ MetricsAggregator / TicketSalesService / RsvpStatsService
       ├─ VendorPaymentsHealthService (Stripe readiness)
       ├─ CurrentVendorResolver (Messages brand readiness)
       ├─ optional VendorProState
       └─ optional refunds repository/metrics
```

Convergence redirects:

```text
/vendor/insights                 → /vendor/analytics
/vendor/events/{id}/insights*    → /vendor/events/{id}/studio/analytics
/vendor/exports                  → /vendor/analytics#exports
```

## Free vs Pro

| Free | Pro |
| --- | --- |
| Business + launch pulse | Longer trends (deep event Analytics) |
| Event intelligence rows | Comparisons / advanced segmentation (teased) |
| Recent activity + next action | PDF / Excel exports |

## Instrumentation

| Event | Where | Pipeline |
| --- | --- | --- |
| `analytics_viewed` | Hub builder logger + Twig data attribute | Deferred collector |
| `pro_upgrade_clicked` | Twig upgrade CTAs | Deferred collector |
| `pro_upgrade_completed` | Existing Pro flows | Existing |

Do not invent new telemetry tables in this epic.

## Manual QA

- [ ] Free organiser opens `/vendor/analytics` (no 403)
- [ ] Business Health answers “How is my business performing?”
- [ ] Launch Readiness answers “Can I successfully run this event today?”
- [ ] Tickets / Stripe / Messages / Door / Refunds / Publishing signals present
- [ ] Event card → Event Analytics (Studio)
- [ ] `/vendor/insights` redirects to Analytics
- [ ] `/vendor/exports` redirects to `#exports`
- [ ] Pro CTA explains value (no bare deny)
- [ ] Desktop / tablet / 390px
- [ ] Keyboard focus order through health → readiness → actions → events
- [ ] Screen reader: section headings, KPI aria-labels, status text (not colour alone)
- [ ] `prefers-reduced-motion` respected

## Remaining roadmap

- Wire product analytics collector for documented events
- Audience deep segments under Pro
- Merge Boost performance panels into Marketing section depth (P3)
- Retire orphan Insights controllers after redirect bake-in
- Optional: move Audience shell page under Analytics nav
