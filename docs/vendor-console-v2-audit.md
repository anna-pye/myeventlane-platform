# MEL Vendor Console v2 — baseline audit (TASK 0)

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Inventory only — no production PHP/Twig/theme edits in TASK 0 beyond this document.

## Baseline commands (reference)

Executed from repository root after branch creation:

```bash
git status -sb
find web/modules/custom -maxdepth 2 -type f -name "*.routing.yml" | sort
find web/modules/custom -maxdepth 3 -type f -name "*.services.yml" | sort
find web/modules/custom -maxdepth 5 -type f | grep -E "Dashboard|Vendor|EventStudio|Analytics|Ticket|Rsvp|RSVP|Attendee|Stripe|Brand|Settings" | sort
```

**Counts:** 64 `*.routing.yml` files and 87 `*.services.yml` files under `web/modules/custom` (per `find`). The name-filtered file list is large (~200+ paths); this audit focuses on vendor console surfaces and cites representative paths.

**Working tree note:** `git status -sb` at TASK 0 start showed many **pre-existing** modified/untracked files (checkout, commerce, event studio, vendor ticketing, theme, config/sync). TASK 0 adds **only** this audit document unless/until later tasks change code.

---

## 1. Existing routes

Grouped by owning module. Requirements abbreviated (`VC` = `myeventlane_vendor.access.vendor_console:access`).

### `myeventlane_vendor` — [`myeventlane_vendor.routing.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml)

| Route name (machine) | Path | Access / notes |
| -------------------- | ---- | -------------- |
| Admin vendor CRUD | `/admin/structure/myeventlane/vendor` (+ add/edit/delete) | Admin permission |
| `myeventlane_vendor.login_alias` | `/vendor/login` | `_access: 'TRUE'` |
| `entity.myeventlane_vendor.canonical` | `/vendor/{myeventlane_vendor}` | Public vendor profile |
| `myeventlane_vendor.settings` | `/admin/structure/myeventlane/vendor/settings` | Admin `VendorSettingsForm` |
| Public lists | `/vendors`, `/organisers` | `access content` |
| Stripe | `/stripe/connect`, `/stripe/callback`, legacy callback, `/vendor/onboard/stripe-return`, `/vendor/onboard/stripe-refresh`, `/stripe/manage` | Mixed (`StripeConnectAccess`, VC for manage) |
| `myeventlane_vendor.create_event_gateway` | `/create-event` | Gateway |
| Onboarding | `/vendor/onboard`, `/vendor/onboard/account`, `/vendor/onboard/profile`, `/vendor/onboard/stripe`, `/vendor/onboard/branding`, `/vendor/onboard/first-event`, `/vendor/onboard/boost`, `/vendor/onboard/complete` | Mostly logged-in + theme override |
| Legacy manage | `/vendor/event/{event}/edit` | `ManageEventEditController::access` |
| Shell redirects | `/dashboard`, `/vendor` | `VendorDashboardController::entrypointRedirect`, `_access: 'TRUE'` |
| **`myeventlane_vendor.console.dashboard`** | **`/vendor/dashboard`** | **`VC`** |
| **`myeventlane_vendor.console.studio`** | **`/vendor/studio`** | **`VC`**, `_theme: myeventlane_vendor_theme` |
| Editor | `/vendor/events/{event}/editor` | `VC` |
| Studio JSON/API | `/vendor/studio/schema/event`, `/vendor/studio/event/{event}/data`, POST saves (`overview`, `tickets`, `attendees`, `promotion`, `settings`, `publish`, `submit-review`) | `VC` |
| Boost export | `/vendor/dashboard/boost/export` | `VC` |
| **`myeventlane_vendor.console.events`** | **`/vendor/events`** | **`VC`** |
| **`myeventlane_vendor.console.events_add`** | **`/vendor/events/add`** | **`VC`** |
| **`myeventlane_vendor.console.event_workspace`** | **`/vendor/events/{event}`** | **`VC`**, `event: \d+` |
| Event subpages | `.../overview`, `.../orders`, `.../orders/{order}`, `.../tickets`, `.../rsvps`, `.../analytics`, `.../settings`, `.../unpublish`, `.../promotion`, `.../promotion/branding`, `.../publish`, `.../boost/export` | Mostly `VC`; see exceptions below |
| `myeventlane_vendor.console.event_tickets` | `/vendor/events/{event}/tickets` | **`myeventlane_tickets.access.event_tickets:access`** (not `VC` alone) |
| `myeventlane_vendor.console.event_analytics` | `/vendor/events/{event}/analytics` | `use pro financial analytics` + `_myeventlane_pro_access: 'TRUE'` + `VC` |
| `myeventlane_vendor.console.event_promotion` & `myeventlane_vendor_comms.branding` | `.../promotion`, `.../promotion/branding` | `_entity_access: 'node.view'` + `VendorCommsController::checkAccess` |
| `myeventlane_vendor.console.messaging_brand` | `/vendor/dashboard/messaging/brand` | `VC` |
| Other console | `/vendor/payouts`, `/vendor/boost`, `/vendor/audience` | `VC` |
| Legacy `/vendor/event/...` | `design`, `content`, `tickets` (redirect), `checkout-questions`, `series`, placeholders | Per-controller `::access` |

