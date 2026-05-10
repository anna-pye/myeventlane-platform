# Event-First Dashboard Audit

## Inspected Sources

- `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Service/VendorActionQueueBuilder.php`
- `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`
- `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard-live-ops.scss`
- `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_mel-dashboard.scss`
- `web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.module`
- `web/modules/custom/myeventlane_event/myeventlane_event.module`

## Current Structure Audit

| Surface | Current Purpose | Keep | Move | Collapse | Remove |
|----------|----------------|------|------|-----------|--------|
| Vendor dashboard route | `/vendor/dashboard` renders `myeventlane_vendor.controller.vendor_dashboard:dashboard` through `myeventlane_vendor_dashboard` using `myeventlane_vendor_theme`. | Yes. Existing route, theme option, and access callback stay canonical. | No. | No. | No. |
| `VendorDashboardViewModelBuilder` | Builds vendor payload, readiness, operational readiness, lifecycle guidance, KPI strip, events, analytics availability, action queue, hero hint, and empty state. | Yes. Reuse as canonical dashboard view model. | No. | Presentation output can be reorganised by Twig. | No. |
| `VendorActionQueueBuilder` | Builds prioritised action queue from dashboard model, readiness, event state, route availability, and access checks. | Yes. | Secondary recommendations move behind progressive disclosure. | Yes, all but one visible priority action. | No. |
| Readiness payload | Tracks profile, public profile, Stripe readiness, score, labels, and readiness row state. | Yes. | Into `Review event setup`. | Yes. | Remove giant score/ring presentation only. |
| Operational readiness payload | Summarises publishing, bookings, payouts, attendees, and discovery with `MelReadinessHelper`. | Yes. | Into `Review event setup`. | Yes. | No. |
| Lifecycle guidance payload | Summarises lifecycle stages from existing readiness helper logic and analytics availability. | Yes. | Into `Review event setup`. | Yes. | No. |
| KPI strip | Decorated through `MelDataPresentationManager`; previously shown as a performance snapshot after multiple guidance panels. | Yes. | Immediately below current event hero. | No, but keep lightweight. | No charts/KPI walls on dashboard homepage. |
| Events roster | Lists up to six managed events with image, state, type, metrics, links, and removal UI. | Yes. | Under secondary event list. | Yes. | No. |
| Current event data | Existing event rows include title, status, date label, type, capacity, metrics, image, presentation issues, removal, and links. | Yes. | Promote one selected event into dashboard hero. | No. | No. |
| Quick actions | Existing top quick bar showed several action queue CTAs. | Yes. | Convert to current-event actions. | No. | Remove stacked priority-action quick bar pattern. |
| Activity feed | `VendorDashboardController::buildDashboardActivity()` exists and the builder can derive lightweight event-state activity from event summaries. | Yes. | Below quick actions. | Yes on dashboard as a details panel. | No fake activity service. |
| Analytics summary | Builder currently exposes availability and empty items; controller has activity and KPI data. | Yes. | Detailed analytics stay on analytics/event analytics pages. | Yes if rendered on dashboard. | No graphing on dashboard homepage. |
| Growth cards | Controller builds growth cards through existing growth insight/tracking services. | Yes. | Secondary guidance. | Yes. | No. |
| Boost/promote surfaces | Existing boost routes and dashboard top boost opportunity are presentation surfaces. | Yes. | Footer or secondary panels. | Yes. | No Stripe/Commerce/Boost flow changes. |
| Billing/contribution strip | Existing contribution strip is injected as `mel_contribution_billing_strip`. | Yes. | Footer micro surface/settings/billing context. | Yes. | No. |
| Onboarding panel | Existing onboarding manager builds a panel when vendor setup is incomplete. | Yes. | `Review event setup`. | Yes. | No new onboarding engine. |
| Contextual help | Help preprocessors attach dashboard help/support content. | Yes. | Keep contextual and secondary. | Yes when part of dashboard chrome. | No public/staff boundary changes. |
| Shell navigation | Vendor console shell is route/theme driven; page template delegates to vendor layout. | Yes. | No. | No. | No. |
| Preprocessors | Dashboard, event, help, donations, pro, automation, and launch modules enrich existing variables. | Yes. | No logic duplication in Twig. | Presentation only. | No. |
| Mobile layout | Live-ops primitives and vendor dashboard SCSS provide responsive grids and spacing. | Yes. | Current dashboard order should start with priority, event, metrics, actions. | Yes for operational intelligence. | No new responsive framework. |

## Audit Conclusion

The dashboard already has the required backend systems: view model builder, readiness helper, lifecycle summaries, action queue, metrics, event state, shell, contextual help, and secondary promotional/billing surfaces. The overload comes from presentation order and visual weighting, not from missing backend engines.

The refactor should therefore keep routing, access, data ownership, readiness logic, lifecycle logic, and analytics sources intact while changing the homepage hierarchy to event-first progressive disclosure.
