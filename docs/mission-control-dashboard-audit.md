# Mission Control Dashboard Audit

## Inspected Sources

- `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorActionQueueBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/MetricsAggregator.php`
- `web/modules/custom/myeventlane_vendor/src/Service/TicketSalesService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/UserVendorMembershipQuery.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorPayoutsController.php`
- `web/modules/custom/myeventlane_core/src/MelReadinessHelper.php`
- `web/modules/custom/myeventlane_core/src/Service/EventStateResolver.php`
- `web/modules/custom/myeventlane_escalations_refunds/src/Service/RefundsMetricsCalculator.php`
- `web/modules/custom/myeventlane_escalations_refunds/src/Service/RefundsAccessGuard.php`
- `web/modules/custom/myeventlane_stripe/src/Service/VendorStripeService.php`
- `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard-live-ops.scss`

## Current Structure Audit

| Surface | Current Purpose | Keep | Reduce | Relocate | Expand |
|----------|----------------|------|---------|-----------|--------|
| Vendor dashboard route | Renders the organiser dashboard through the canonical vendor console controller and theme. | Yes. Keep `myeventlane_vendor.console.dashboard` and existing access chain. | No route changes. | No. | No. |
| `VendorDashboardViewModelBuilder` | Builds vendor payload, readiness, operational readiness, lifecycle guidance, KPIs, event rows, action queue, current event, activity items, analytics availability, and empty state. | Yes. This remains the canonical dashboard model owner. | Reduce homepage weight by changing presentation and derived compact payloads. | No. | Add only derived mission-control payloads from existing model data. |
| Dashboard event selection logic | Uses `UserVendorMembershipQuery`, loads managed events, ranks active/published/draft/upcoming/past events, and selects `events[0]` as the current event. | Yes. | No duplicate selection logic in Twig. | No. | No. |
| Current event hero | Presents the primary event title, status, booking state, date, attendee summary, media, and primary event action. | Yes. | Reduce large visual weight and oversized media. | No. | Clarify that this is one event focus inside a broader organiser home. |
| Quick metrics | Uses current event `metrics` when available, otherwise decorated vendor KPIs from `MelDataPresentationManager`. | Yes. | Keep to five compact signals. | Deep analytics stay off the dashboard. | No charts or trend walls. |
| Quick actions | Uses existing event quick actions built with route/access checks. | Yes. | Keep compact and current-event scoped. | No. | No. |
| Action queue | `VendorActionQueueBuilder` prioritises readiness, draft events, Stripe payout setup, missing booking setup, and analytics availability using existing model data. | Yes. | Show one primary alert. | Secondary recommendations stay collapsed. | No duplicate priority scoring. |
| Readiness panels | Existing readiness payload uses `MelReadinessHelper` and vendor/store fields. | Yes. | Keep out of initial dashboard view. | Collapsed `Review event setup`. | No new readiness system. |
| Lifecycle panels | Existing lifecycle summary comes from `MelReadinessHelper`. | Yes. | Keep out of initial dashboard view. | Collapsed operational panels. | No new lifecycle system. |
| Organiser overview strip | Not previously present as a compact cross-organiser row. | Yes, as a derived presentation payload. | Keep tiny, count-based, and operational. | No. | Add from existing event rows, KPI rows, readiness, and action queue only. |
| Operational activity stream | Existing builder derived sparse event-state activity; controller also has older dashboard activity logic. | Yes. | Remove feed-like framing and keep a short list. | No fake feed or polling. | Improve wording from existing event/readiness payloads only. |
| Upcoming events strip | Existing event rows include title, date, status, booking state, metrics, and links. | Yes. | Avoid large event cards and duplicate teaser systems. | Full roster remains collapsed. | Add compact row from existing dashboard event rows. |
| Event summary builders | Dashboard and event index builders already compose event state, ticket sales, RSVP counts, thumbnails, route links, and removal payloads. | Yes. | Avoid adding a third event card model. | Full event management stays on events/event workspace pages. | No. |
| Analytics summaries | Dashboard exposes availability only; `VendorAnalyticsViewModelBuilder` owns deeper analytics. | Yes. | Do not surface charts or deep metrics on homepage. | Analytics page and event analytics remain owners. | No. |
| Attendee summaries | Dashboard event rows already expose attendee summary from ticket and RSVP counts. | Yes. | Keep single-line state. | Attendee and check-in pages own deeper readiness. | No. |
| Booking summaries | `TicketSalesService`, `RsvpStatsService`, and event row metrics already expose lightweight booking counts. | Yes. | Keep count-only on dashboard. | Orders, RSVPs, and ticket setup stay contextual. | No. |
| Support summaries | Dashboard has event support quick action and contextual help surfaces. | Yes. | Keep support entry points secondary. | Support page/help surfaces own support detail. | No. |
| Payout summaries | Vendor payout page has payout summary and Stripe management URL; dashboard readiness has Stripe payout readiness. `VendorStripeService` can call Stripe and is not dashboard-safe for page-load expansion. | Keep readiness signal only. | Do not query Stripe from dashboard. | Payout detail stays on payouts/settings/Stripe surfaces. | No dashboard payout engine. |
| Refund summaries | Refund request routes and escalation refund metrics exist, but vendor-level refund summary access is permission-gated and contextual. No canonical dashboard-safe refund summary payload was found. | Keep contextual refund routes. | Do not invent refund counts. | Event workspace refund requests and escalation refund summaries. | No dashboard refund engine. |
| Billing/contribution surfaces | Existing contribution strip is injected into the dashboard template. | Yes. | Keep as footer micro surface. | Billing/settings/contextual side surfaces. | No. |
| Promotional/boost surfaces | Existing boost and growth cards exist. | Yes. | Keep below operational focus. | Promote event and secondary panels. | No. |
| Mobile layout | Existing MEL live-ops and dashboard SCSS provide responsive grids and horizontal strips. | Yes. | Avoid endless stacked cards. | Secondary intelligence remains collapsed. | Expand compact horizontal mission-control rows only. |

## Audit Conclusion

The dashboard already has the required operational systems: canonical view-model builder, event membership query, event state resolver, ticket and RSVP summaries, readiness helper, lifecycle helper, action queue, analytics owner, payout page, refund contextual routes, support links, and vendor shell. The refinement should therefore add only compact derived presentation payloads and Twig/SCSS hierarchy changes.

No canonical dashboard-safe refund activity service was found. Refund intelligence must remain contextual unless a future owner exposes an access-safe summary payload. No Stripe or Commerce flow should be changed for this dashboard refinement.