**Comment in routing (dashboard permission):** `VendorConsoleAccess` intentionally avoids `_permission: access vendor dashboard` on the route definition so `administer nodes` bypass remains handled inside the access service ([`myeventlane_vendor.routing.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml) lines ~302–305).

### `myeventlane_event_studio` — [`myeventlane_event_studio.routing.yml`](web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml)

| Route name | Path | Access |
| ---------- | ---- | ------ |
| `myeventlane_event_studio.create` | **`/vendor/events/create`** | `access content` + **`VC`** |
| `myeventlane_event_studio.edit` | **`/vendor/events/{node}/edit`** | `_entity_access: 'node.update'` |
| Section forms | `.../edit/basic`, `datetime`, `tickets`, `description`, `preview`, `publish` | `_entity_access: 'node.update'` |
| `myeventlane_event_studio.autosave` | `/vendor/events/autosave` | `access content` |
| AI | `/vendor/events/ai/generate`, `rewrite`, `/vendor/events/studio/ticket-link-suggestions` | CSRF/header where noted |

### `myeventlane_vendor_settings` — [`myeventlane_vendor_settings.routing.yml`](web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.routing.yml)

| Route name | Path | Access |
| ---------- | ---- | ------ |
| `myeventlane_vendor.console.settings` | **`/vendor/settings`** | **`VC`** (`VendorSettingsForm`) |

### `myeventlane_dashboard` — [`myeventlane_dashboard.routing.yml`](web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.routing.yml)

| Route name | Path | Notes |
| ---------- | ---- | ----- |
| `myeventlane_dashboard.customer` | `/my-events` | Customer dashboard — **not** organiser console |

There is **no** route in this file to `Drupal\myeventlane_dashboard\Controller\VendorDashboardController` (see §8).

### `myeventlane_analytics` — [`myeventlane_analytics.routing.yml`](web/modules/custom/myeventlane_analytics/myeventlane_analytics.routing.yml)

| Route name | Path | Access |
| ---------- | ---- | ------ |
| `myeventlane_analytics.admin_revenue` | `/admin/myeventlane/revenue` | Admin permission + Pro |
| **`myeventlane_analytics.dashboard`** | **`/vendor/analytics`** | **`VC`** + `_myeventlane_pro_access: 'TRUE'` |
| `myeventlane_analytics.event` | `/vendor/analytics/event/{node}` | `AnalyticsDashboardController::accessEvent` + Pro |
| Exports | `.../export/pdf`, `.../export/excel` | Same event access + Pro |

### `myeventlane_event_attendees` — [`myeventlane_event_attendees.routing.yml`](web/modules/custom/myeventlane_event_attendees/myeventlane_event_attendees.routing.yml)

| Path | Controller | Access |
| ---- | ---------- | ------ |
| **`/vendor/events/{node}/attendees`** | `myeventlane_vendor.controller.vendor_event_attendees:attendees` | `VC` |
| `/vendor/events/{node}/attendees/export` | `VendorAttendeeController::export` | `VendorAttendeeController::access` |
| `/vendor/event/{node}/attendees` (+ export) | Legacy → 301 canonical | Custom access on export controller |
| `/vendor/attendee/{event_attendee}/checkin` | POST check-in | `VendorAttendeeController::accessAttendee` |
| `/vendor/event/{node}/waitlist` (+ export) | Waitlist management | `WaitlistManagementController::access` |

### `myeventlane_checkin` — [`myeventlane_checkin.routing.yml`](web/modules/custom/myeventlane_checkin/myeventlane_checkin.routing.yml)

| Path pattern | Permissions |
| ------------ | ----------- |
| `/vendor/events/{node}/check-in`, `.../scan`, `.../list`, `.../search` | `myeventlane_checkin.access` / `scan` as routed |
| `/vendor/events/{node}/check-in/toggle/{attendee_id}` | `myeventlane_checkin.toggle` |

### `myeventlane_tickets` — vendor subpaths (sample from [`myeventlane_tickets.routing.yml`](web/modules/custom/myeventlane_tickets/myeventlane_tickets.routing.yml))

Under **`/vendor/events/{event}/tickets/...`**: `types`, `settings`, `groups` (+ CRUD), `access-codes` (+ CRUD), `widgets` (+ CRUD), `types/{mel_ticket_type}/edit`. Access commonly **`myeventlane_tickets.access.event_tickets:access`** or `manage own events tickets` (see full YAML for each route).

### `myeventlane_rsvp` — vendor paths (from [`myeventlane_rsvp.routing.yml`](web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml))

Singular legacy-style paths remain: **`/vendor/event/{event}/rsvps`**, `.../export`, `.../checkin`, `.../checkin/pdf`, `.../scan`, plus `/vendor/qr/validate`. **Canonical plural workspace route** in vendor module is **`/vendor/events/{event}/rsvps`** — relationship between these must be verified in controllers/subscribers (TASK 1+).

---

## 2. Existing controllers and forms

### `myeventlane_vendor` — controllers ([`web/modules/custom/myeventlane_vendor/src/Controller`](web/modules/custom/myeventlane_vendor/src/Controller))

Includes: `VendorDashboardController`, `VendorEventsController`, `VendorEventCreateController`, `EventWorkspaceController`, `VendorStudioController`, `VendorStudioSchemaController`, `VendorEventOverviewController`, `VendorEventOrdersController`, `VendorEventOrderViewController`, `VendorEventTicketsController`, `VendorEventRsvpController`, `VendorEventAnalyticsController`, `VendorEventSettingsController`, `VendorEventAttendeesController`, `VendorPayoutsController`, `VendorBoostController`, `VendorAudienceController`, `VendorDashboardMessagingBrandController`, `ManageEventEditController`, `ManageEventDesignController`, `ManageEventContentController`, `ManageEventTicketsController`, `ManageEventCheckoutQuestionsController`, `ManageSeriesInstancesController`, `ManageEventPlaceholderController`, onboarding controllers, `StripeConnectController`, `CreateEventGatewayController`, `VendorPublicController`, `VendorDetailController`, `VendorConsoleBaseController`, etc.

### `myeventlane_vendor` — forms ([`web/modules/custom/myeventlane_vendor/src/Form`](web/modules/custom/myeventlane_vendor/src/Form))

`EventTicketManagerForm`, `EventUnpublishForm`, `VendorBrandingForm`, `VendorEventsBulkActionsForm`, `VendorForm`, `VendorOnboardProfileForm`, `VendorSettingsForm` (admin field UI route), `EventContentForm`, `EventCheckoutQuestionsForm`, `EventDesignForm`, `EventInformationForm`, `FormActionUrlFixer`.

### `myeventlane_vendor_settings`

- [`VendorSettingsForm`](web/modules/custom/myeventlane_vendor_settings/src/Form/VendorSettingsForm.php) — **`/vendor/settings`** (not the same class as admin `VendorSettingsForm` in `myeventlane_vendor`).

### `myeventlane_event_studio`

- **Controllers:** [`EventStudioController`](web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php), [`EventStudioAutosaveController`](web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioAutosaveController.php), [`EventStudioPreviewController`](web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioPreviewController.php), [`EventStudioAiController`](web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioAiController.php), [`EventStudioTicketSuggestionsController`](web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioTicketSuggestionsController.php).
- **Forms:** [`EventStudioForm`](web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php), `EventStudioBaseForm`, `EventStudioBasicForm`, `EventStudioDateForm`, `EventStudioTicketsForm`, `EventStudioDescriptionForm`, `EventStudioPublishForm`.

### `myeventlane_analytics`

- [`AnalyticsDashboardController`](web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php), [`AdminRevenueDashboardController`](web/modules/custom/myeventlane_analytics/src/Controller/AdminRevenueDashboardController.php).

### `myeventlane_dashboard` (services exist; routing thin)

- [`VendorDashboardController`](web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php) — **present in codebase**, **not** registered in [`myeventlane_dashboard.routing.yml`](web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.routing.yml) (see §8).
- [`CustomerDashboardController`](web/modules/custom/myeventlane_dashboard/src/Controller/CustomerDashboardController.php) — wired to `/my-events`.

---

## 3. Existing services (vendor console–adjacent)

### `myeventlane_vendor` — [`myeventlane_vendor.services.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml)

Non-exhaustive but critical IDs:

| Service ID | Class |
| ---------- | ----- |
| `myeventlane_vendor.access.vendor_console` | `Drupal\myeventlane_vendor\Access\VendorConsoleAccess` |
| `myeventlane_vendor.current_vendor_resolver` | `CurrentVendorResolver` |
| `myeventlane_vendor.user_vendor_membership_query` | `UserVendorMembershipQuery` |
| `myeventlane_vendor.event_access_checker` | `EventVendorAccessChecker` |
| `myeventlane_vendor.service.ticket_sales` | `TicketSalesService` |
| `myeventlane_vendor.service.rsvp_stats` | `RsvpStatsService` |
| `myeventlane_vendor.service.metrics_aggregator` | `MetricsAggregator` |
| `myeventlane_vendor.service.event_tabs` | `VendorEventTabsService` |
| `myeventlane_vendor.event_studio_create` | `VendorEventStudioCreateService` |
| `myeventlane_vendor.paid_publish_stripe_gate` | `PaidPublishStripeGate` |
| `myeventlane_vendor.publish_requirements_gate` | `VendorPublishRequirementsGate` |
| `myeventlane_vendor.controller.vendor_dashboard` | `VendorDashboardController` (DI-heavy) |
| Other `myeventlane_vendor.controller.*` | Workspace, events, orders, RSVPs, analytics, etc. |
| `myeventlane_vendor.vendor_console_page_preprocess` | `VendorConsolePagePreprocess` |

### `myeventlane_event_studio` — [`myeventlane_event_studio.services.yml`](web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.services.yml)

Includes: `myeventlane_event_studio.controller`, `myeventlane_event_studio.save` (`EventStudioSaveService`), `myeventlane_event_studio.repository`, `myeventlane_event_studio.autosave_controller`, `myeventlane_event_studio.mel_payload`, subscribers (`VendorLegacyWizardRedirectSubscriber`, `VendorMelTicketTypeEditRedirectSubscriber`, `EventNodeFormAccessSubscriber`), etc.

### `myeventlane_analytics` — [`myeventlane_analytics.services.yml`](web/modules/custom/myeventlane_analytics/myeventlane_analytics.services.yml)

Includes: `myeventlane_analytics.order_item_classifier`, `myeventlane_analytics.data`, `myeventlane_analytics.sales`, `myeventlane_analytics.conversion`, `myeventlane_analytics.phase7.query` (`AnalyticsQueryService`), scope resolver, guard, etc.

### `myeventlane_vendor_analytics` — [`myeventlane_vendor_analytics.services.yml`](web/modules/custom/myeventlane_vendor_analytics/myeventlane_vendor_analytics.services.yml)

| Service ID | Class |
| ---------- | ----- |
| **`myeventlane_vendor_analytics.vendor_kpi`** | `Drupal\myeventlane_vendor_analytics\Service\VendorKpiService` (uses `@myeventlane_analytics.phase7.query`) |

### `myeventlane_dashboard` — [`myeventlane_dashboard.services.yml`](web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.services.yml)

| Service ID | Role |
| ---------- | ---- |
| `myeventlane_dashboard.dashboard_builder` | `VendorDashboardBuilder` |
| `myeventlane_dashboard.vendor_context` | `VendorContextService` |
| `myeventlane_dashboard.metrics` | `VendorMetricsService` (uses `@myeventlane_analytics.phase7.query`) |
| `myeventlane_dashboard.events` | `VendorEventsService` |

**Overlap:** Dashboard metrics/builder module services parallel what `VendorDashboardController` assembles via `MetricsAggregator`, `TicketSalesService`, `RsvpStatsService`, `VendorKpiService`, etc. (TASK 3+ must reconcile, not duplicate.)

### `myeventlane_tickets`

- `myeventlane_tickets.access.event_tickets` — used on main ticket manager form route and ticket submodule paths ([`myeventlane_tickets.services.yml`](web/modules/custom/myeventlane_tickets/myeventlane_tickets.services.yml)).

---

## 4. Existing Twig templates

### Module: `myeventlane_vendor` — [`templates/`](web/modules/custom/myeventlane_vendor/templates)

`vendor-console-page.html.twig`, `myeventlane-manage-event.html.twig`, `myeventlane-vendor-list.html.twig`, `myeventlane-vendor-detail.html.twig`, `myeventlane-vendor-analytics.html.twig`, onboarding/gateway templates, `vendor/help-panel.html.twig`, series content partial, `components/onboarding-journey-steps.html.twig`.

**Theme hook:** `myeventlane_vendor_console_page` → template `vendor-console-page` ([`myeventlane_vendor.module`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.module) `hook_theme()`).

### Module: `myeventlane_event_studio` — [`templates/`](web/modules/custom/myeventlane_event_studio/templates)

`mel-event-studio.html.twig`, `mel-event-studio-nav.html.twig`, `mel-event-studio-wizard-nav.html.twig`, `mel-event-studio-wizard-preview.html.twig`, form element overrides for MEL option cards.

### Theme: `myeventlane_vendor_theme` — [`templates/`](web/themes/custom/myeventlane_vendor_theme/templates)

Console/workspace-oriented examples: `layout/console-page.html.twig`, `mel-event/mel-event-workspace.html.twig`, `includes/sidebar.html.twig`, `page--vendor-dashboard.html.twig`, `myeventlane-vendor-events-grid.html.twig`, `myeventlane-vendor-studio.html.twig`, event partials (`event/overview`, `orders`, `attendees`, `tickets`, `rsvps`, etc.), components (`workspace-tabs`, `vendor-kpi-strip`, `status-badge`, …).

### Theme: `myeventlane_theme`

Entity/vendor public templates referenced from `hook_theme()` (e.g. `entity--myeventlane-vendor--full` path).

---

## 5. Existing SCSS/CSS libraries

### `myeventlane_vendor` — [`myeventlane_vendor.libraries.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.libraries.yml)

`manage_event`, `hide_admin_sidebar`, `stripe_connect_cta`, `onboarding`, `bulk_actions`, `vendor_settings` / `vendor_settings_theme_only`, `vendor_detail_analytics`, `simple_tabs`, `fix_form_action`, `mel_publish_panel`, `mel_studio_search`, `vendor_help_panel`, **`event_ticket_manager`** (`css/event-ticket-manager.css`, `js/event-ticket-manager.js`).

### `myeventlane_event_studio` — [`myeventlane_event_studio.libraries.yml`](web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.libraries.yml)

`mel_event_studio` (aggregates `mel-event-studio.css`, nav CSS, **`mel-event-studio-shell.css`**), `mel_event_studio_shell_only`.

### `myeventlane_vendor_theme` — [`myeventlane_vendor_theme.libraries.yml`](web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.libraries.yml)

**`global` / `global-styling`:** Vite-built `dist/main.css` + `dist/main.js` (canonical vendor styling). Additional libraries: `dashboard`, `analytics`, `event_overview`, **`vendor-workspace`**, `vendor_wizard`, `event-form`, etc.

Vendor SCSS source lives under [`web/themes/custom/myeventlane_vendor_theme/src/scss`](web/themes/custom/myeventlane_vendor_theme/src/scss) (e.g. `components/_console.scss`, `pages/_mel-dashboard.scss`, `workspace.scss`, tokens under `tokens/`).

---

## 6. Canonical route decision (initial)

Aligned with product intent, **verified as already present** unless noted:

| Intent | Canonical path | Route module | Notes |
| ------ | -------------- | ------------ | ----- |
| Dashboard | `/vendor/dashboard` | `myeventlane_vendor.console.dashboard` | Command centre |
| Events index | `/vendor/events` | `myeventlane_vendor.console.events` | |
| Event create | **`/vendor/events/create`** | `myeventlane_event_studio.create` | **Prefer over** `/vendor/events/add` |
| Event edit | **`/vendor/events/{node}/edit`** | `myeventlane_event_studio.edit` | Section URLs under `.../edit/*` |
| Event workspace | `/vendor/events/{event}` | `myeventlane_vendor.console.event_workspace` | Parameter is numeric node ID |
| Per-event analytics (workspace) | `/vendor/events/{event}/analytics` | `myeventlane_vendor.console.event_analytics` | Pro + permission gated |
| Vendor-wide analytics | `/vendor/analytics` | `myeventlane_analytics.dashboard` | Pro gated |
| Vendor profile | `/vendor/settings` | `myeventlane_vendor.console.settings` | `VendorSettingsForm` (vendor_settings module) |
| Branding subsection | `/vendor/dashboard/messaging/brand` **or** merge into settings | `myeventlane_vendor.console.messaging_brand` | TASK 10 decides merge vs keep; code lives today |

**Attendees / check-in (canonical plural paths already):**

- `/vendor/events/{node}/attendees` (+ export) — event_attendees module
- `/vendor/events/{node}/check-in/*` — checkin module

**Tickets:** `/vendor/events/{event}/tickets` as **advanced** surface; Event Studio `.../edit/tickets` for normal flow — matches product strategy pending TASK 7 verification.

---

## 7. Legacy route decision (initial)

**Do not delete** until traffic and links are audited.

| Pattern | Proposed handling |
| ------- | ----------------- |
| `/vendor/events/add` | Redirect or replace links with **`/vendor/events/create`** |
| `/vendor/event/{event}/...` manage family | Treat as **legacy**; align with redirects already used elsewhere (e.g. attendees legacy → canonical) |
| `/vendor/studio` + POST `/vendor/studio/event/...` | **Advanced / alternate editor** API surface; reconcile with Event Studio UX without breaking saves |
| `/vendor/analytics/event/{node}` vs `/vendor/events/{event}/analytics` | Clarify **when each is linked**; avoid duplicate nav entries |
| `myeventlane_rsvp` `/vendor/event/{event}/rsvps...` | Align with **`/vendor/events/{event}/rsvps`** or document intentional dual paths |

Stripe and onboarding URLs (`/stripe/*`, `/vendor/onboard/*`) remain **functional**, not “legacy UI” in the same sense.

---

## 8. Duplicate or overlapping logic found

1. **Two dashboard implementations:** Live **`/vendor/dashboard`** uses [`Drupal\myeventlane_vendor\Controller\VendorDashboardController`](web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php). Separate [`Drupal\myeventlane_dashboard\Controller\VendorDashboardController`](web/modules/custom/myeventlane_dashboard/src/Controller/VendorDashboardController.php) + **`VendorDashboardBuilder` / `VendorMetricsService`** exist but are **not** wired in `myeventlane_dashboard.routing.yml`. Risk of drift and confusion for future “dashboard view model” work (TASK 3).
2. **Two event-creation URLs:** `/vendor/events/create` (Event Studio) vs `/vendor/events/add` (vendor module `VendorEventCreateController`).
3. **Two path families:** `/vendor/events/...` (plural, canonical console) vs `/vendor/event/...` (singular legacy manage).
4. **Two editor experiences:** Event Studio section routes vs `/vendor/studio` + JSON segment saves (`VendorStudioController`).
5. **Two per-event analytics entry points:** `myeventlane_vendor.console.event_analytics` vs `myeventlane_analytics.event` (`/vendor/analytics/event/{node}`).
6. **RSVP vendor URLs:** Plural workspace route under vendor module vs singular routes registered in **`myeventlane_rsvp`** — verify redirects or consolidate links (TASK 1/8).
7. **Metrics paths:** `MetricsAggregator` + ticket/RSVP services + **`VendorKpiService`** + dashboard module metrics all touch similar domains — TASK 3 must pick **one** orchestration layer.

---

## 9. Access-control gaps found

1. **Ticket manager route** (`myeventlane_vendor.console.event_tickets`): `_custom_access: 'myeventlane_tickets.access.event_tickets:access'` — **differs** from default **`VendorConsoleAccess`** on sibling routes; behaviour must be verified equivalent for team/admin ([`myeventlane_vendor.routing.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml)).
2. **Event Studio edit/create:** Uses **`_entity_access: 'node.update'`** (and create uses `VC` + `access content`) — **not** identical to `VC` alone; team-member and cross-vendor denial depend on node access hooks vs vendor membership (TASK 11).
3. **Check-in routes:** Permission-based (`myeventlane_checkin.access`, etc.) — may diverge from organiser console gate for users who pass `VC` but lack check-in permission.
4. **Promotion/comms:** `_entity_access: 'node.view'` + `VendorCommsController::checkAccess` — third pattern in the same workspace.
5. **Attendee export:** `VendorAttendeeController::access` vs list page using **`VC`** — confirm consistent store/event scoping.
6. **Pro / analytics:** Multiple routes add **`_myeventlane_pro_access`** and specific permissions — vendors without Pro may hit **different** denial surfaces than other console pages.

Kernel coverage exists: [`VendorConsoleAccessKernelTest`](web/modules/custom/myeventlane_vendor/tests/src/Kernel/VendorConsoleAccessKernelTest.php).

---

## 10. Files likely to change in later tasks (hypothesis)

| Task area | Candidate paths |
| --------- | ----------------- |
| Shell / nav | [`vendor-console-page.html.twig`](web/modules/custom/myeventlane_vendor/templates/vendor-console-page.html.twig), [`VendorConsolePagePreprocess`](web/modules/custom/myeventlane_vendor/src/Hook/VendorConsolePagePreprocess.php), [`myeventlane_vendor_theme/src/scss/components/_console.scss`](web/themes/custom/myeventlane_vendor_theme/src/scss/components/_console.scss), `layout/console-page.html.twig`, `myeventlane_vendor.libraries.yml` |
| Dashboard view model | [`VendorDashboardController.php`](web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php), [`myeventlane_vendor.services.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml); possible **reuse or bridge** to [`VendorDashboardBuilder`](web/modules/custom/myeventlane_dashboard/src/Service/VendorDashboardBuilder.php) |
| Events list | [`VendorEventsController.php`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php), [`myeventlane-vendor-events-grid.html.twig`](web/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig) |
| Event Studio | [`EventStudioController.php`](web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php), forms under `Form/`, [`mel-event-studio.html.twig`](web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig), shell CSS |
| Workspace | [`EventWorkspaceController.php`](web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php), [`VendorEventTabsService.php`](web/modules/custom/myeventlane_vendor/src/Service/VendorEventTabsService.php), workspace Twig under vendor theme |
| Analytics | [`AnalyticsDashboardController.php`](web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php), [`VendorEventAnalyticsController.php`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php) |
| Settings / brand | [`VendorSettingsForm.php`](web/modules/custom/myeventlane_vendor_settings/src/Form/VendorSettingsForm.php) (settings module), [`VendorDashboardMessagingBrandController.php`](web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardMessagingBrandController.php) |
| Access unification | [`VendorConsoleAccess.php`](web/modules/custom/myeventlane_vendor/src/Access/VendorConsoleAccess.php), [`EventTicketsAccess.php`](web/modules/custom/myeventlane_tickets/src/Access/EventTicketsAccess.php), route `requirements` in vendor + tickets + event_studio YAML |
| Routing redirects | [`myeventlane_vendor.routing.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml), [`myeventlane_event_studio.routing.yml`](web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml), [`myeventlane_rsvp.routing.yml`](web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml) |

---

## TASK 1 follow-up

Create [`docs/vendor-console-v2-route-map.md`](docs/vendor-console-v2-route-map.md) with explicit canonical vs legacy table and redirect decisions **after** link/reference grep across codebase (TASK 1).

### TASK 1 factual addendum (grep-verified, 2026-05-05)

1. **`myeventlane_vendor.console.create_event` is not a registered route.** [`OrganiserContextBlock`](web/modules/custom/myeventlane_core/src/Plugin/Block/OrganiserContextBlock.php) calls `Url::fromRoute('myeventlane_vendor.console.create_event')`; the only related gateway route is `myeventlane_vendor.create_event_gateway` (`/create-event`). Likely intent: `myeventlane_event_studio.create` — fix in TASK 11/12.
2. **`/vendor/events/add` already redirects in PHP:** [`VendorEventCreateController::buildForm`](web/modules/custom/myeventlane_vendor/src/Controller/VendorEventCreateController.php) returns a `RedirectResponse` to `myeventlane_event_studio.create`. Menu link [`myeventlane_vendor.links.menu.yml`](web/modules/custom/myeventlane_vendor/myeventlane_vendor.links.menu.yml) still targets `myeventlane_vendor.console.events_add`.
3. **`/vendor/event/{event}/edit` already redirects:** [`ManageEventEditController::edit`](web/modules/custom/myeventlane_vendor/src/Controller/ManageEventEditController.php) redirects to `myeventlane_event_studio.edit`.
4. **Vendor shell “Attendees” top-level item** uses [`myeventlane_checkout_flow.vendor_attendees`](web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.routing.yml) (`/vendor/attendees`), not `VendorConsoleAccess` — see `VendorAttendeesController::checkAccess`.

---

## Verification (TASK 0)

| Check | Result |
| ----- | ------ |
| Branch | `feature/mel-vendor-console-v2` created |
| Production code edits for TASK 0 | **None** — only this document added |
| Pre-existing dirty tree | Unrelated local changes remain (see § baseline); not introduced by TASK 0 |

**Commands not required for TASK 0:** `composer validate`, `npm run mel:build`, `ddev drush` — defer to TASK 14 when implementation touches builds/runtime.
