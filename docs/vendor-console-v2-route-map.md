# MEL Vendor Console v2 — canonical route map

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**TASK:** 1 (documentation and route-reference audit only — no production routing/config/code changes in this task)

## 1. Purpose

This document locks the **canonical organiser (vendor console) URL structure** and records **legacy overlaps**, **grep-verified link sources**, **navigation rules**, and **later redirect/access responsibilities** before TASK 2+ implementation. The goal is a single reference so later tasks do not duplicate dashboard, event workspace, RSVP, ticket, analytics, Event Studio, or profile flows—or guess route names, owners, or access callbacks.

## 2. Source documents

- Baseline inventory and overlap analysis: [`docs/vendor-console-v2-audit.md`](vendor-console-v2-audit.md)

Authoritative route definitions were cross-checked in:

- [`web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`](../web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml)
- [`web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml`](../web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml)
- [`web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.routing.yml`](../web/modules/custom/myeventlane_vendor_settings/myeventlane_vendor_settings.routing.yml)
- [`web/modules/custom/myeventlane_analytics/myeventlane_analytics.routing.yml`](../web/modules/custom/myeventlane_analytics/myeventlane_analytics.routing.yml)
- [`web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml`](../web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml)
- [`web/modules/custom/myeventlane_event_attendees/myeventlane_event_attendees.routing.yml`](../web/modules/custom/myeventlane_event_attendees/myeventlane_event_attendees.routing.yml)
- [`web/modules/custom/myeventlane_checkin/myeventlane_checkin.routing.yml`](../web/modules/custom/myeventlane_checkin/myeventlane_checkin.routing.yml)
- [`web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.routing.yml`](../web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.routing.yml)
- [`web/modules/custom/myeventlane_pro/myeventlane_pro.routing.yml`](../web/modules/custom/myeventlane_pro/myeventlane_pro.routing.yml)

## 3. Product route principles

These are the **target product rules** for Vendor Console v2 navigation and CTAs (aligned with TASK 0 and verified against existing routes):

1. `/vendor/dashboard` is the organiser command centre.
2. `/vendor/events` is the event management index.
3. `/vendor/events/create` is the **only normal create-event entry point** for new console UI and CTAs.
4. `/vendor/events/{node}/edit` is the **only normal event editing entry point** (Event Studio); section URLs live under `/vendor/events/{node}/edit/*`.
5. `/vendor/events/{event}` is the event workspace (numeric node ID; `{event}` is an `entity:node` parameter named `event` in routing).
6. `/vendor/events/{event}/analytics` is the **workspace-scoped** event analytics tab (vendor-themed workspace shell).
7. `/vendor/analytics` is the **vendor-wide** analytics dashboard route.
8. `/vendor/settings` is the vendor profile/settings route (organiser settings form).
9. `/vendor/dashboard/messaging/brand` is **not** a second profile system—it is either a styled subsection of profile/comms or a future redirect target (TASK 10).
10. `/vendor/events/{event}/tickets` remains **advanced** ticket management (`EventTicketManagerForm` + deeper ticket submodule URLs); normal ticket setup belongs in Event Studio (`/vendor/events/{node}/edit/tickets`).
11. Legacy `/vendor/event/...` paths **must not be linked** from the new Vendor Console UI (some already redirect or are placeholders; see §5).

**Parameter naming note:** Many routes use `{event}` and Event Studio uses `{node}` for the same event nid. When generating URLs, always use the **route’s declared parameter name** (`event` vs `node`).

## 4. Canonical route table

| Product area | Canonical path | Canonical route name | Owning module | Controller/form | Access requirement | Notes |
| ------------ | -------------- | -------------------- | -------------- | ----------------- | ------------------ | ----- |
| Dashboard | `/vendor/dashboard` | `myeventlane_vendor.console.dashboard` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_dashboard:dashboard` | `_custom_access: myeventlane_vendor.access.vendor_console:access` | Dashboard permission gate is applied **inside** `VendorConsoleAccess` (not as a bare `_permission` on the route); see audit §1 comment in routing YAML. |
| Events index | `/vendor/events` | `myeventlane_vendor.console.events` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_events:list` | `VendorConsoleAccess` | |
| Event create | `/vendor/events/create` | `myeventlane_event_studio.create` | `myeventlane_event_studio` | `myeventlane_event_studio.controller:buildCreate` | `_permission: access content` + `VendorConsoleAccess` | Section/step routes are not listed here; see `myeventlane_event_studio.edit_*` in the same YAML file. |
| Event edit | `/vendor/events/{node}/edit` | `myeventlane_event_studio.edit` | `myeventlane_event_studio` | `myeventlane_event_studio.controller:buildEdit` | `_entity_access: node.update` | Differs from `VendorConsoleAccess`-only routes; TASK 11 must reconcile team/node access. |
| Event workspace | `/vendor/events/{event}` | `myeventlane_vendor.console.event_workspace` | `myeventlane_vendor` | `myeventlane_vendor.controller.event_workspace:workspace` | `VendorConsoleAccess`; `event: \d+` | |
| Event overview | `/vendor/events/{event}/overview` | `myeventlane_vendor.console.event_overview` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_overview:overview` | `VendorConsoleAccess` | |
| Advanced tickets (manager) | `/vendor/events/{event}/tickets` | `myeventlane_vendor.console.event_tickets` | `myeventlane_vendor` | `\Drupal\myeventlane_vendor\Form\EventTicketManagerForm` | `_custom_access: myeventlane_tickets.access.event_tickets:access` | **Not** `VendorConsoleAccess` alone; ticket subtree routes live in `myeventlane_tickets` (types, settings, groups, access-codes, widgets, etc.). |
| Event RSVPs (workspace UI) | `/vendor/events/{event}/rsvps` | `myeventlane_vendor.console.event_rsvps` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_rsvps:rsvps` | `VendorConsoleAccess` | Uses `VendorEventRsvpController`; tabs use plural URL in `VendorEventTabsService` (`/vendor/events/{id}/rsvps`). Legacy RSVP module URLs remain under `/vendor/event/...` (§5). |
| Event orders | `/vendor/events/{event}/orders` | `myeventlane_vendor.console.event_orders` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_orders:orders` | `VendorConsoleAccess` | Order detail: `myeventlane_vendor.console.event_order_view` → `/vendor/events/{event}/orders/{order}`. |
| Event attendees | `/vendor/events/{node}/attendees` | `myeventlane_event_attendees.vendor_list` | `myeventlane_event_attendees` | `myeventlane_vendor.controller.vendor_event_attendees:attendees` | `VendorConsoleAccess` | Export: `myeventlane_event_attendees.vendor_export` → `/vendor/events/{node}/attendees/export` with `VendorAttendeeController::access`. |
| Event check-in | `/vendor/events/{node}/check-in` | `myeventlane_checkin.page` | `myeventlane_checkin` | `CheckInController::page` | `_permission: myeventlane_checkin.access` | Subroutes: `…/scan` (`myeventlane_checkin.scan`, `myeventlane_checkin.scan` permission), `…/list`, `…/search` (`myeventlane_checkin.access`), `…/toggle/{attendee_id}` (`myeventlane_checkin.toggle`). |
| Event analytics (workspace tab) | `/vendor/events/{event}/analytics` | `myeventlane_vendor.console.event_analytics` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_event_analytics:analytics` | `_permission: use pro financial analytics` + `_myeventlane_pro_access: TRUE` + `VendorConsoleAccess` | Controller throws if Pro resolver/user not Pro-active (`VendorEventAnalyticsController`). Distinct from `myeventlane_analytics.event` UI (see §5). |
| Vendor analytics | `/vendor/analytics` | `myeventlane_analytics.dashboard` | `myeventlane_analytics` | `AnalyticsDashboardController::dashboard` | `VendorConsoleAccess` + `_myeventlane_pro_access: TRUE` | Vendor-wide UI uses `VendorAnalyticsViewModelBuilder`; per-event CTAs use workspace analytics (`myeventlane_vendor.console.event_analytics`). Legacy advanced UI + exports: `myeventlane_analytics.event` / export routes (TASK 9). |
| Vendor profile/settings | `/vendor/settings` | `myeventlane_vendor.console.settings` | `myeventlane_vendor_settings` | `\Drupal\myeventlane_vendor_settings\Form\VendorSettingsForm` | `VendorConsoleAccess` | Venues tabs: `myeventlane_venue` routes under `/vendor/settings/venues…`. |
| Messaging brand (dashboard) | `/vendor/dashboard/messaging/brand` | `myeventlane_vendor.console.messaging_brand` | `myeventlane_vendor` | `myeventlane_vendor.controller.vendor_dashboard_messaging_brand:brand` | `VendorConsoleAccess` | Embeds `Drupal\myeventlane_messaging\Form\VendorBrandingForm` (not the onboarding copy in `myeventlane_vendor\Form\VendorBrandingForm`—two classes share short name; see grep). TASK 10 merge vs `/vendor/settings`. |
| Pro branding | `/vendor/settings/branding` | `myeventlane_pro.branding` | `myeventlane_pro` | `ProBrandingController::settings` | `ProBrandingController::access` + `_myeventlane_pro_access: TRUE` | Separate Pro-only surface; TASK 10 must relate to messaging brand. |
| Stripe Connect | `/stripe/connect` | `myeventlane_vendor.stripe_connect` | `myeventlane_vendor` | `StripeConnectController::connect` | `StripeConnectAccess::access` | |
| Stripe callback | `/stripe/callback` | `myeventlane_vendor.stripe_callback` | `myeventlane_vendor` | `StripeConnectController::callback` | `_access: TRUE` | Legacy alias: `myeventlane_vendor.stripe_callback_legacy` → `/stripe/connect/callback`. |
| Stripe dashboard | `/stripe/manage` | `myeventlane_vendor.stripe_manage` | `myeventlane_vendor` | `StripeConnectController::manage` | `VendorConsoleAccess` | |
| Vendor attendees & sales (cross-event) | `/vendor/attendees` | `myeventlane_checkout_flow.vendor_attendees` | `myeventlane_checkout_flow` | `VendorAttendeesController::dashboard` | `VendorAttendeesController::checkAccess` | **Not** `VendorConsoleAccess`; uses store resolution + admin bypass. Exists today and is wired as **Attendees** in full vendor shell nav (`myeventlane_vendor_theme.theme`). |

**Onboarding (readiness for dashboard/profile):** routes under `/vendor/onboard/*` are defined in `myeventlane_vendor.routing.yml` (`myeventlane_vendor.onboard`, `.onboard.account`, `.onboard.profile`, `.onboard.stripe`, `.onboard.branding`, `.onboard.first_event`, `.onboard.boost`, `.onboard.complete`) plus Stripe return URLs `/vendor/onboard/stripe-return` and `/vendor/onboard/stripe-refresh`. Keep functional; TASK 5/10 may reference them from CTAs.

## 5. Legacy and overlap route table

| Legacy / overlapping path | Current route name | Current owner | Current usage found by grep | Decision | Later implementation task |
| ------------------------- | ------------------ | ------------- | ---------------------------- | -------- | --------------------------- |
| `/vendor/events/add` | `myeventlane_vendor.console.events_add` | `myeventlane_vendor` | Defined in routing; `VendorEventCreateController::buildForm` redirects to `myeventlane_event_studio.create`; account menu still registers this route in `myeventlane_vendor.links.menu.yml`; `LaunchRequestProtectionSubscriber` lists route name; docs/qa mention path | **Redirect later** | TASK 6/12: change menu/links to `myeventlane_event_studio.create`; optional HTTP/route consolidation after metrics |
| `/vendor/event/{event}/edit` | `myeventlane_vendor.manage_event.edit` | `myeventlane_vendor` | Routing + `ManageEventEditController::edit` | **Redirect later** (already behaves as redirect) | **Current behaviour:** immediate `RedirectResponse` to `myeventlane_event_studio.edit`. Later: optional removal or 301 policy for bookmarks; TASK 7 |
| `/vendor/event/{event}/tickets` | `myeventlane_vendor.manage_event.tickets` | `myeventlane_vendor` | `ManageEventTicketsController` docblock | **Redirect later** (already redirects to plural tickets) | TASK 8: ensure no new UI links here |
| `/vendor/event/{event}/rsvps` | `myeventlane_rsvp.vendor_event_rsvps` | `myeventlane_rsvp` | `myeventlane_rsvp.routing.yml`; functional tests hit `/vendor/event/…/rsvps` | **Redirect later** | TASK 8/11: parity check—access uses `VendorEventAccess::checkAccess`, **not** `VendorConsoleAccess`; plural workspace uses `VendorConsoleAccess` + controller ownership asserts |
| `/vendor/event/{event}/rsvps/export` | `myeventlane_rsvp.export_csv` | `myeventlane_rsvp` | routing + tests | **Redirect later** | TASK 8 |
| `/vendor/event/{event}/rsvps/checkin` | `myeventlane_rsvp.checkin_list` | `myeventlane_rsvp` | routing | **Redirect later** | TASK 8 — coordinate with commerce check-in (`/vendor/events/{node}/check-in/*`) vs RSVP check-in |
| `/vendor/analytics/event/{node}` | `myeventlane_analytics.event` | `myeventlane_analytics` | Advanced timeline / funnel / charts template `analytics-event.html.twig`; PDF/Excel export sibling routes | **Advanced / export / legacy UI** (TASK 9) | **Canonical** organiser analytics tab: `/vendor/events/{event}/analytics` (`VendorEventAnalyticsController`). Legacy route **retained** for funnel/time-series view and exports; linked from legacy template + export URLs only—not primary console CTAs. |
| `/vendor/studio` | `myeventlane_vendor.console.studio` | `myeventlane_vendor` | `VendorStudioController`; vendor theme JS (`vendor-studio.js`) default endpoints | **Keep as advanced** | Alternate editor shell; TASK 7/8 must not break |
| `/vendor/studio/event/{event}/data` | `myeventlane_vendor.console.studio.event_data` | `myeventlane_vendor` | GET JSON | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/schema/event` | `myeventlane_vendor.studio_event_schema` | `myeventlane_vendor` | vendor-studio.js `/vendor/studio/schema/event` | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/overview` | `myeventlane_vendor.console.studio.event_overview_save` | `myeventlane_vendor` | POST save segment | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/tickets` | `myeventlane_vendor.console.studio.event_tickets_save` | `myeventlane_vendor` | POST | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/attendees` | `myeventlane_vendor.console.studio.event_attendees_save` | `myeventlane_vendor` | POST | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/promotion` | `myeventlane_vendor.console.studio.event_promotion_save` | `myeventlane_vendor` | POST | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/settings` | `myeventlane_vendor.console.studio.event_settings_save` | `myeventlane_vendor` | POST | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/publish` | `myeventlane_vendor.console.studio.event_publish` | `myeventlane_vendor` | POST | **Keep as API/internal** | TASK 7 |
| `/vendor/studio/event/{event}/save` | `myeventlane_vendor.studio_event_save` | `myeventlane_vendor` | POST (generic save) | **Keep as API/internal** | vendor-studio.js endpoint template |
| `/vendor/studio/event/{event}/submit-review` | `myeventlane_vendor.console.studio.submit_review` | `myeventlane_vendor` | POST | **Keep as API/internal** | TASK 7 |
| `/vendor/dashboard/messaging/brand` | `myeventlane_vendor.console.messaging_brand` | `myeventlane_vendor` | Linked from dashboard controller URLs; settings hub links in `VendorDashboardMessagingBrandController` | **Needs TASK 10 review** | Merge vs `/vendor/settings#brand` vs keep subsection |
| `/vendor/events/{event}/editor` | `myeventlane_vendor.console.event_editor` | `myeventlane_vendor` | routing | **Keep as advanced** | Alternate editor entry; TASK 7 |
| `/vendor/event/{event}/design` … `/advanced` | `myeventlane_vendor.manage_event.*` | `myeventlane_vendor` | routing | **Deprecate after verification** | Many are legacy manage stack or placeholders; do not link in v2 |
| `/vendor/event/{node}/waitlist` | `myeventlane_event_attendees.waitlist_manage` | `myeventlane_event_attendees` | routing; dashboard builds `waitlist_url` with `/vendor/event/{id}/waitlist` | **Needs TASK 8 review** | Still singular path family |
| `/vendor/events/{event}/build/*` | `myeventlane_event` module routes | `myeventlane_event` | `myeventlane_event.routing.yml` | **Needs TASK 7 review** | Parallel “build” wizard paths alongside Event Studio |

Additional overlap (from grep, not redundant table rows):

- **RSVP QR / validation:** `/vendor/event/{event}/scan` (`myeventlane_rsvp.checkin_scan`), `/vendor/qr/validate` (`myeventlane_rsvp.checkin_validate` POST).
- **Insights:** `/vendor/events/{event}/insights/*` (`myeventlane_reporting`) — related reporting surfaces; TASK 9 may align with analytics nav.

## 6. Navigation rules

### Primary nav items (target Vendor Console v2)

1. **Dashboard** → `/vendor/dashboard` (`myeventlane_vendor.console.dashboard`).
2. **Events** → `/vendor/events` (`myeventlane_vendor.console.events`).
3. **Analytics** → `/vendor/analytics` (`myeventlane_analytics.dashboard`).
4. **Attendees** → `/vendor/attendees` **when** `myeventlane_checkout_flow.vendor_attendees` is accessible (`VendorAttendeesController::checkAccess`). If hidden for the account, attendee management stays **event-scoped** via workspace tabs (`/vendor/events/{node}/attendees`, check-in, RSVP surfaces).  
   **Evidence:** `_myeventlane_vendor_theme_build_full_vendor_shell_nav_items()` registers `'route' => 'myeventlane_checkout_flow.vendor_attendees'` for key `attendees` — **grep:** `myeventlane_vendor_theme.theme` ~L1965–1969.
5. **Profile** → `/vendor/settings` (`myeventlane_vendor.console.settings`).

**Gap vs today:** Full vendor shell nav **does not** currently include a top-level **Analytics** route item (footer-internal Twig uses hard-coded `/vendor/analytics`). TASK 2 must add Analytics per product §3 without duplicating footers.

### Create Event CTA

- **Always** `myeventlane_event_studio.create` → `/vendor/events/create`.
- **Never** `myeventlane_vendor.console.events_add` (`/vendor/events/add`) in new UI (route may remain for backward compatibility).
- **Never** `/node/add/event` as vendor marketing/create CTA (still appears in some footers: `footer-internal.html.twig` in `myeventlane_theme` and `myeventlane_vendor_theme` — grep hit).
- **Never** legacy `/vendor/event/…` manage URLs or parallel wizard routes (`/vendor/events/{event}/build/*`) as primary CTAs — TASK 7 validates exceptions.

**Broken reference found:** `OrganiserContextBlock` uses `Url::fromRoute('myeventlane_vendor.console.create_event')` — **that route name does not exist** in `myeventlane_vendor.routing.yml` (only `myeventlane_vendor.create_event_gateway` exists for `/create-event`). Only occurrence in repo — **TASK 11/12** fix (likely intended `myeventlane_event_studio.create`).

### Event card actions (target)

- **Manage** → `/vendor/events/{event}` (`myeventlane_vendor.console.event_workspace`).
- **Edit** → `/vendor/events/{node}/edit` (`myeventlane_event_studio.edit`).
- **Tickets** → `/vendor/events/{event}/tickets` **only** as advanced (`myeventlane_vendor.console.event_tickets`) — subject to `EventTicketsAccess`.
- **RSVPs** → `/vendor/events/{event}/rsvps` (`myeventlane_vendor.console.event_rsvps`).
- **Orders** → `/vendor/events/{event}/orders`.
- **Attendees** → `/vendor/events/{node}/attendees`.
- **Analytics** → `/vendor/events/{event}/analytics` (`myeventlane_vendor.console.event_analytics`) for workspace consistency; deep-link to `myeventlane_analytics.event` only when TASK 9 defines export/advanced behaviour.

### Vendor profile actions

- **Profile/settings** → `/vendor/settings`.
- **Brand** → `/vendor/dashboard/messaging/brand` **only if** TASK 10 keeps it; else redirect/hash on settings.
- **Pro branding** → `/vendor/settings/branding` (`myeventlane_pro.branding`) when Pro-gated product applies.
- **Stripe connect/manage** → `/stripe/connect`, `/stripe/manage` (and callbacks as today).

## 7. Redirect policy for later implementation

No redirects are implemented in TASK 1. Planned posture:

1. **`/vendor/events/add`** → later **301 or route alias** to `/vendor/events/create` **after** menu/link cleanup; note **today** `VendorEventCreateController` already issues a redirect to `myeventlane_event_studio.create` (same destination path).
2. **`/vendor/event/{event}/edit`** → later formal redirect policy to `/vendor/events/{event}/edit` **if** product standardises on plural-only paths; **today** `ManageEventEditController` redirects to Event Studio edit (`/vendor/events/{node}/edit`).
3. **`/vendor/event/{event}/tickets`** → later match plural `/vendor/events/{event}/tickets`; **today** `ManageEventTicketsController::redirectToCanonicalTickets`.
4. **`/vendor/event/{event}/rsvps`** (+ export/check-in) → later redirect to `/vendor/events/{event}/rsvps` **after** access parity (`VendorEventAccess` vs `VendorConsoleAccess` + `VendorConsoleBaseController::assertEventOwnership`).
5. **`/vendor/analytics/event/{node}`** → later redirect or link-normalise to `/vendor/events/{event}/analytics` **unless** TASK 9 preserves this route for Pro exports / distinct analytics UX (exports today live under analytics module paths).
6. **`/vendor/studio` JSON/API routes** → **keep** as internal/advanced; **do not** expose as primary nav links.
7. **`/vendor/dashboard/messaging/brand`** → TASK 10: **keep subsection** vs **redirect** to `/vendor/settings#brand`.

## 8. Access policy notes

TASK 11 must unify or verify **all** of the following against product intent (no resolution in TASK 1):

| Mechanism | Where it appears (examples) |
| --------- | ---------------------------- |
| `VendorConsoleAccess` | Most `/vendor/events/…`, dashboard, settings, messaging brand |
| `EventVendorAccessChecker` | `AnalyticsDashboardController::accessEvent` workspace parity |
| `myeventlane_tickets.access.event_tickets:access` (`EventTicketsAccess`) | `/vendor/events/{event}/tickets` and ticket submodule routes |
| `_entity_access: node.update` | Event Studio edit/create stack |
| `VendorCommsController::checkAccess` + `_entity_access: node.view` | Promotion routes |
| `VendorEventAccess::checkAccess` | Legacy `/vendor/event/{event}/rsvps*` RSVP module routes |
| `VendorAttendeeController::access` / `::accessAttendee` | Attendee export, legacy paths, check-in POST |
| Check-in permissions | `myeventlane_checkin.access`, `.scan`, `.toggle` |
| Pro gates | `_myeventlane_pro_access`, `use pro financial analytics`, `VendorEventAnalyticsController` runtime checks |
| Admin/staff bypass | Various `administer nodes`, `administer commerce_order`, `bypass node access`, RSVP admin permissions |

**Route-specific difference to preserve in TASK 11:** `myeventlane_event_studio.autosave` (`POST /vendor/events/autosave`) requires `_permission: access content` only (no `VendorConsoleAccess` in routing YAML) — verify CSRF/session expectations in `EventStudioAutosaveController` before tightening.

## 9. Implementation dependencies for later tasks

| Task | Owns |
| ---- | ---- |
| TASK 2 | Shared shell/nav — align sidebar with §6 (incl. Analytics), remove conflicting patterns |
| TASK 3 | Dashboard view model |
| TASK 4 | Action queue |
| TASK 5 | `/vendor/dashboard` UI |
| TASK 6 | `/vendor/events` UI |
| TASK 7 | Event Studio canonical create/edit; `/vendor/studio` integration |
| TASK 8 | `/vendor/events/{event}` workspace |
| TASK 9 | `/vendor/analytics` + relationship to `myeventlane_analytics.event` |
| TASK 10 | `/vendor/settings` + messaging brand + Pro branding |
| TASK 11 | Access unification |
| TASK 12 | User dropdown/nav visibility (menus still point `events_add`; fix `OrganiserContextBlock`) |
| TASK 13 | Styling hardening |

## 10. Final TASK 1 summary

- **Routes confirmed:** Canonical and legacy rows above traced to `*.routing.yml` and key controllers/forms cited in §4–§5.
- **Canonical decisions made:** Plural `/vendor/events/…` family + Event Studio create/edit + workspace analytics path + vendor-wide `/vendor/analytics` + `/vendor/settings` as profile hub (see §3).
- **Legacy routes identified:** Singular `/vendor/event/…` manage + RSVP module paths + `/vendor/events/add` + `/vendor/studio` stack + analytics module per-event path (§5).
- **Redirect decisions for later:** §7 (including “already redirects” current behaviour for several legacy paths).
- **Unresolved questions:**  
  - Parity between legacy RSVP access (`VendorEventAccess`) and workspace RSVP (`VendorConsoleAccess` + ownership asserts).  
  - Which analytics surface is primary for vendors (`VendorEventAnalyticsController` vs `AnalyticsDashboardController::eventAnalytics`) and how exports attach.  
  - RSVP check-in (`myeventlane_rsvp`) vs commerce attendee check-in (`myeventlane_checkin`).  
  - `/vendor/events/{event}/build/*` wizard vs Event Studio — deprecation scope.  
  - Fix invalid route `myeventlane_vendor.console.create_event` in `OrganiserContextBlock`.
- **Files changed:** `docs/vendor-console-v2-route-map.md` (this file); optional audit addendum in `docs/vendor-console-v2-audit.md`.

## Appendix A — Grep command findings (TASK 1)

Commands run equivalent to the TASK brief (repository root); representative findings captured above. High-signal hits:

- **Path inventory:** `myeventlane_vendor.routing.yml` centralises console + legacy `/vendor/event/…` + `/vendor/studio/*` API paths.
- **Hard-coded URLs:** `VendorDashboardController` uses `/vendor/analytics/event/{id}` and `/vendor/dashboard/messaging/brand` / `/vendor/settings` as strings — normalise in TASK 5/9/10.
- **Theme/JS:** `vendor-studio.js` defaults `melSchemaEndpoint` `/vendor/studio/schema/event` and save endpoint `/vendor/studio/event/{event}/save`.
- **Menus:** `myeventlane_vendor.links.menu.yml` — **Create event** → `myeventlane_vendor.console.events_add` (should become `myeventlane_event_studio.create` per §6).
- **Footers:** `/node/add/event` still linked from internal footers (grep in themes).
- **Config:** `config/sync/myeventlane_help_centre.help_content.yml` references `myeventlane_vendor.console.dashboard` (help centre scope).

---

**Residual risks for TASK 2 onward:** Hidden coupling between dashboard KPI cards and `myeventlane_analytics.event` paths; dual RSVP implementations (vendor workspace vs RSVP module legacy URLs); menu + OrganiserContextBlock still targeting wrong create routes; full shell nav missing Analytics link; `/vendor/attendees` uses different access logic than `VendorConsoleAccess`.

---

## TASK 2 implementation notes

**Date:** 2026-05-05  
**Scope:** Shared vendor console shell, primary navigation order, canonical routes for CTAs/footer, access-aware Analytics + Attendees, MEL styling hooks under `.mel-vendor-console`. No dashboard data models, action queue, or route YAML changes.

### Shell / nav files inspected

- `web/modules/custom/myeventlane_vendor/templates/vendor-console-page.html.twig` — workspace/console inner layout (`mel-console-page`); unchanged (still module-owned).
- `web/themes/custom/myeventlane_vendor_theme/templates/layout/console-page.html.twig` — theme hook `myeventlane_vendor_console_page` layout (`mel-console`); unchanged structure.
- `web/themes/custom/myeventlane_vendor_theme/templates/page--vendor-dashboard.html.twig` — thin wrapper; unchanged.
- `web/themes/custom/myeventlane_vendor_theme/templates/includes/sidebar.html.twig` — shell sidebar; updated with `mel-vendor-console__*` classes on nav/links.
- `web/themes/custom/myeventlane_vendor_theme/templates/layout/page.html.twig` — vendor shell wrapper; `mel-vendor-console` root + header/content/sidebar/primary-action classes.
- `web/modules/custom/myeventlane_vendor/src/Hook/VendorConsolePagePreprocess.php` — workspace meta/tabs only; not altered (no business logic change).

### Nav builder chosen

- **Owner:** `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme`
- **Functions:** `_myeventlane_vendor_theme_build_full_vendor_shell_nav_items()` (full console), `_myeventlane_vendor_theme_build_onboarding_shell_nav_items()` (onboarding subset), `_myeventlane_vendor_theme_decorate_shell_nav_item()` adds `route_name`, `is_active`, `is_accessible` for Twig-only rendering.
- **Primary order:** Dashboard → Events → Analytics (included only when `myeventlane_analytics.dashboard` exists and access allows) → Attendees (only when `myeventlane_checkout_flow.vendor_attendees` access allows) → Profile (`myeventlane_vendor.console.settings`, label “Profile”, key `profile`). Secondary items follow (Notifications, Orders stub, Refund requests, Audience, Payouts, Growth, Help).

### Active section / routing

- `_myeventlane_vendor_theme_get_active_section()` uses **path prefixes** for dashboard/events/analytics/attendees/profile where needed (e.g. `/vendor/analytics/*`, `/vendor/settings/*`, `/vendor/events/*`, `/vendor/studio/*` → Events active; `/vendor/dashboard/messaging/brand` → Profile so it is not mistaken for Dashboard).

### Create-event CTA updates

- **Shell header:** `shell_primary_action` set in `myeventlane_vendor_theme_preprocess_page()` when `myeventlane_event_studio.create` is accessible — label “Create Event”, URL from `Url::fromRoute()` (no hardcoded paths).
- **Account menu:** `web/modules/custom/myeventlane_vendor/myeventlane_vendor.links.menu.yml` — `myeventlane_vendor.menu_account.create_event` now uses `myeventlane_event_studio.create` (replaces `myeventlane_vendor.console.events_add`).
- **Vendor internal footer:** `footer-internal.html.twig` (vendor theme) uses `footer_context.vendor_console_urls.create_event` built in PHP with access checks (replaces `/node/add/event`).

### Analytics nav

- Top-level **Analytics** item added when route access passes (`myeventlane_analytics.dashboard`). Hidden when module/route missing or Pro/access denies (no disabled stub unless product adds it later).

### Attendees nav

- **Attendees** remains **access-aware**: omitted from the built list when `myeventlane_checkout_flow.vendor_attendees` is not accessible (same pattern as before, order updated).

### Styling

- `web/themes/custom/myeventlane_vendor_theme/src/scss/layout/_navigation.scss` — `.mel-vendor-console.mel-vendor-shell` scoped foundation: warm page background `#FFF9F5`, footer/header borders `#E9E3DE`, primary button `#F26D5B` / hover `#E55C49`, focus ring `#7C83FD`, `prefers-reduced-motion` on sidebar transitions.

### Icons

- `templates/components/sidebar-icon.html.twig` — new `analytics` icon key.

### Messaging Brand / Settings tabs

- Preprocess adds `mel_vendor_route_tabs` for both `myeventlane_vendor.console.settings` and `myeventlane_vendor.console.messaging_brand` so the correct tab is active on each route.

### Files changed

| File |
|------|
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.links.menu.yml` |
| `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` |
| `web/themes/custom/myeventlane_vendor_theme/templates/layout/page.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/includes/sidebar.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/includes/footer-internal.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/sidebar-icon.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/layout/_navigation.scss` |
| Compiled CSS: run `npm run mel:build` — vendor `dist/` is gitignored in this repo (`.gitignore` `/web/themes/custom/**/dist/`). |

### Verification results

Run locally after pull:

- `git status -sb`
- `php -l web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme`
- `npm run mel:build` (and `npm run mel:lint` if SCSS/Twig touched per team habit)
- `ddev drush cr`
- `ddev drush route \| grep -E "myeventlane_vendor.console.dashboard|myeventlane_vendor.console.events|myeventlane_event_studio.create|myeventlane_analytics.dashboard|myeventlane_vendor.console.settings|myeventlane_checkout_flow.vendor_attendees"`

### Residual risks for TASK 3

- Dashboard content may still expose non-canonical links in **controller-built** data (e.g. hard-coded KPI URLs); TASK 3/5 should align those with this shell only where product owns the dashboard view model.

---

## TASK 3 implementation notes

**Date:** 2026-05-05  
**Scope:** Single dashboard view model builder service for `/vendor/dashboard` data orchestration only (no Twig/SCSS/route YAML redesign).

### Services and classes inspected

- `VendorDashboardController` (`myeventlane_vendor`) — existing dashboard variables and `resolveDashboardVendor` / event helpers (not duplicated in TASK 3).
- `myeventlane_vendor.services.yml` — `myeventlane_vendor.current_vendor_resolver`, `user_vendor_membership_query`, `service.ticket_sales`, `service.rsvp_stats`, `service.metrics_aggregator`, controller wiring.
- `MetricsAggregator`, `TicketSalesService`, `RsvpStatsService`, `UserVendorMembershipQuery`, `CurrentVendorResolver`.
- `myeventlane_dashboard` — `VendorDashboardBuilder` (requires `StoreInterface` + date range; returns `metrics` / `events` / `stripe` payload, not the TASK 3 contract).
- `myeventlane_vendor_analytics` — `VendorKpiService` (store + Phase 7 / optional tables); **not** wired into TASK 3 builder to avoid duplicating ticket/RSVP paths already composed by `MetricsAggregator` + to keep revenue aligned with `TicketSalesService` completed-order rules via `getVendorKpis()`.

### Builder strategy

- **Chosen: A** — New orchestration service in `myeventlane_vendor`: `VendorDashboardViewModelBuilder`.
- **Not B:** `myeventlane_dashboard.dashboard_builder` is store-centric and would still require full normalisation to the TASK 3 shape; live dashboard remains `myeventlane_vendor`-owned.
- **Not C:** No existing TASK 3–shaped builder in `myeventlane_vendor`.

### Existing services reused

| Concern | Service |
| -------- | -------- |
| Vendor context | `myeventlane_vendor.current_vendor_resolver` (`resolveFromUser`) |
| Vendor-scoped event IDs | `myeventlane_vendor.user_vendor_membership_query` (`getManagedEventNodeIds`) |
| Revenue / tickets / RSVP KPI strip | `myeventlane_vendor.service.metrics_aggregator` → `TicketSalesService` + `RsvpStatsService` |
| Per-event ticket revenue labels | `myeventlane_vendor.service.ticket_sales` (`getSalesSummary`) |
| Per-event RSVP counts | `myeventlane_vendor.service.rsvp_stats` (`getEventRsvpCount`) |
| Event type (paid / RSVP / both) | `myeventlane_core.event_state_resolver` (`getEventDomainState`) |
| Upcoming event count filter | `UpcomingEventEntityQueryHelper` + entity query |

### Service created

- **Service ID:** `myeventlane_vendor.dashboard_view_model_builder`
- **Class:** `Drupal\myeventlane_vendor\Service\VendorDashboardViewModelBuilder`
- **Method:** `build(AccountInterface $account): array`

### Controller integration

- **Status:** Integrated.
- `VendorDashboardController::dashboard()` adds theme variable `vendor_dashboard_view_model` from `$this->dashboardViewModelBuilder->build($this->currentUser)` (render key `#vendor_dashboard_view_model` via `buildVendorPage`).
- Existing dashboard variables are unchanged.

### Returned top-level keys

`vendor`, `readiness`, `kpis`, `action_queue`, `events`, `analytics_summary`, `empty_state` — structure matches TASK 3 spec (nested keys including `events[].links` with canonical route names where generated).

### Known limitations

- **Vendor resolution:** Builder uses `CurrentVendorResolver` only; the controller also resolves vendor via user reference fields and store fallback (`resolveDashboardVendor`). Rare accounts may see mismatched `vendor` block vs controller `vendor` entity until TASK 5 aligns or resolver is extended.
- **Stripe readiness:** Derived read-only from the vendor-linked commerce store fields (`field_stripe_*`); no Stripe API and no `VendorEventStudioCreateService` (that service can persist vendor/store links).
- **`myeventlane_dashboard`:** Not called; documented above.
- **Analytics summary:** `analytics_summary.available` uses route + `access_manager` check on `myeventlane_analytics.dashboard`; `items` left empty for TASK 4+.

### Files changed

| File |
|------|
| `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php` |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` |
| `docs/vendor-console-v2-route-map.md` |

### Verification results

Run locally after pull:

- `git status -sb`
- `php -l web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`
- `php -l web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`
- `ddev drush cr`
- `ddev drush php:eval "\$b = \Drupal::service('myeventlane_vendor.dashboard_view_model_builder'); var_dump(get_class(\$b));"`
- `ddev drush php:eval "\$m = \Drupal::service('myeventlane_vendor.dashboard_view_model_builder')->build(\Drupal::currentUser()); print_r(array_keys(\$m));"` (avoid printing full model in logs — contains site-specific counts only).

### Residual risks for TASK 4

- **Action queue** is intentionally `[]`; TASK 4 must populate without duplicating notification/quick-action builders from the controller.

---

## TASK 4 implementation notes

**Date:** 2026-05-05  
**Scope:** Vendor Action Queue service (`VendorActionQueueBuilder`) + wiring into `VendorDashboardViewModelBuilder` only (no dashboard Twig/SCSS/controller changes).

### Files inspected

- `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php` — TASK 3 model shape (`vendor`, `readiness`, `events[]`, `analytics_summary`, event `links`, `event_type`, `status`).
- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` — service registration patterns.
- `docs/vendor-console-v2-audit.md` — route names for Stripe, settings, Event Studio create, events index.
- `VendorDashboardController.php` (read-only via TASK brief / grep) — existing `quick_actions` left untouched; no duplication.

### Service created

| Item | Value |
|------|--------|
| Service ID | `myeventlane_vendor.action_queue_builder` |
| Class | `Drupal\myeventlane_vendor\Service\VendorActionQueueBuilder` |
| Method | `build(array $dashboardModel, AccountInterface $account): array` |

Constructor dependencies: `router.route_provider`, `access_manager`, `logger.factory`, `string_translation` (for `t()` only).

Private helpers: `routeExists()`, `routeUrl()`, `routeUrlIfAccessible()` — optional routes skipped when missing; URLs built with `Url::fromRoute()` only.

### Action rules implemented

1. **No vendor profile** — `vendor.id` empty → warning, priority 10, settings URL if `myeventlane_vendor.console.settings` exists and access allows.
2. **Profile incomplete** — `readiness.profile_complete` false or readiness item `profile` with `complete` false; skipped when `vendor.id` empty → warning, priority 20.
3. **Stripe / payout incomplete** — readiness item `stripe` incomplete or `stripe_ready` false with ≥1 `paid`/`both` event in dashboard `events` → error + priority 30 when paid/both exists; otherwise warning + priority 80. URL: `myeventlane_vendor.stripe_connect` (with `destination=/vendor/dashboard`) if accessible, else `myeventlane_vendor.stripe_manage`. No Stripe API. **Skipped when `vendor.id` is empty** (no vendor-linked store to attach Connect to; action 1 covers setup first).
4. **No events** — `events` empty → info, priority 40, `myeventlane_event_studio.create` if accessible.
5. **Draft events** — `status`/`status_label` indicates draft/unpublished/incomplete → max **2** actions, warning, priority 50, `links.edit` when present.
6. **Published + unknown booking mode** — published rows (`status` `upcoming` or `past`) with `event_type === unknown` → error, priority 25, edit URL with tickets fallback from `links`.
7. **Analytics unavailable** — `analytics_summary.available` false and ≥1 paid/both event → info, priority 90, `myeventlane_vendor.console.events`.

Sorting: ascending `priority`, then severity order error → warning → info → success; **maximum 6** items returned.

### Existing data reused

- Dashboard model only: vendor payload, readiness flags/items, event rows (type, status, labels, `links`, metrics labels), analytics availability flag.
- Route existence + `access_manager::checkNamedRoute` for optional URLs (no UI assumptions).

### Skipped rules and why

- **§7 Upcoming event — attendee prep:** dashboard event rows expose `date_label` (formatted) but **no machine timestamp** in the public model; per TASK 4 brief, **do not parse `date_label`** — rule skipped until TASK 5+ adds a reliable machine date or TASK 4 extends the model (out of scope for allowed files).
- **High views / low conversion, waitlist:** not implemented — no such metrics in the dashboard model and no additional services wired.

### Verification results

Run from repo root (after pull):

- `git status -sb`
- `php -l web/modules/custom/myeventlane_vendor/src/Service/VendorActionQueueBuilder.php`
- `php -l web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php`
- `ddev drush cr`
- `ddev drush php:eval "\$q = \Drupal::service('myeventlane_vendor.action_queue_builder'); var_dump(get_class(\$q));"`
- `ddev drush php:eval "\$m = \Drupal::service('myeventlane_vendor.dashboard_view_model_builder')->build(\Drupal::currentUser()); print_r(array_keys(\$m)); print PHP_EOL; print 'action_queue_count=' . count(\$m['action_queue']) . PHP_EOL;"`

If `ddev` is unavailable locally, note **not run** in the PR/summary.

### Residual risks for TASK 5

- **False positives for “Add RSVP or tickets”** when `event_type` is `unknown` but the event actually sells tickets or accepts RSVPs — domain resolver vs. reality may drift; UI should allow dismiss or deep-link to Event Studio tickets section.
- **`links.edit` / `links.tickets`** may be NULL if routes missing; actions still appear with null URL — Twig layer should handle missing CTA.
- **Stripe URL** follows console access rules; vendors denied Connect but allowed manage may see null URL until access/route parity is reviewed (TASK 11).
- **Attendee-prep action** still absent until machine dates exist on event rows.
- **Dual vendor resolution** paths may produce inconsistent `vendor` vs legacy `$vendor` in Twig until TASK 5 consumes only the view model or resolver parity improves.
- **Pro-only routes** (e.g. event workspace analytics) still emit `Url` objects when the route exists; access may deny at request time — callers should treat links as hints unless TASK 5 adds access-aware hiding.
- `OrganiserContextBlock` and **public** theme `footer-internal.html.twig` still reference legacy/wrong create routes or `/node/add/event` — out of TASK 2 scope; TASK 11/12 per route map.
- Duplicate “Create” affordances may appear where **region/header** blocks also render account menu items alongside `shell_primary_action`; cosmetic consolidation is a later UX pass.

---

## TASK 5 implementation notes

**Date:** 2026-05-05  
**Scope:** `/vendor/dashboard` UI renders from `vendor_dashboard_view_model` (TASK 3 model + TASK 4 `action_queue`). UI-only; no new dashboard data services, route YAML, or access changes.

### Template chosen

- Primary implementation: [`web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig`](../web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig) (`myeventlane_vendor_dashboard` theme hook).
- [`page--vendor-dashboard.html.twig`](../web/themes/custom/myeventlane_vendor_theme/templates/page--vendor-dashboard.html.twig) unchanged (thin `page.html.twig` wrapper).

### Controller changes

- [`VendorDashboardController::dashboard()`](../web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php): removed Chart.js `drupalSettings.vendorCharts` (placeholder/zero-only chart payload); set `charts` to `[]` for backward compatibility; removed unused private `buildChartData()` (fake analytics).
- Existing dashboard variables (`kpis`, `events`, `quick_actions`, `dashboard_*`, `stripe`, etc.) **retained** for other preprocess/alters and fallback; the dashboard Twig no longer renders legacy KPI grids, quick_actions, activity, alerts rail, audience/stripe rails, or legacy event performance list.

### Model keys rendered

- `vendor` (`label`, `settings_url`, optional `profile_url` — not used as hero primary in this layout)
- `readiness` (`score`, `items[]`: `label`, `complete`, `severity`, optional `url`)
- `kpis[]`: `label`, `value`, `context`, `severity`, optional `url` (card links when URL present)
- `action_queue[]`: `severity`, `title`, `message`, optional `action_label` + `url` (content always shown when URL missing)
- `events[]` (max 6): `status_label`, `title`, `date_label`, `event_type` (presentation labels), `metric_label`, `revenue_label`, optional `capacity_label`, `links.manage` / `links.edit` / `links.analytics` only when non-null (canonical routes from builder)
- `analytics_summary`: section only when `available` is true **and** `items` non-empty (currently typically empty)
- `empty_state`: hero CTAs + “Your events” empty block (duplicate primary CTA suppressed when hero already shows the same empty-state action)

### Old dashboard UI

- **Removed from Twig:** legacy hero copy, `dashboard_kpis`, `dashboard_action_cards`, `dashboard_activity_items`, `dashboard_alerts` rail, audience summary rail, Stripe panel rail, legacy `dashboard_event_performance` list, hard-coded `quick_actions`, `show_welcome` empty include.
- **Retained when present:** `onboarding_panel`, `mel_contribution_billing_strip`, `mel_top_boost_opportunity`, `growth_cards` (+ existing library attachment from controller), optional Pro/launch blocks driven by existing variables.

### SCSS / library

- Styles: [`web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_mel-dashboard.scss`](../web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_mel-dashboard.scss) — new block scoped under `.mel-vendor-dashboard-v2` (imported via existing `main.scss`).
- [`myeventlane_vendor_theme.libraries.yml`](../web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.libraries.yml): `dashboard` library no longer pulls Chart.js (dashboard page does not render charts).

### Verification results

Run locally after pull:

- `git status -sb`
- `php -l web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php`
- `npm run mel:build` and `npm run mel:lint`
- `ddev drush cr`
- `ddev drush php:eval "\$m = \Drupal::service('myeventlane_vendor.dashboard_view_model_builder')->build(\Drupal::currentUser()); print_r(array_keys(\$m)); print PHP_EOL; print 'actions=' . count(\$m['action_queue']) . '; events=' . count(\$m['events']) . PHP_EOL;"`

**Browser smoke:** not run in this session (no authenticated `/vendor/dashboard` session in the agent environment).

### Residual risks for TASK 6

- Header **Create Event** (`shell_primary_action`) plus hero primary can both appear when the model empty-state CTA is also create (product may want one CTA later).
- `vendor` block in the model vs legacy controller `resolveDashboardVendor()` mismatch noted in TASK 3 remains possible for edge accounts.
- Theme hook `myeventlane_vendor_dashboard` could declare `vendor_dashboard_view_model` in `hook_theme` defaults for clarity (controller already passes it on the render array).

---

## TASK 6 implementation notes

**Date:** 2026-05-05  
**Scope:** `/vendor/events` events index UI + `VendorEventIndexViewModelBuilder` only. Event Studio, workspace ticket sync, RSVP saves, analytics computation, vendor settings, and route YAML unchanged.

### Controller inspected/changed

- [`web/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php`](../web/modules/custom/myeventlane_vendor/src/Controller/VendorEventsController.php) — injects `myeventlane_vendor.event_index_view_model_builder`, reads `status` + `sort` query params, renders `myeventlane_vendor_events_grid` with `#vendor_event_index_model`. When `summary.total > 0`, attaches `VendorEventsBulkActionsForm` below in a wrapper; when zero events, bulk form is omitted so the index empty state is not duplicated by the view-driven form empty state.

### Builder strategy

- **New service** [`VendorEventIndexViewModelBuilder`](../web/modules/custom/myeventlane_vendor/src/Service/VendorEventIndexViewModelBuilder.php) (`myeventlane_vendor.event_index_view_model_builder`).
- **Not** extending or copying `VendorDashboardViewModelBuilder` bodies — same *patterns* only (membership IDs → load nodes → `EventStateResolver` + ticket/RSVP summaries + route/access-safe URLs).
- Inline route helpers (`routeExists`, `routeUrlIfAccessible`) mirror the small private helpers used in `VendorActionQueueBuilder` / dashboard builder (no shared trait added).

### Services reused

| Concern | Service |
| -------- | -------- |
| Scoped event IDs | `myeventlane_vendor.user_vendor_membership_query::getManagedEventNodeIds($uid, FALSE)` |
| Node load | `entity_type.manager` |
| Paid / RSVP domain | `myeventlane_core.event_state_resolver::getEventDomainState()` |
| Ticket revenue / sold | `myeventlane_vendor.service.ticket_sales::getSalesSummary()` |
| RSVP counts | `myeventlane_vendor.service.rsvp_stats::getEventRsvpCount()` |
| Boost chip eligibility | `entity_field.manager` — `field_promoted` on `node` `event` bundle |
| Optional link URLs | `router.route_provider` + `access_manager::checkNamedRoute` |

### Model shape

- Matches TASK 6 contract: `title`, `subtitle`, `primary_action`, `filters` (`active`, `items[]` with `key`, `label`, `url`, `active`, `count`), `sort`, `summary` (`total`, `draft`, `published`, `needs_attention`, `paid`, `rsvp`), `events[]` (includes `status_severity`, `links.*`), `empty_state`.
- Anonymous users receive a minimal guest model (empty filters/sort chips; events `[]`).

### Filters / sorts implemented

- **Query params:** `?status=all|draft|published|needs_attention|past|rsvp|paid|boosted` (invalid → `all`; `boosted` ignored → `all` when `field_promoted` missing).
- **Sort:** `?sort=soonest|updated` (default `soonest`).
- **Filter/sort URLs:** `Url::fromRoute('myeventlane_vendor.console.events')` with query options only (no hardcoded `/vendor/events?…` strings).
- **Boosted:** Uses boolean `field_promoted` only — no heuristic “boost” scoring.

### Template chosen

- [`web/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig`](../web/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig) — theme hook `myeventlane_vendor_events_grid` (module `hook_theme` adds `vendor_event_index_model` variable; legacy `events` retained).

### Bulk form template

- [`form--mel-vendor-events.html.twig`](../web/themes/custom/myeventlane_vendor_theme/templates/form--mel-vendor-events.html.twig) — removed duplicate “Your events” toolbar + Create link (CTA is index header only). Keeps list/grid toggle + bulk actions.

### SCSS / library

- New [`pages/_vendor-events.scss`](../web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_vendor-events.scss), imported from [`main.scss`](../web/themes/custom/myeventlane_vendor_theme/src/scss/main.scss).
- No new library: relies on existing `global-styling` / `mel_vendor_events` (bulk) attachments.

### Canonical route links generated

- `manage` → `myeventlane_vendor.console.event_workspace` (`event` param)  
- `edit` → `myeventlane_event_studio.edit` (`node`)  
- `tickets` → `myeventlane_vendor.console.event_tickets` (`event`)  
- `rsvps` → `myeventlane_vendor.console.event_rsvps` (`event`)  
- `orders` → `myeventlane_vendor.console.event_orders` (`event`)  
- `attendees` → `myeventlane_event_attendees.vendor_list` (`node`)  
- `analytics` → `myeventlane_vendor.console.event_analytics` (`event`)  
- Primary create → `myeventlane_event_studio.create`

### Legacy links removed / remaining

- **Removed from this page’s themed bulk toolbar:** `/vendor/events/add`-style CTA and duplicate title row (Create now index-only via `myeventlane_event_studio.create`).
- **Not introduced:** `/vendor/event/…`, `/vendor/events/add`, `/node/add/event`, `/vendor/studio` as management links.
- **Still elsewhere in repo:** legacy routes and footers noted in TASK 1–2 docs (out of TASK 6 scope).

### Verification results

- `php -l` on `VendorEventIndexViewModelBuilder.php`, `VendorEventsController.php` — OK.
- `npm run mel:build`, `npm run mel:lint` — OK.
- `ddev drush cr` — OK.
- `ddev drush php:eval` on `event_index_view_model_builder->build()` — top-level keys OK (anonymous user → `filters=0` items expected).

**Browser smoke:** not run (no manual `/vendor/events` session in agent).

### Residual risks for TASK 7

- **Dual lists:** View-driven bulk form (`mel_vendor_events`) vs membership-driven index model may diverge if View filters differ from `getManagedEventNodeIds()` — bulk actions apply only to view rows; organisers should treat the card grid as navigation/metrics and bulk as operational subset until unified (product decision). Bulk UI is hidden entirely when the index model reports zero managed events (avoids double empty states).
- **Needs attention** omits Stripe/payout gating (per TASK 6 — avoids duplicating dashboard Stripe readiness); paid events may still show “healthy” until TASK 7 readiness surfaces align.
- **Shell Create Event** plus index primary CTA may both appear (same as dashboard residual).
- **Moderation:** `moderation_state` = `review` maps to status `unknown` / “Needs review”; filtering uses internal `status` values (`published`/`past`/`draft`/`unknown`) — only draft/published/past/needs_attention/rsvp/paid/boosted exposed as chips.

---

## TASK 7 implementation notes

**Date:** 2026-05-05  
**Scope:** Canonical Event Studio create/edit UX in the vendor console; section nav + copy; advanced ticket manager posture; `field_product_target` preservation on paid saves; no new ticket/commerce builders; legacy routes retained.

### Files inspected (TASK brief)

- Routing: `myeventlane_event_studio.routing.yml`, `myeventlane_vendor.routing.yml`, `myeventlane_event.routing.yml` (build wizard paths).
- Event Studio: `EventStudioController.php`, `EventStudioForm.php`, `EventStudioBaseForm.php`, `EventStudioTicketsForm.php`, `EventStudioSaveService.php`, `VendorLegacyWizardRedirectSubscriber.php`, `mel-event-studio.html.twig`, `mel-event-studio-nav.html.twig`, `mel-event-studio-wizard-nav.html.twig`, `mel-event-studio.js`, `EventStudioPreprocess.php`.
- Vendor studio API shell: `VendorStudioController.php`, `vendor-studio.js` (default `/vendor/studio` endpoints).
- Legacy: `VendorEventCreateController.php`, `ManageEventEditController.php`, `ManageEventTicketsController.php`, `ManageEventNavigation.php` (legacy manage stack; not linked from TASK 6 index).
- Parallel build: `/vendor/events/{event}/build/*` in `myeventlane_event` — vendors hitting studio step routes are redirected to unified edit via `VendorLegacyWizardRedirectSubscriber`.

### Canonical links changed

- Event Studio **sidebar section nav** (`mel-event-studio-nav.html.twig`): six `a.mel-nav-link` items aligned with `MEL_STEPS` order in `mel-event-studio.js` (Basics & date → Tickets or RSVP → Details → Guest questions → Preview → Publish); removed redirect of the Tickets nav item to `myeventlane_vendor.console.event_tickets`.
- **Optional** “Advanced ticket manager” link: URL built access-checked on the form (`EventStudioForm` `#mel_advanced_ticket_manager_url`), exposed via `hook_preprocess_mel_event_studio` (`myeventlane_event_studio.module`) for the Twig sidebar.
- Vendor theme copy: events grid secondary edit → “Edit event”; optional tickets column → “Advanced ticket manager”; vendor card / event table / performance block / event settings page → “Edit event” instead of “Open Event Studio”.
- Page chrome: unified studio `<title>` via `EventStudioController::editTitle()` and visually-hidden H1 on edit → “Edit event” (`mel-event-studio.html.twig`).
- Staff horizontal wizard nav (`mel-event-studio-wizard-nav.html.twig`): same section labels for consistency.

### Legacy links retained (and why)

- **`myeventlane_vendor.console.events_add`** (`/vendor/events/add`): redirect controller still valid for bookmarks; TASK 2 menu already uses `myeventlane_event_studio.create`; no new vendor CTAs added here.
- **`myeventlane_vendor.manage_event.edit`** (`/vendor/event/{event}/edit`): redirect to Event Studio edit; not linked from TASK 6 grid or updated studio shell.
- **`/vendor/events/{event}/build/*`**: `myeventlane_event` wizard routes remain; vendor studio step routes redirect to unified edit; full deprecation is TASK/product follow-up.
- **`myeventlane_vendor.console.studio`** and POST `/vendor/studio/event/{event}/*`**: unchanged — internal/advanced API used by `vendor-studio.js` and `VendorStudioController`.
- **`mel_event_actions.tickets`** in `EventStudioPreprocess`: still populated for any legacy/debug consumers; primary sidebar no longer uses it for navigation.

### Event Studio section nav status

- **Unified edit** (`myeventlane_event_studio.edit`): sidebar lists six steps matching JS `MEL_STEPS` (combined “Basics & date” reflects that scheduling lives in the same `mel-step-identity` panel as title essentials — separate `/edit/datetime` routes exist but vendors are redirected to unified edit).
- **Route-based step forms** (`edit_basic`, `edit_datetime`, …): remain for staff/admin; vendors redirected to unified edit per existing subscriber.

### Ticket setup / advanced ticket manager status

- Normal ticket and RSVP setup stays **inside** Event Studio (`mel-step-tickets`, embedded ticket UI / `EventTicketManagerForm` as already implemented).
- **`/vendor/events/{event}/tickets`** is labeled **Advanced ticket manager** on the events index optional links and offered as an optional sidebar link when route access allows — never as the primary Tickets nav target inside Studio.

### `field_product_target` preservation review

- **`EventStudioMelPayloadService::buildFromFormState()`** (unchanged): restores product id from the loaded node when autocomplete is missing from POST for paid flows.
- **`EventStudioSaveService::applyTicketPayload()`** (updated): for **paid** events, empty/missing product id in the payload **no longer clears** an existing `field_product_target`; invalid positive IDs still log and no longer wipe a previously valid link. Clearing happens when booking mode is **not** paid (existing behaviour). Comment documents conditional UI / autosave / wizard omissions.
- Autosave endpoint builds payloads without the Mel payload helper’s preservation step; the SaveService change covers those saves too.

### `/vendor/studio` posture

- No new shell or index links added; theme continues to treat `/vendor/studio` paths as **Events** active section only when deep-linked (`myeventlane_vendor_theme.theme`).
- Default schema/save URL strings remain in `vendor-studio.js` for API compatibility.

### Verification results

- `git status -sb` — working tree shows broader branch changes; TASK 7 touches are listed under **Files changed** below.
- `php -l` on `EventStudioSaveService.php`, `EventStudioController.php`, `EventStudioForm.php`, `myeventlane_event_studio.module` — OK.
- `npm run mel:build` — OK.
- `npm run mel:lint` — OK (project scripts as defined).
- `ddev drush cr` — OK.
- `ddev drush route | grep -E "myeventlane_event_studio.create|…"` — canonical + legacy + studio routes present as expected.

### Grep verification (`myeventlane_vendor` / `myeventlane_event_studio` / `myeventlane_vendor_theme`)

| Hit | Classification |
|-----|----------------|
| `myeventlane_vendor.routing.yml` paths `/vendor/event/.../edit`, `/vendor/events/add`, `/vendor/studio*` | **Legacy redirect/API routes retained** |
| `VendorStudioController` docblock | **Internal / advanced** |
| `VendorEventCreateController` docblock | **Legacy redirect retained** |
| `myeventlane_vendor_theme.theme` `/vendor/studio` prefix | **Active-section helper for deep links (not a CTA)** |
| `vendor-studio.js` default endpoints | **Safe internal/API defaults** |

Remaining **`/node/add/event`** and **`myeventlane_vendor.console.create_event`** issues are **public theme / OrganiserContextBlock** — **TASK 11/12** per route map §6.

### Browser smoke

- Not run in this session (no manual authenticated vendor session logged).

### Residual risks for TASK 8

- Event **workspace** tabs and overview CTAs may still use legacy wording (“Open Event Studio”) outside files touched here — align when workspace shell is in scope.
- Parallel **`/vendor/events/{event}/build/*`** wizard still exists at routing level; bookmarks may confuse organisers until redirected or hidden globally.
- **`ManageEventNavigation`** still references legacy manage routes for the old manage stack — harmless if that UI is unreachable to vendors.
- **Explicit unlink** of a ticket product while staying on “Paid” may remain constrained by payload preservation + autocomplete behaviour (product intent should confirm before tightening).

### Files changed (TASK 7)

| File |
|------|
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.module` |
| `web/modules/custom/myeventlane_event_studio/css/mel-event-studio-nav.css` |
| `web/modules/custom/myeventlane_event_studio/js/mel-event-studio.js` |
| `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php` |
| `web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php` |
| `web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php` |
| `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-nav.html.twig` |
| `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio-wizard-nav.html.twig` |
| `web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/myeventlane-vendor-events-grid.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/node--event--vendor-card.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/vendor-event-performance.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/event-table.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/event/mel-event-settings-page.html.twig` |

---

## TASK 8 implementation notes

**Date:** 2026-05-05  
**Scope:** `/vendor/events/{event}` event workspace UI + `VendorEventWorkspaceViewModelBuilder` only. No Event Studio, ticket sync, RSVP saves, checkout, analytics computation, vendor profile, or route YAML changes.

### Controllers inspected/changed

- **Changed:** [`web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php`](../web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php) — `workspace()` now renders `mel_event_workspace` with `#vendor_event_workspace_model` instead of redirecting to `myeventlane_vendor.console.event_overview`. `publish()` fallback redirect target updated from overview route to `myeventlane_vendor.console.event_workspace` when Event Studio route is missing (fatal edge case).
- **Read for parity (unchanged):** `VendorEventOverviewController`, `VendorConsoleBaseController`, related event sub-controllers, `VendorEventTabsService` consumers.

### Builder strategy

- **New:** `Drupal\myeventlane_vendor\Service\VendorEventWorkspaceViewModelBuilder` — service ID `myeventlane_vendor.event_workspace_view_model_builder`, method `build(NodeInterface $event, AccountInterface $account): array`.
- Orchestrates readiness, next action, metrics, canonical action URLs, and delegates tab rows to `VendorEventTabsService::buildWorkspaceTabs()` (no duplication of dashboard/index builder bodies).

### Services reused

| Concern | Service |
| -------- | -------- |
| Paid / RSVP domain | `myeventlane_core.event_state_resolver` |
| Ticket gross / sold / orders count | `myeventlane_vendor.service.ticket_sales` |
| RSVP confirmed count | `myeventlane_vendor.service.rsvp_stats` |
| Workspace + legacy tab definitions | `myeventlane_vendor.service.event_tabs` (`VendorEventTabsService`) |
| Route existence + link access | `router.route_provider`, `access_manager` |
| Event field presence (body, image, dates) | `entity_field.manager` |
| Date labels | `date.formatter`, `datetime.time` |

### Model shape

Top-level keys: `event`, `readiness` (`score`, `items[]`), `next_action` (nullable array), `metrics[]`, `tabs[]`, `actions` (edit, advanced_tickets, rsvps, orders, attendees, checkin, analytics, settings, preview), `empty_state`. Nested shapes match TASK 8 spec (`Url|null` where specified).

### Tabs strategy

- **`VendorEventTabsService`** refactored: canonical `Url::fromRoute()` + `access_manager` checks; **Overview** tab targets `myeventlane_vendor.console.event_workspace` (numeric `{event}`); tickets tab label **Advanced ticket manager**; **Promotion** tab appended when route exists; **Boost** uses `myeventlane_boost.vendor_event_boost`; refund requests use `myeventlane_refunds.vendor_refund_requests` with `{node}`.
- **`buildWorkspaceTabs()`** returns TASK 8 tab rows (`key`, `label`, `url`, `active`, `available`) for the workspace template.
- **`getTabs()`** retains the legacy array shape (`url` string, `disabled`) for other `mel_event_workspace` pages.

### Canonical route links generated (workspace model)

- `myeventlane_event_studio.edit` (`node`), `myeventlane_vendor.console.event_tickets` (`event`), `myeventlane_vendor.console.event_rsvps`, `myeventlane_vendor.console.event_orders`, `myeventlane_event_attendees.vendor_list` (`node`), `myeventlane_checkin.page` (`node`), `myeventlane_vendor.console.event_analytics`, `myeventlane_vendor.console.event_settings`, `entity.node.canonical` (preview/public when allowed).

### Legacy links removed/remaining

- **Removed from workspace root:** 302 redirect to `/vendor/events/{event}/overview`; workspace header/insights duplication when the TASK 8 model is present; hardcoded `/vendor/events/{id}/…` strings in tab service (replaced by routes).
- **Remaining hits (grep):** legacy **`/vendor/event/…`** and **`/vendor/studio`** definitions in `myeventlane_vendor.routing.yml`, **`VendorStudioController`**, **`vendor-studio.js`** defaults, **`VendorEventOverviewController` / `VendorEventSettingsController`** “Open Event Studio” labels, **`VendorDashboardController`** waitlist path string, **`manage-event.css`** comments — classified as **legacy retained**, **internal/API**, or **out of TASK 8 scope** (subpages/settings/dashboard).

### Template chosen

- [`web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig`](../web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig) — TASK 8 block under `.mel-event-workspace-v2*` when `vendor_event_workspace_model` is present; legacy shell/tabs/insights preserved when the model is absent.
- [`workspace-tabs.html.twig`](../web/themes/custom/myeventlane_vendor_theme/templates/components/workspace/workspace-tabs.html.twig) — supports `disabled` (legacy) and `available` (workspace model).

### SCSS / library

- Styles appended to [`web/themes/custom/myeventlane_vendor_theme/src/scss/components/_workspace.scss`](../web/themes/custom/myeventlane_vendor_theme/src/scss/components/_workspace.scss) (scoped BEM under `.mel-event-workspace-v2`). No new library entry; **`npm run mel:build`** compiles into vendor theme `dist/main.css`.

### Theme hook

- `mel_event_workspace` gains optional variable `vendor_event_workspace_model` in [`myeventlane_vendor_theme.theme`](../web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme).

### Verification results

- `php -l` on `VendorEventWorkspaceViewModelBuilder.php`, `VendorEventTabsService.php`, `EventWorkspaceController.php` — OK.
- `npm run mel:build`, `npm run mel:lint` — OK.
- `ddev drush cr` — OK.
- `ddev drush php:eval` on `event_workspace_view_model_builder->build()` — top-level keys OK; sample `tabs=10`, `metrics=4`.

### Browser smoke

- **Not run** in this session (no manual authenticated vendor browser session).

### Residual risks for TASK 9

- **`/vendor/events/{event}/overview`** remains a separate Mission Control page; bookmarks differ from workspace root until product consolidates or redirects.
- **Secondary local tasks** (`*.links.task.yml`) may still point at overview route — possible duplicate “Overview” affordances vs workspace URL (cosmetic / TASK 11+).
- **Promotion** tab has no `active` wiring from `VendorEventCommsForm` shell if that form does not pass tab active key `promotion` (minor UX).
- **Tab access** denial surfaces as unavailable tabs (no URL); rare edge accounts should be verified under TASK 11.

### Files changed (TASK 8)

| File |
|------|
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` |
| `web/modules/custom/myeventlane_vendor/src/Controller/EventWorkspaceController.php` |
| `web/modules/custom/myeventlane_vendor/src/Service/VendorEventTabsService.php` |
| `web/modules/custom/myeventlane_vendor/src/Service/VendorEventWorkspaceViewModelBuilder.php` |
| `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` |
| `web/themes/custom/myeventlane_vendor_theme/templates/mel-event/mel-event-workspace.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/workspace/workspace-tabs.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_workspace.scss` |

---

## TASK 9 implementation notes

**Date:** 2026-05-05  
**Scope:** Vendor analytics UI/link alignment and view-model orchestration only. No new analytics computation modules, no checkout/Event Studio/vendor settings/Stripe changes, route YAML unchanged.

### TASK 8 hotfix (reference)

- **`VendorCommsController`:** property collision with `ControllerBase::$currentUser` was fixed by removing an injected/promoted `current_user` and using `$this->currentUser()` inside `checkAccess()` (TASK 8 hotfix). Not re-modified in TASK 9.

### Analytics routes inspected

- `myeventlane_analytics.routing.yml` — `myeventlane_analytics.dashboard` (`/vendor/analytics`), `myeventlane_analytics.event` (`/vendor/analytics/event/{node}`), `myeventlane_analytics.export_pdf`, `myeventlane_analytics.export_excel`.
- `myeventlane_vendor.routing.yml` — `myeventlane_vendor.console.event_analytics` (`/vendor/events/{event}/analytics`).

### Route posture decision

| Surface | Path | Route | Role |
| ------- | ---- | ----- | ---- |
| Vendor-wide analytics | `/vendor/analytics` | `myeventlane_analytics.dashboard` | **Canonical** account-level dashboard |
| Event workspace analytics | `/vendor/events/{event}/analytics` | `myeventlane_vendor.console.event_analytics` | **Canonical** per-event analytics (Pro-gated as today) |
| Legacy / advanced | `/vendor/analytics/event/{node}` | `myeventlane_analytics.event` | **Retained** — funnel, time-series, charts, PDF/Excel export siblings; not used as primary CTA from new console lists |

### Services reused (no duplicate ticket/revenue math)

| Concern | Service |
| -------- | -------- |
| Vendor KPI strip (aligned with TASK 3) | `myeventlane_vendor.service.metrics_aggregator` → `TicketSalesService` + `RsvpStatsService` |
| Per-event gross / tickets sold labels | `myeventlane_vendor.service.ticket_sales` (`getSalesSummary`) |
| Per-event RSVP counts | `myeventlane_vendor.service.rsvp_stats` (`getEventRsvpCount`) |
| Managed event scope | `myeventlane_vendor.user_vendor_membership_query` |
| Event type (paid / RSVP / both) | `myeventlane_core.event_state_resolver` |
| Upcoming published count | `UpcomingEventEntityQueryHelper` + entity query (same pattern as dashboard builder) |

**Not used:** `myeventlane_vendor_analytics.vendor_kpi` — overlaps Phase 7/store-centric KPIs; TASK 3 already documented preferring `MetricsAggregator` for completed-order-safe semantics.

**Intentionally not wired:** vendor-wide **conversion** KPI — would require a real aggregation API; `conversion_label` on events stays unset.

### Model / builder strategy

- **New:** `Drupal\myeventlane_analytics\Service\VendorAnalyticsViewModelBuilder` — service ID `myeventlane_analytics.vendor_view_model_builder`, method `build(AccountInterface $account, array $filters = [])`.
- **`$filters`:** reserved; **not applied** — underlying KPI services are not date-range aware. `date_range.items` is empty; documented limitation.
- **`AnalyticsDashboardController::dashboard()`** delegates to the builder and passes `#analytics_model` to `myeventlane_analytics_dashboard`. Removed `DashboardEventLoader` + per-event `AnalyticsDataService` time-series summation for the vendor-wide row list (avoided divergent “author-only” scope vs team-managed events).

### Vendor-wide analytics template

- **Module template:** `web/modules/custom/myeventlane_analytics/templates/analytics-dashboard.html.twig` — renders TASK 9 model (KPI cards, event cards, empty state). Pro overlay preserved via `myeventlane_pro_preprocess_myeventlane_analytics_dashboard`.
- **Theme styles:** `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_analytics.scss` — BEM under `.mel-vendor-analytics-v2*` (mobile-first, 16px cards, 44px targets, focus-visible).

### Event workspace analytics controller / template

- **`VendorEventAnalyticsController`:** injects `access_manager` + `logger.factory`; passes `workspace_back_url`, `edit_event_url`, `export_pdf_url`, `export_excel_url` into `myeventlane_vendor_event_analytics` when routes exist and export routes pass access checks.
- **`event/analytics.html.twig`:** toolbar row — Back to workspace, Edit event, Export PDF/Excel (each omitted when URL null).

### Legacy analytics module event page

- **`AnalyticsDashboardController::eventAnalytics`:** passes access-checked export URLs and workspace / vendor-home links into `analytics-event.html.twig`.
- **Template:** clarified copy as **Advanced analytics** (timeline/funnel); primary workspace analytics called out in subtitle.

### Canonical links generated

- Vendor-wide event rows: **View event analytics** → `myeventlane_vendor.console.event_analytics`; **View workspace** → `myeventlane_vendor.console.event_workspace` (when `access_manager` allows).
- **Legacy dashboard table data:** `VendorDashboardController::getEventsTableData()` `analytics_url` → `myeventlane_vendor.console.event_analytics` via `safeRouteUrl` (replacing hard-coded `/vendor/analytics/event/{id}`).
- **Twig:** `best-event-card.html.twig`, `event-table.html.twig` — analytics link wrapped in `{% if event.analytics_url %}` for null-safe URLs.

### Legacy analytics links retained

- **`myeventlane_analytics.event`** + **export routes** — unchanged paths; used for advanced view and downloads.
- **No removal** of export or legacy route definitions.

### Fake / placeholder analytics

- **Removed from vendor-wide dashboard:** duplicate computation loop that summed `AnalyticsDataService::getSalesTimeSeries` per event for table totals (replaced by membership-scoped builder using commerce-complete semantics via `TicketSalesService`).
- **Not introduced:** charts or conversion metrics on `/vendor/analytics` beyond existing real KPIs.

### Verification results

Run locally after pull:

- `git status -sb`
- `php -l` on each changed PHP file
- `npm run mel:build` and `npm run mel:lint`
- `ddev drush cr`
- `ddev drush route | grep -E "myeventlane_analytics.dashboard|myeventlane_analytics.event|myeventlane_vendor.console.event_analytics|myeventlane_analytics.export_pdf|myeventlane_analytics.export_excel"`
- `ddev drush php:eval "\$b=\Drupal::service('myeventlane_analytics.vendor_view_model_builder'); var_dump(get_class(\$b)); \$m=\$b->build(\Drupal::currentUser()); print_r(array_keys(\$m));"`
- Grep classification (remaining hits): canonical workspace vs export/advanced legacy vs TASK 11/12 cleanup elsewhere.

### Browser smoke

- **Not run** in this session (no authenticated vendor browser against a live `/vendor/analytics` session).

### Residual risks for TASK 10

- **`myeventlane_analytics.info.yml`** still declares a dependency on `myeventlane_dashboard` though the dashboard controller no longer uses `DashboardEventLoader` — harmless but could be cleaned in a dependency-hygiene pass (not required for TASK 10).
- **Pro overlay** on `/vendor/analytics` (`myeventlane_pro_preprocess_myeventlane_analytics_dashboard`) may interact with route-level Pro requirement — verify UX with product if double-gating confuses organisers.
- **Team-managed vs author-only:** legacy `DashboardEventLoader` scope differed from membership query; organisers who relied on author-only event lists may see a different event set on `/vendor/analytics` (expected improvement for team accounts).

### Files changed (TASK 9)

| File |
|------|
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_analytics/myeventlane_analytics.module` |
| `web/modules/custom/myeventlane_analytics/myeventlane_analytics.services.yml` |
| `web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php` |
| `web/modules/custom/myeventlane_analytics/src/Service/VendorAnalyticsViewModelBuilder.php` |
| `web/modules/custom/myeventlane_analytics/templates/analytics-dashboard.html.twig` |
| `web/modules/custom/myeventlane_analytics/templates/analytics-event.html.twig` |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorEventAnalyticsController.php` |
| `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` |
| `web/themes/custom/myeventlane_vendor_theme/templates/event/analytics.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/best-event-card.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/event-table.html.twig` |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_analytics.scss` |

---

## TASK 10 implementation notes

**Date:** 2026-05-05  
**Scope:** Vendor profile/settings hub (`/vendor/settings`), messaging brand subsection (`/vendor/dashboard/messaging/brand`), Pro branding alignment (`/vendor/settings/branding`), canonical vendor entity fields, module + theme styling — **no** Stripe API/checkout/Event Studio/analytics/ticket changes.

### Routes inspected

- `myeventlane_vendor_settings.routing.yml` — `myeventlane_vendor.console.settings` → `\Drupal\myeventlane_vendor_settings\Form\VendorSettingsForm`
- `myeventlane_vendor.routing.yml` — `myeventlane_vendor.console.messaging_brand` → `VendorDashboardMessagingBrandController::brand`
- `myeventlane_pro.routing.yml` — `myeventlane_pro.branding` → `ProBrandingController::settings`

### Route posture decision

| Path | Decision |
| ---- | -------- |
| `/vendor/settings` | **Canonical organiser profile hub** — single `VendorSettingsForm`; sections for public profile, visual assets, contact & email identity, public visibility, venues placeholder, business & payments (store mirror + stored Stripe flags), team, preferences. |
| `/vendor/dashboard/messaging/brand` | **Styled subsection retained (option C)** — same save path as hub for overlapping assets: continues to embed `Drupal\myeventlane_messaging\Form\VendorBrandingForm`, which writes **only** canonical vendor fields (`field_msg_logo` / `field_vendor_logo` / `field_logo_image`, `field_banner_image`, accent colours). **No redirect** in TASK 10. Cross-links from profile header + messaging intro link back to profile. |
| `/vendor/settings/branding` | **Pro-only retained** — still embeds the same `VendorBrandingForm`; intro updated with links to profile + messaging brand for wayfinding. |

### Canonical storage / source of truth

- **Vendor entity** (`myeventlane_vendor`): profile name, `field_summary`, `field_tagline`, `field_description`, `field_vendor_bio`, `field_website`, `field_social_links`, visual assets (logo priority `field_msg_logo` → `field_vendor_logo` → `field_logo_image`, `field_banner_image`, `field_accent_colour` / `field_msg_accent_color`), contact (`field_email`, `field_phone`, `field_address`), messaging identity (`field_msg_from_name`, `field_msg_from_email`, `field_msg_reply_to`, `field_msg_footer`), public visibility toggles, preferences, team (`field_vendor_users`), business (`field_business_name`, `field_abn`).
- **Commerce store** (linked via `field_vendor_store`): mirrored **only** through existing `VendorStoreSubscriber` / `ensureStoreForVendor` path on save — **unchanged** mirror semantics.
- **`VendorBrandResolver`**: unchanged field order (accent: `field_accent_colour` then `field_msg_accent_color`; logo: `field_msg_logo` → `field_vendor_logo` → `field_logo_image`). Messaging form logo order aligned with resolver.

### Forms/controllers changed

| Area | Change |
| ---- | ------ |
| `VendorSettingsForm` | Section layout + `mel-vendor-settings-v2` hooks; website + social links moved under **Public profile**; **Contact** adds messaging sender fields; **Visual assets** adds accent colour + messaging-aligned logo field priority; **Business & payments** copy + stored `field_stripe_status` line + Stripe CTAs unchanged routes; header quick links (Messaging brand, Pro branding when access allows); **URL validation** — full `http(s)://…` required with message *“Please enter the full URL, including https://”* (no silent scheme prepend); reduced noisy `notice` logs to `debug`. |
| `VendorBrandingForm` (messaging) | Injects `CurrentVendorResolverInterface` + `UserVendorMembershipQuery`; hidden `vendor_id` + membership check on validate (non-admin); removes **`field_description`** from this form (canonical long copy only on profile hub); removes `mel_debug` logging; `mel-vendor-brand-v2` + global vendor theme library; link to profile settings. |
| `VendorDashboardMessagingBrandController` | Removed debug request logging; dropped unused `RequestStack` DI. |
| `ProBrandingController` | Uses `CurrentVendorResolverInterface` instead of uid-only entity query; intro links to profile + messaging brand. |

### Duplicate / legacy brand logic retained

- **`Drupal\myeventlane_vendor\Form\VendorBrandingForm`** (onboarding): **unchanged** — used for `/vendor/onboard/branding` wizard only; not wired to console messaging route.
- **Messaging `VendorBrandingForm`**: retained as the **console** branding editor; overlaps logo/banner/colour with profile hub intentionally — **same entity fields**, not duplicated save services.

### Stripe / payment panel

- **Still no Stripe API** on settings render.
- Shows existing store booleans (`field_stripe_connected`, `field_stripe_charges_enabled`, `field_stripe_payouts_enabled`) where present.
- If `field_stripe_status` exists on the store, shows **stored** onboarding phase string (read-only).
- Connect / Manage links: `myeventlane_vendor.stripe_connect`, `myeventlane_vendor.stripe_manage` (unchanged).

### Team / preferences

- **Team**: existing `field_vendor_users` UI unchanged (non-AJAX add member).
- **Preferences**: existing notification fields unchanged.

### Styling

- `myeventlane_vendor_settings` — `css/mel-vendor-settings.scss` + `css/mel-vendor-settings.css` — v2 header/preview/pill/status helpers.
- `myeventlane_messaging` — `css/vendor-branding.css` — `.mel-vendor-brand-v2*` additions.
- `myeventlane_vendor_theme` — `src/scss/pages/_settings.scss` — shell width + title helper.

### Module dependency note

- **`myeventlane_messaging.info.yml`** does **not** declare `myeventlane_vendor` (would create a cycle: vendor already depends on messaging). Runtime: vendor module is enabled wherever messaging branding form is used in production.

### Verification results

Run locally after pull:

- `git status -sb`
- `php -l` on each changed PHP file listed below
- `npm run mel:build` and `npm run mel:lint`
- `ddev drush cr`
- `ddev drush route | grep -E "myeventlane_vendor.console.settings|myeventlane_vendor.console.messaging_brand|myeventlane_pro.branding|myeventlane_vendor.stripe_connect|myeventlane_vendor.stripe_manage"`  
- `ddev drush php:eval "\$form = \\Drupal::formBuilder()->getForm('Drupal\\\\myeventlane_vendor_settings\\\\Form\\\\VendorSettingsForm'); print is_array(\$form) ? 'settings form ok' : 'settings form failed';"`

### Browser smoke

- **Not run** in this agent session (no authenticated vendor browser).

### Residual risks for TASK 11

- **Access parity**: `VendorBrandingForm` now enforces `UserVendorMembershipQuery` for non-admins; confirm this matches `VendorConsoleAccess` expectations for every team edge case.
- **URL strictness**: vendors who relied on bare domains for website/social must now enter full URLs — intentional product change; communicate if support sees confusion.
- **Accent colour**: restricted `field_msg_accent_color` allowed-values still gate dual-write to that field when allowlist is non-empty.

### Files changed (TASK 10)

| File |
|------|
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_vendor_settings/src/Form/VendorSettingsForm.php` |
| `web/modules/custom/myeventlane_vendor_settings/css/mel-vendor-settings.scss` |
| `web/modules/custom/myeventlane_vendor_settings/css/mel-vendor-settings.css` |
| `web/modules/custom/myeventlane_messaging/src/Form/VendorBrandingForm.php` |
| `web/modules/custom/myeventlane_messaging/css/vendor-branding.css` |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardMessagingBrandController.php` |
| `web/modules/custom/myeventlane_pro/src/Controller/ProBrandingController.php` |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_settings.scss` |

---

## TASK 11 implementation notes

**Date:** 2026-05-05  
**Scope:** Access consistency for Vendor Console v2 — documentation + targeted PHP/services/routing only (no UI redesign, no ticket/RSVP/checkout business-logic changes).

### Access matrix

- Created [`docs/vendor-console-v2-access-matrix.md`](vendor-console-v2-access-matrix.md) with route-by-route current vs desired access, admin bypass notes, and decisions (keep / tighten / preserve stronger access / defer).

### Services/controllers inspected

- `VendorConsoleAccess`, `EventVendorAccessChecker`, `VendorConsoleBaseController`, `UserVendorMembershipQuery` / `CurrentVendorResolver` (referenced for parity model).
- Event Studio: `EventStudioController`, `EventStudioAutosaveController`, `myeventlane_event_studio.routing.yml`.
- Tickets: `EventTicketsAccess`.
- RSVP: `VendorEventAccess` (+ RSVP routing).
- Attendees: `VendorAttendeeController` (unchanged — already parity-aligned).
- Check-in: `CheckInController` (corrected team/vendor parity in controller).
- Analytics: `AnalyticsDashboardController::accessEvent`.
- Comms: `VendorCommsController::checkAccess`.
- Store resolution: `VendorOwnershipResolver::getStoreForUser`.
- Pro branding: `ProBrandingController::access`.
- Broken route: `OrganiserContextBlock`.

### Canonical access model decision

- **Single parity primitive:** `EventVendorAccessChecker::accountHasWorkspaceParityForEvent()` matches `VendorConsoleBaseController::assertEventOwnership()` membership semantics (event author or `field_event_vendor` → `field_vendor_users`).
- **Admin/staff:** Explicit bypasses only (`administer nodes`, `administer commerce_order` / `bypass node access` where already used); analytics event route also allows `administer nodes` alongside existing attendee-admin bypass.
- **Do not duplicate:** RSVP legacy access and ticket console fallback now reuse the checker instead of copying `field_vendor_users` loops.

### Routes tightened

- `myeventlane_event_studio.autosave`: added `VendorConsoleAccess` on the route; controller denies anonymous; existing nodes require `node.update` **and** workspace parity unless `administer nodes`; create-without-nid requires access to `myeventlane_event_studio.create`.
- Legacy RSVP vendor routes: `_custom_access` now invokes service `myeventlane_rsvp.vendor_event_access:access` (DI + shared checker).

### Routes preserved (stronger or unchanged) and why

- Event Studio edit routes: **`_entity_access: node.update` retained**; controller adds parity for non-admins so broad ACL alone cannot manage another organiser’s event.
- Advanced tickets: **`EventTicketsAccess`** retained (ticket permission path unchanged); vendor-console fallback branch delegates parity to `EventVendorAccessChecker`.
- Attendee export / permissions: **`VendorAttendeeController::access`** unchanged (permission model stronger than parity-only).
- Check-in routes: **permissions** (`myeventlane_checkin.access`, `.scan`, `.toggle`) unchanged; controller now matches workspace parity instead of owner-only.
- Pro analytics / financial permission gates: **unchanged** on routes and `VendorEventAnalyticsController` runtime checks.

### Admin/staff bypass decisions

- Autosave: `administer nodes` skips parity/update composite (Drupal admins).
- Check-in controller: `administer nodes` allows event-scoped UI (aligns with other console surfaces).
- Vendor comms: added `administer nodes` alongside existing commerce/bypass.
- Pro branding route: `administer nodes` allows settings page (form still loads vendor via resolver when possible).

### Team member access decisions

- **`VendorOwnershipResolver::getStoreForUser`:** uses `CurrentVendorResolverInterface` when present so **team members** resolve the linked commerce store (fixes dead `field_owner` query on vendor entity and aligns `/vendor/attendees` + comms store checks with owner/team model).
- **Check-in:** vendor team members with check-in permissions can operate check-in for their events (parity).

### Pro / check-in / ticket extra gates preserved

- Pro route requirement `_myeventlane_pro_access` and permission `use pro financial analytics` untouched.
- `ProBrandingController`: still requires active Pro; access now uses **resolved vendor** (owner or team via `CurrentVendorResolver`) instead of role-only `vendor`, plus `administer nodes` bypass.
- Check-in permissions unchanged on routes.
- Ticket management via `EventAccess::canManageEventTickets` unchanged as first branch in `EventTicketsAccess`.

### Broken create route cleanup

- **`OrganiserContextBlock`:** `myeventlane_vendor.console.create_event` replaced with **`myeventlane_event_studio.create`** (prevents invalid route generation).

### Verification results (automated)

- Run locally after pull: `git status -sb`; `php -l` on each changed PHP file; `npm run mel:build`; `npm run mel:lint`; `ddev drush cr`; `ddev drush route | grep -E "vendor.console|event_studio|myeventlane_tickets|myeventlane_rsvp|myeventlane_checkin|myeventlane_analytics|myeventlane_pro.branding|vendor_attendees"`; grep verification commands from TASK brief.

### Browser smoke

- **Not run** in this agent session (no authenticated organiser browser against a live site).

### Residual risks for TASK 12

- Workspace RSVP vs legacy RSVP still differ by **permission** (`manage own event rsvps` on legacy only); both deny cross-vendor via shared parity where applicable.
- `OrganiserContextBlock` create link assumes Event Studio module enabled (same as other `Url::fromRoute` calls — caught per link).
- Public theme `/node/add/event` footers remain **out of TASK 11** per brief.

### Files changed (TASK 11)

| File |
|------|
| `docs/vendor-console-v2-access-matrix.md` |
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.services.yml` |
| `web/modules/custom/myeventlane_checkout_flow/src/Service/VendorOwnershipResolver.php` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.info.yml` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.routing.yml` |
| `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.services.yml` |
| `web/modules/custom/myeventlane_rsvp/src/Access/VendorEventAccess.php` |
| `web/modules/custom/myeventlane_tickets/myeventlane_tickets.services.yml` |
| `web/modules/custom/myeventlane_tickets/src/Access/EventTicketsAccess.php` |
| `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml` |
| `web/modules/custom/myeventlane_event_studio/myeventlane_event_studio.services.yml` |
| `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioAutosaveController.php` |
| `web/modules/custom/myeventlane_event_studio/src/Controller/EventStudioController.php` |
| `web/modules/custom/myeventlane_checkin/myeventlane_checkin.info.yml` |
| `web/modules/custom/myeventlane_checkin/src/Controller/CheckInController.php` |
| `web/modules/custom/myeventlane_vendor_comms/myeventlane_vendor_comms.info.yml` |
| `web/modules/custom/myeventlane_vendor_comms/src/Controller/VendorCommsController.php` |
| `web/modules/custom/myeventlane_pro/src/Controller/ProBrandingController.php` |
| `web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php` |
| `web/modules/custom/myeventlane_core/src/Plugin/Block/OrganiserContextBlock.php` |

---

## TASK 12 implementation notes

**Date:** 2026-05-05  
**Scope:** Navigation visibility and canonical routes for vendor shell, account/public CTAs, footers, and organiser context — no dashboard/workspace/analytics builders, checkout, or route YAML changes.

### Nav cleanup audit

- Created [`docs/vendor-console-v2-nav-cleanup.md`](vendor-console-v2-nav-cleanup.md) with grep classifications and decisions before production edits.

### Account menu / dropdown

- **`myeventlane_vendor.links.menu.yml`:** Re-verified — Dashboard, My events, Create event (`myeventlane_event_studio.create`), Settings on `account` menu; core menu access applies.
- **`myeventlane_vendor_theme` header `user_menu`:** Inserts the same four console links **before** Profile when each `Url::fromRoute()->access()` passes; Notifications and Log out unchanged.
- **`quick_actions` “+ Create Event”:** Only when `myeventlane_event_studio.create` passes `_myeventlane_vendor_theme_named_route_accessible` (ordinary customers no longer see it on the vendor shell).

### OrganiserContextBlock

- **Gate:** Trusted console users (`VendorConsoleTrust::accountIsTrustedForVendorConsole`) or `administer nodes`; links still filtered with `Url::access()` (dashboard remains permission-gated on the route).

### Vendor shell primary nav

- **Verified** — `_myeventlane_vendor_theme_build_full_vendor_shell_nav_items()` order unchanged: Dashboard, Events, Analytics (if allowed), Attendees (if allowed), Profile; secondary items follow.

### Public / internal footers

- **`myeventlane_theme/templates/layout/footer-internal.html.twig`:** Uses `vendor_console_urls` from preprocess (access-checked URLs); removed hardcoded `/vendor/...` and `/node/add/event`.
- **`myeventlane_theme_preprocess_page`:** Sets `footer_context` via `FooterContextService` and `vendor_console_urls` when `is_vendor` (role flag unchanged).

### `/node/add/event`

- **Vendor-facing internal footer:** Removed (replaced by access-checked Event Studio create URL in `vendor_console_urls`).
- **Docs / admin setup guides:** Unchanged (classified in audit).

### `/vendor/events/add`

- **No new links;** legacy route + launch rate-limit list retained.

### `/vendor/studio`

- **Unchanged** — internal/API only; active-section mapping retained.

### Launch protection

- **`LaunchRequestProtectionSubscriber`:** Removed duplicate `myeventlane_event_studio.create` entry in the rate-limit array; kept `events_add` for legacy bookmarks.

### Public marketing create CTAs

- **Anonymous / mixed audiences:** Templates and preprocess use **`myeventlane_vendor.create_event_gateway`** (`/create-event`) instead of direct `myeventlane_event_studio.create`.
- **Logged-in vendor shell:** Continues to use access-checked **`myeventlane_event_studio.create`** for primary/header create actions.

### Files changed (TASK 12)

| File |
|------|
| `docs/vendor-console-v2-nav-cleanup.md` |
| `docs/vendor-console-v2-route-map.md` |
| `web/modules/custom/myeventlane_core/src/Plugin/Block/OrganiserContextBlock.php` |
| `web/modules/custom/myeventlane_launch/src/EventSubscriber/LaunchRequestProtectionSubscriber.php` |
| `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` |
| `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` |
| `web/themes/custom/myeventlane_theme/templates/layout/footer-internal.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/page--front.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/region--header.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/includes/mel-final-cta-default.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/includes/footer.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/includes/mel-host-cta-default.html.twig` |
| `web/themes/custom/myeventlane_theme/templates/vendor/event-navigator.html.twig` |
| `web/themes/custom/myeventlane_theme/components/hero/hero.twig` |
| `web/themes/custom/myeventlane_radix/templates/includes/header.html.twig` |

### Verification results

Run locally: `git status -sb`; `php -l` on changed PHP; `npm run mel:build`; `npm run mel:lint`; `ddev drush cr`; grep verification command from TASK brief; `ddev drush route \| grep -E "myeventlane_event_studio.create|myeventlane_vendor.console.dashboard|..."`.

### Browser smoke

- **Not run** in this agent session.

### Residual risks for TASK 13

- **`FooterContextService::is_vendor`** remains role-based; trust-only team accounts may still omit vendor footer accordion until a later footer-context consolidation.
- **Organiser strip vs account menu:** Trusted users suppress inline organiser block when the block builds non-empty output — verify UX with product if header feels empty for edge roles.

---

## TASK 13 implementation notes

**Date:** 2026-05-05  

### Documentation

- **Hardening audit:** [`docs/vendor-console-v2-task13-hardening-audit.md`](vendor-console-v2-task13-hardening-audit.md)

### Pages / components inspected

- Vendor dashboard, events index, event workspace, Event Studio nav CSS; SCSS pages `_mel-dashboard.scss`, `_vendor-events.scss`, `_workspace.scss`; vendor alert component reuse for workspace presentation alerts.

### Styling changes

- Event index + dashboard event cards: **presentation issue chips** (dot + label + severity modifiers) for ticket mapping and paid price display gaps.
- Workspace: **alert strip** reusing `.mel-vendor-alert` patterns; secondary action buttons with 44px min height; **focus-visible** on workspace primary/secondary/tertiary buttons and Event Studio nav links.

### Accessibility

- Issue chips grouped with `role="group"` and `aria-label`; workspace alerts in `role="region"`; severity not conveyed by colour alone (dot + text).

### Log-noise cleanup

- **`BOOST CANDIDATE`:** TASK 13 moved logs to **debug**; **TASK 15** gates them behind **`mel.debug_boost_candidates`** only (`mel.dev_mode` may still affect UI).
- **`FINAL RESPONSE`:** `ResponseDebugSubscriber` only logs when **`mel.debug_http_response_trace`** state is truthy; uses injected services and **debug** severity (see audit doc for `drush state:set`).

### Ticket mapping / paid price warnings

- **Purchase enforcement unchanged** (`TicketAvailabilityService` error logging retained).
- **Vendor UI:** `VendorEventPresentationAlertsBuilder` surfaces mapping gaps using **`TicketAvailabilityService::resolveTierForVariation()`** (same public mapping as checkout). Paid display gaps use **`TicketTypeManager::loadPublishedPaidTicketPrices()`** + **`BookingFlowResolver::getBookingMode()`** — intentionally **not** calling `getDisplayPricing()` on vendor pages to avoid duplicate watchdog warnings.

### Legacy link regression

- Re-grep performed (see verification); remaining hits are route definitions, internal JS endpoints, tests, docs, install hooks — **no new vendor-facing CTAs** to forbidden paths.

### Verification results

- Recorded in agent session: `git status -sb`; `php -l` on changed PHP; `composer validate`; `npm run mel:lint`; `npm run mel:build`; `ddev drush cr` (when DDEV available); final grep for legacy/debug strings.

### Browser smoke

- **Not run** in this agent session (manual check recommended at ~390px + keyboard).

### Residual risks for TASK 14

- Bulk **ticket-type ↔ variation repair** tooling and edge cases where ticket rows exist only outside `field_ticket_types` parity with checkout mapping.
- Optional consolidation of **paid price** vendor messaging with public booking UI when product wants identical copy.

---

## TASK 14 implementation notes

**Date:** 2026-05-05  

### Documentation

- **Ticket reconciliation audit:** [`docs/vendor-console-v2-task14-ticket-reconciliation.md`](vendor-console-v2-task14-ticket-reconciliation.md)

### Canonical mapping model

- Event **`field_product_target`** → Commerce ticket product; **`field_ticket_types`** lists `mel_ticket_type` entities used by **`TicketAvailabilityService::resolveTierForVariation()`** (inverse-only rows are invisible to checkout until merged onto the field).
- **`mel_ticket_type.commerce_variation`** → purchasable **`ticket_variation`**.
- **`TicketTierLifecycleService::reconcileEventTicketReferences()`** merges inverse **`mel_ticket_type.event`** references onto **`field_ticket_types`**; its tail calls **`syncPaidTiers()`** (see TASK 14 doc for Commerce sync side effects).

### Services inspected

- `TicketAvailabilityService`, `TicketTypeManager`, `BookingFlowResolver`, `TicketTierLifecycleService`, `VendorEventPresentationAlertsBuilder` (TASK 13).

### Diagnostic service + Drush

- **`EventTicketReconciliationService`** (`myeventlane_vendor.event_ticket_reconciliation`), logger channel **`myeventlane_ticket_reconciliation`**.
- Commands: **`mel:tickets:audit`**, **`mel:tickets:repair`** (`web/modules/custom/myeventlane_vendor/drush.services.yml`).

### Repair rules

- Default **dry-run**; **`--apply`** persists **`reconcileEventTicketReferences()`** only when audit shows reconcile-eligible issues and **no** `ambiguous_variation_mapping`.
- Does **not** bypass **`TicketAvailabilityService`** rules, fake prices, or mutate orders/line items.

### Non-repairable cases (examples)

- Orphan **`ticket_variation`** rows with no matching tier for the event, ambiguous duplicate **`commerce_variation`** mappings, missing product for paid-capable events, paid display gaps without published priced tiers.

### Paid price reconciliation behaviour

- Audit emits **`paid_without_prices`** under **`BookingFlowResolver::MODE_PAID`** when **`loadPublishedPaidTicketPrices()`** is empty; explanatory copy only (no automatic price writes).

### Vendor UI alert changes

- Workspace alert copy/action labels aligned with “Review ticket setup”, “Open advanced ticket manager”, and support fallback without embedding Drush in vendor-facing strings.

### Verification results

- Run in agent session: `git status -sb`; `php -l` on changed PHP; `composer validate`; `npm run mel:lint`; `npm run mel:build`; `ddev drush cr`; `ddev drush php:eval` service load; `ddev drush mel:tickets:audit`; final grep for command/service identifiers (record stdout in CI notes if DDEV unavailable).

### Browser smoke

- **Not run** in this agent session (manual check recommended on `/vendor/events` and a workspace with known mapping drift).

### Residual risks for TASK 15

- **CLI repair inherits `syncPaidTiers()`** side effects after reconcile (variation unpublish rules inside `TicketTypeManager::syncTicketTypesToVariations()`).
- Events needing **new** ticket types or variation rewiring still require Event Studio / advanced ticket manager workflows—not inferred by audit.

---

## TASK 15 implementation notes

**Date:** 2026-05-05  

### Documentation

- **TASK 15 audit:** [`docs/vendor-console-v2-task15-ticket-product-and-debug.md`](vendor-console-v2-task15-ticket-product-and-debug.md)

### Product creation / sync path (verified)

- **`TicketTypeManager::syncTicketTypesToVariations()`** (paid/both) calls private **`getOrCreateTicketProduct()`**, which creates a **`ticket`** Commerce product with **`field_event`**, saves **`field_product_target`** on the event, then mirrors published paid tiers to variations (no invented prices).
- **`EventProductManager::syncProducts()`** delegates paid/both paths to that sync but carries additional **intent** guards (publish/sync, locks); TASK 15 Drush repair calls **`TicketTypeManager`** directly for explicit CLI repair only.

### Missing product detection

- Audit issue codes: **`missing_ticket_product`** (empty/broken/invalid bundle link), **`ambiguous_ticket_product_relink`** (multiple ticket products with **`field_event`** = event).
- Non-event bundle audits use **`invalid_bundle`** (replaces the old **`missing_product`** label for that case).

### Repair strategy (`mel:tickets:repair`)

- Dry-run lists **product** steps (unique **`field_event`** link vs **`syncTicketTypesToVariations()`**) and/or **reconcile** when eligible.
- **`--apply`**: runs **product repair first** (when repairable), reloads the node, re-audits, then **`reconcileEventTicketReferences()`** when still eligible and abort rules clear.
- Abort reasons extended with **`ambiguous_ticket_product_relink`** (blocks all repair).

### Event Studio / ticket manager logging

- **`EventTicketManagerForm`**: expected missing product no longer logs **`myeventlane_vendor`** errors; form/messenger copy directs organisers to finish ticket setup.

### Vendor alerts

- **`VendorEventPresentationAlertsBuilder`**: **`field_event_type`** `paid`/`both` + invalid/missing product reference → workspace alert + chip (**Ticket product missing**) with CTA toward Event Studio edit when accessible.

### BOOST CANDIDATE debug gating

- **`VendorDashboardController`**: **`BOOST CANDIDATE`** **`mel_debug`** logs only when **`state mel.debug_boost_candidates`** is set; **`mel.dev_mode`** alone does not log.

### Verification results

- Agent session: `git status -sb`; `php -l` on touched PHP; `composer validate`; `npm run mel:lint`; `npm run mel:build`; `ddev drush cr`; `ddev drush php:eval` service load; `ddev drush mel:tickets:audit --event=1562`; `ddev drush mel:tickets:repair --event=1562` (dry-run); grep for identifiers below. **`mel:tickets:repair --apply`** **not** run (requires explicit approval).

### Browser smoke

- **Not run** in this agent session.

### Residual risks for TASK 16

- **`syncTicketTypesToVariations()`** during product repair can still **unpublish** orphan variations on the event product when tiers exist; operators must accept Commerce side effects before **`--apply`**.
- Workspace **`eventType`** derived from **`has_product`** + RSVP can still read **`unknown`** when **`field_event_type`** is paid/both but product is missing — alerts now key off **`field_event_type`** for the missing-product chip.

---

## TASK 16 implementation notes

**Date:** 2026-05-05  

### Documentation

- **TASK 16 audit:** [`docs/vendor-console-v2-task16-orphan-variation-cleanup.md`](vendor-console-v2-task16-orphan-variation-cleanup.md)

### Proof TASK 16 commands were previously missing

Pre-implementation verification (recorded in the TASK 16 audit doc):

- `grep` for `mel:tickets:orphans`, `mel:tickets:cleanup-orphans`, `inspectOrphanVariations`, and `cleanupOrphanVariations` under `web/modules/custom/myeventlane_vendor` and `docs` returned **no matches**.
- `ddev drush list | grep mel:tickets` listed only **`mel:tickets:audit`** and **`mel:tickets:repair`**.

### Orphan variation definition

- Implemented to match **`orphan_variation_not_repairable`** detection in **`EventTicketReconciliationService::auditEvent()`**: published **`ticket_variation`** on the event **`field_product_target`** ticket product, **`resolveTierForVariation()`** yields no tier, and **no** inverse **`mel_ticket_type.event`** row (non-archived) references the variation via **`commerce_variation`**.

### Order usage detection

- **`commerce_order_item`** entity query on **`purchased_entity`** = variation ID; parent **`commerce_order`** **`state`** id.
- **Protected** states mirror **`VendorEventOrdersController::INCLUDED_STATES`**: `completed`, `partially_refunded`, `refunded`, `placed`, `fulfilled`, `fulfillment`.
- **Draft/cart:** `draft`.
- **Other** states → inspect **`manual_review`** / cleanup **skip** (conservative).
- Output and logs: **counts only** — no customer or order narrative fields.

### Cleanup rules

- Default **dry-run**; **`cleanupOrphanVariations(..., ['apply' => TRUE])`** required to unpublish.
- **Unpublish only** (`setPublished(FALSE)`); no deletes; no price/SKU changes; no ticket types created.
- Draft rows: unpublish allowed when **`allow_draft_usage`** is true (default); **`--disallow-draft-usage`** on Drush refuses when draft items exist.
- Optional **`then_reconcile`** (default true with **`--apply`**): reload event, **`auditEvent()`**; if **`repairAbortReason()`** is clear, **`repairEvent(..., apply true)`**; remaining abort/skip reasons surfaced as **`remaining_blockers`**.

### Drush commands created

- **`mel:tickets:orphans`** — **`--event`** (required), **`--format=table|json`**.
- **`mel:tickets:cleanup-orphans`** — **`--event`** (required); **`--variation`** (comma list or repeat); **`--apply`**; **`--no-reconcile`**; **`--disallow-draft-usage`**; **`--format`**.

### Repair command guidance update

- **`mel:tickets:repair`** (table output): when **`skipped_reason`** is **`orphan_variation_not_repairable`**, prints commands to run **`mel:tickets:orphans`** then dry-run **`mel:tickets:cleanup-orphans`**.

### Service wiring

- **`EventTicketReconciliationService`** adds **`@commerce_price.currency_formatter`** for variation price labels in inspect output (`myeventlane_vendor.services.yml`).

### Verification results

- Post-implementation (agent session): `grep` for TASK 16 symbols; `php -l` on changed PHP; `composer validate`; `ddev composer dump-autoload`; `ddev drush cr`; `ddev drush list | grep mel:tickets`; `ddev drush mel:tickets:orphans` / `cleanup-orphans` on sample event IDs where data exists; `npm run mel:lint`; `npm run mel:build`; **`mel:tickets:cleanup-orphans --apply`** **not** run without explicit approval.

### Browser smoke

- **Not run** (Drush-only TASK).

### Residual risks for TASK 17

- Operators may still strand **draft** carts when unpublishing with default draft allowance — counts are surfaced; use **`--disallow-draft-usage`** when carts must be preserved until checkout clears.
- **Unknown** workflow states on legacy orders could block cleanup until classified — conservative **`manual_review`** path.
- **`mel:tickets:repair`** after cleanup may still hit **`paid_without_prices`**, missing product, or ambiguous mapping — **`remaining_blockers`** + audit tail document follow-up.

---

## TASK 16 hotfix — cleanup success verification

**Date:** 2026-05-05  

### Observed bug

**`mel:tickets:cleanup-orphans --apply`** could report **`unpublished`** for an orphan variation while **`mel:tickets:orphans`** and **`mel:tickets:audit`** still showed it **published** after **`drush cr`**, proving the CLI lied about persistence (not the reconcile tail when **`--no-reconcile`** was used).

### Root cause

1. **Stale variation objects** from **`Product::getVariations()`** in inspect/audit — status in memory did not match storage after unpublish elsewhere in the same or a prior request.  
2. **Unpublish path** needed the same reliable pattern as manual **`php:eval`**: explicit **`status`** + **`setPublished(FALSE)`**, **save**, **storage cache reset**, **reload**, and **success only if reload is unpublished**.

### Files changed

- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — fresh product/variation loads; hardened **`cleanupOrphanVariations()`** unpublish + verification; cache reset before post-repair audit; **`remaining_blockers`** + warning log when reload still published.  
- **`docs/vendor-console-v2-task16-orphan-variation-cleanup.md`** — §11 hotfix note.  
- **`docs/vendor-console-v2-route-map.md`** — this subsection.

### Verification notes (variation 4173 / event 1592)

After deploy, run (**do not** substitute ellipsis placeholders — use these full commands):

```bash
ddev drush mel:tickets:cleanup-orphans --event=1592 --apply --no-reconcile

ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("commerce_product_variation"); foreach ([4173,4174,4175] as $id) { $v=$s->load($id); if (!$v) { print "$id missing\n"; continue; } print $id." ".$v->label()." ".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL; }'

ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("commerce_product_variation"); $s->resetCache([4173]); $v=$s->load(4173); if (!$v) { print "4173 missing\n"; } else { print $v->id()." ".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL; }'

ddev drush mel:tickets:orphans --event=1592
ddev drush mel:tickets:audit --event=1592
```

**Expected if unpublish persisted:** **4173** absent from orphans; no **`orphan_variation_not_repairable`** for **4173**; audit may still show **`variation_without_ticket_type`** for inverse tiers **102**, **103**.

**If reload stays published:** cleanup must report **`skipped`** and **`remaining_blockers`**, not **`unpublished`**.

---

## TASK 16 hotfix — repair lifecycle left orphan variation published

**Date:** 2026-05-05  

### Observed sequence

After **`mel:tickets:repair --apply`** (reconcile + **`syncPaidTiers`**), logs showed **Unpublished orphaned variation 4173** and new variations **4174**/**4175**, but **`mel:tickets:audit`** still listed **`orphan_variation_not_repairable`** for **4173** — contradictory and unsafe.

### Root cause

1. **`TicketTypeManager::removeOrphanedVariations()`** used **`setPublished(FALSE)`** without persisting **`status`** reliably and **without reload verification**, so **`myeventlane_event`** could log success incorrectly.  
2. Post-repair **`auditEvent()`** needed **full entity cache resets** (including **`mel_ticket_type`**) and explicit **`success`** / **`remaining_orphan_variation_ids`** in the repair result when orphan audit codes remained.

### Files changed

- **`web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`** — verified orphan unpublish; **`syncTicketTypesToVariations()`** returns **FALSE** if verify fails; fresh **`mel_ticket_type`** load before **`commerce_variation`** branch.  
- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — **`finalizeRepairAudit()`**, **`success`** / **`remaining_orphan_variation_ids`** on repair apply; **`mel_ticket_type`** in **`resetReconciliationEntityCaches()`**.  
- **`web/modules/custom/myeventlane_vendor/src/Commands/EventTicketReconciliationCommands.php`** — warning when **`applied`** && **`success === false`**.  
- **`docs/vendor-console-v2-task16-orphan-variation-cleanup.md`** — §12 repair lifecycle hotfix.  
- **`docs/vendor-console-v2-route-map.md`** — this subsection.

### Verification (event 1592 / variations 4173–4175)

```bash
ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("commerce_product_variation"); foreach ([4173,4174,4175] as $id) { $v=$s->load($id); if (!$v) { print "$id missing\n"; continue; } print $id." ".$v->label()." ".($v->isPublished() ? "published" : "unpublished")." raw_status=".$v->get("status")->value.PHP_EOL; }'

ddev drush mel:tickets:orphans --event=1592
ddev drush mel:tickets:audit --event=1592
ddev drush mel:tickets:repair --event=1592
```

Use **`repair --apply`** only when dry-run shows no orphan blocker and operators approve.

**Healthy outcome:** **4173** unpublished in storage; absent from orphan inspect; no **`orphan_variation_not_repairable`** for **4173**; **`mel:tickets:repair`** reports **`Repair success (no orphan variation blockers): yes`** after apply when lifecycle unpublish verified.

If **4173** stays published, run **`mel:tickets:cleanup-orphans --event=1592 --apply --no-reconcile`** then re-audit; avoid **`repair --apply`** until dry-run shows no orphan blocker.

---

## TASK 17 implementation notes

**Date:** 2026-05-05  

### Audit doc

- [`docs/vendor-console-v2-task17-product-variation-reference-sync.md`](vendor-console-v2-task17-product-variation-reference-sync.md)

### Root cause

- **`TicketTypeManager::syncTicketTypeToVariation()`** updated existing **`commerce_variation`** targets without appending the variation ID to **`commerce_product.variations`** (new-variation path did attach). **`removeOrphanedVariations()`** unpublishes SKUs but did not reconcile the product entity reference list, so checkout could still surface stale IDs (e.g. event **1592**: product **97** listing **4173** while tiers mapped **4174**/**4175**).

### Files changed

- **`web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`** — **`ensureVariationReferencedOnProduct()`** on variation updates; **`rebuildProductVariationReferences()`** + public **`syncProductVariationReferencesForEvent()`**; **`syncTicketTypesToVariations()`** always rebuilds product refs after orphan handling (still returns **FALSE** when orphan unpublish verification fails); **`normalizeProductVariationIds()`** runs on a freshly loaded product.
- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — audit issue **`product_missing_mapped_variation`**; **`sync_product_variation_references`** repair intent; apply-path **`mel:tickets:repair`** calls **`syncProductVariationReferencesForEvent()`** when abort-safe; **`finalizeRepairAudit()`** **`success`** also requires no **`product_missing_mapped_variation`** issues.
- **`web/modules/custom/myeventlane_vendor/src/Commands/EventTicketReconciliationCommands.php`** — repair note / blocker wording for TASK 17.
- **`docs/vendor-console-v2-task17-product-variation-reference-sync.md`** — TASK 17 audit (this task).
- **`docs/vendor-console-v2-route-map.md`** — this subsection.

### Issue code added

- **`product_missing_mapped_variation`** (severity **error**) — tier on **`field_ticket_types`** maps to a variation not listed on the event ticket product, or maps to a variation on the wrong product (**repairable: false**).

### Repair behaviour

- Dry-run lists **Would sync product variation references to include mapped ticket variations.** when **`sync_product_variation_references`** is repairable on the pre-repair audit.
- **`--apply`** runs **`TicketTypeManager::syncProductVariationReferencesForEvent()`** after product/reconcile steps when the fresh audit still demands it and **`repairAbortReason()`** is **null** (no **`orphan_variation_not_repairable`**, etc.).
- **`mel:tickets:cleanup-orphans`** remains the explicit unpublish path for orphans.

### Verification result for event 1592

Run after deploy (environment-dependent): **`mel:tickets:audit --event=1592`**, **`mel:tickets:repair --event=1592`**, then **`--apply`** only when dry-run is safe; confirm product **97** **`variations`** include mapped IDs and **`product_missing_mapped_variation`** is absent.

**Agent session (local DDEV):** Event **1592** ticket types **102**/**103** referenced **`commerce_variation`** IDs **4174**/**4175**, but those variation entities were **missing from storage** (`entity` NULL on the tier field), so **`product_missing_mapped_variation`** did not appear until IDs resolve to real `ticket_variation` rows. The live product listed **4173**/**4176** with **`orphan_variation_not_repairable`** blockers; **`mel:tickets:cleanup-orphans`** dry-run correctly targeted **4173**/**4176**. **`mel:tickets:repair`** stayed skipped until orphan abort clears — matching TASK 14/16 rules. After operators restore tier→variation integrity and unpublish orphans, **`repair --apply`** should run the TASK 17 reference rebuild and clear **`product_missing_mapped_variation`** when mappings load.

### Residual risks for TASK 18

- Hybrid events may rely on variations not represented on **`field_ticket_types`**; rebuild keeps IDs with **any** order-item usage but may drop unreferenced non-tier variations — validate on real **both**/**RSVP** mixes before bulk repair.
- **`syncTicketTypesToVariations()`** can still return **FALSE** when orphan unpublish verification fails; operators must treat **`success`** / **`audit_after`** holistically (orphans vs product reference list).

---

## TASK 18 implementation notes

**Date:** 2026-05-05  

### Audit doc

- [`docs/vendor-console-v2-task18-variation-unpublish-persistence.md`](vendor-console-v2-task18-variation-unpublish-persistence.md)

### Root cause

- Commerce **`commerce_product_variation`** publishes via a **boolean** **`status`** base field (**`EntityPublishedTrait`**). Code paths that used **`$variation->set('status', 0)`** did **not** reliably persist unpublish; **`setPublished(FALSE)`** is **not** a valid Commerce/Core API (**parameterless `setPublished()`** publishes). Operators must use **`EntityPublishedInterface::setUnpublished()`** (or **`set('status', FALSE)`**).

### Files changed

- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — **`applyTicketVariationUnpublish()`**; cleanup apply uses supported unpublish APIs; richer **`diagnostic`** payloads on persistence failure; optional **`removeOrphanVariationReferenceFromEventTicketProduct()`** fallback when unpublish still fails and **`total_order_items`** is **0** (**`removed_product_reference`** action).
- **`web/modules/custom/myeventlane_vendor/src/Commands/EventTicketReconciliationCommands.php`** — prints **`diagnostic`** lines for failed unpublish / fallback rows (CLI-only detail).
- **`web/modules/custom/myeventlane_event/src/Service/TicketTypeManager.php`** — **`removeOrphanedVariations()`** uses **`setUnpublished()`** / boolean **`status`** (same boolean bug as TASK 16 hotfix).
- **`docs/vendor-console-v2-task18-variation-unpublish-persistence.md`** — TASK 18 audit + verification record.
- **`docs/vendor-console-v2-route-map.md`** — this subsection.

### Operator commands

- Unpublish orphans (explicit writes): **`drush mel:tickets:cleanup-orphans --event={nid} --apply`** (optional **`--no-reconcile`**).
- Inspect: **`drush mel:tickets:orphans`**, **`drush mel:tickets:audit`**.

### Residual risks for TASK 19

- **Fallback detach** leaves a **published** variation entity in storage that is **not** on the ticket product — intentional for zero-order orphans when unpublish cannot persist; **`repair`** / TASK 17 reference sync should restore a coherent **`variations`** list once abort reasons clear.
- If **both** unpublish and detach fail, **`diagnostic.suspected_next_action`** points operators at custom **`commerce_product_variation`** save-path interference (investigate before bulk cleanup).

---

## TASK 19 implementation notes

**Date:** 2026-05-05  

### Audit doc

- [`docs/vendor-console-v2-task19-ticket-save-flow.md`](vendor-console-v2-task19-ticket-save-flow.md)

### Root cause

- **`EventTicketManagerForm`** persisted **Commerce variations only** and never created or attached **`mel_ticket_type`** entities or **`field_ticket_types`**, and never called **`TicketTierLifecycleService::syncPaidTiers()`** / **`TicketTypeManager::syncTicketTypesToVariations()`**.
- **`EventStudioForm`** embeds the ticket manager but the **main Save** submit handler did **not** run the nested form’s submit pipeline; ticket rows were never passed through a canonical persistence path on full Studio save.
- **`EventStudioSaveService::applyTicketPayload()`** does not model ticket tier rows (only product target and related booking fields).

### Canonical save path decision

- **`TicketTierLifecycleService::persistTicketManagerRows()`** — single entry point used by **Advanced Ticket Manager** and **Event Studio** after full save. It writes Commerce variations, **ensures paid `mel_ticket_type` rows** (create or update by `commerce_variation` + `event`), rebuilds **`field_ticket_types`** order (preserving non–ticket-product paid tiers on **`both`** events), archives tiers when variations are removed, then **`syncPaidTiers()`**.

### Files changed

- **`docs/vendor-console-v2-task19-ticket-save-flow.md`** — TASK 19 audit (problem, model, root cause, plan, verification).
- **`docs/vendor-console-v2-route-map.md`** — this subsection.
- **`web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`** — **`persistTicketManagerRows()`** + private helpers (variation + tier projection).
- **`web/modules/custom/myeventlane_vendor/src/Form/EventTicketManagerForm.php`** — delegates submit to **`persistTicketManagerRows()`**; injects **`TicketTierLifecycleService`**.
- **`web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`** — validates paid/both saved events require ticket rows (or existing paid tiers on **`field_ticket_types`**); after **`EventStudioSaveService::save()`**, runs **`persistTicketManagerRows()`** on embedded ticket POST values.
- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — granular paid-display audit codes (**`paid_without_ticket_types`**, **`paid_ticket_types_unpublished`**, **`paid_ticket_types_without_prices`**) **plus** legacy **`paid_without_prices`** for backwards compatibility.

### Form validation changes

- **Event Studio:** paid/both events with a saved node id must have at least one active ticket row (name/price/best-value rules aligned with the manager) **or** existing paid tiers already on **`field_ticket_types`**; best-value rule unchanged (at most one when multiple active rows). New events without a nid are still allowed a first save so the ticket UI can appear.

### Audit / repair changes

- **Audit:** clearer codes when **`MODE_PAID`** and **`loadPublishedPaidTicketPrices()`** is empty; **`paid_without_prices`** retained as umbrella summary.
- **Repair:** unchanged contract — events with **no** tiers (e.g. **1094**) remain non-repairable without editorial ticket creation.

### Verification (local DDEV)

- **`php -l`** on changed PHP — OK.
- **`composer validate`** — OK.
- **`ddev drush cr`** — OK.
- **`npm run mel:lint`** / **`npm run mel:build`** — OK.
- **`ddev drush mel:tickets:audit --event=1094`** — shows **`paid_without_ticket_types`** + **`paid_without_prices`** (still broken data until tickets are saved through the fixed UI).
- **`ddev drush mel:tickets:audit --event=1378`** — same pattern in this environment (booking mode resolved as paid; operator data may differ).

### Browser smoke

- Not run in this session (no interactive browser). Recommended: Studio Save with one paid tier → re-audit → public ticket matrix; Advanced manager Save parity check.

### Residual risks for TASK 20

- **Hybrid `both`:** merging **`field_ticket_types`** preserves non–ticket-product paid tiers; ordering vs RSVP tiers should be validated on real **`both`** events.
- **Autosave / partial POST:** **`EventStudioAutosaveController`** paths were not changed; if autosave posts omit the tickets subtree, behaviour should be verified separately.
- **`field_product_target` vs `both`:** broader Event Studio product-target rules for **`both`** were not part of this task; stale product on RSVP-only events depends on saved **`field_event_type`** and existing **`EventStudioSaveService`** branches.

## TASK 19 hotfix — inactive inverse ticket types ignored

### Event 1094 evidence (after Save and sync tickets)

- **`field_ticket_types`:** `[{"target_id":"111"}]` — canonical sellable tier **General Admission** only.
- **Inverse `mel_ticket_type.event`:** IDs **109**, **110**, **111** (legacy **Full Price** / **Early Bird** remain linked to the event but **unpublished**; **111** published on field).

### Symptom

- **`mel:tickets:audit`** reported **`variation_without_ticket_type`**: “field_ticket_types is missing inverse event-linked ticket types: **109, 110**”, **`repairable: yes`**, action **`reconcile_event_ticket_references`** — wrong for checkout mapping because **109** and **110** are **inactive/unpublished** and must not be merged back onto **`field_ticket_types`**.

### Root cause

- **`EventTicketReconciliationService::auditEvent()`** compared **all** non-archived inverse ticket IDs to **`field_ticket_types`**, without requiring **`isPublished()`**.
- **`TicketTierLifecycleService::reconcileEventTicketReferences()`** appended **every** inverse-linked tier onto the field, including unpublished rows.

### Fix

1. **Audit:** `variation_without_ticket_type` (inverse-vs-field gap) uses **published** inverse IDs only; optional **`inactive_inverse_ticket_types_ignored`** (**info**, not repairable) lists unpublished inverse IDs missing from the field.
2. **Variation branch:** repairable **`variation_without_ticket_type`** only when the single matching tier is **published** and missing from the field.
3. **Repair:** **`reconcileEventTicketReferences()`** keeps field order for IDs still valid on inverse rows (any publish state), but **only appends published** inverse tiers to **`field_ticket_types`**.

### Files changed

- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — **`inversePublishedTicketIdsForEvent()`** / **`inverseTicketIdsForEventFiltered()`**; audit + optional info issue; published-only variation branch.
- **`web/modules/custom/myeventlane_event/src/Service/TicketTierLifecycleService.php`** — **`reconcileEventTicketReferences()`** merge uses **`$inversePublishedIds`** for append-only.
- **`docs/vendor-console-v2-route-map.md`** — this subsection.

### Verification (local DDEV)

- **`php -l`** on **`EventTicketReconciliationService.php`**, **`TicketTierLifecycleService.php`**, **`EventTicketReconciliationCommands.php`**, **`TicketTypeManager.php`** — OK.
- **`composer validate --no-check-all --strict`** — OK.
- **`ddev drush cr`** — OK.
- **`ddev drush mel:tickets:audit --event=1094`** — **0 errors**, **0 warnings**, **repairable: no**; **info** `inactive_inverse_ticket_types_ignored` for **109, 110** only.
- **`ddev drush mel:tickets:repair --event=1094`** (dry run) — **`no_repair_actions`** / nothing to apply.

## TASK 20 implementation notes

**Audit doc:** [`docs/vendor-console-v2-task20-ticket-ux-smoke.md`](vendor-console-v2-task20-ticket-ux-smoke.md)

### Pages inspected (code + checklist)

- Event Studio paid tickets embed (`EventStudioForm` + `EventTicketManagerForm`).
- Advanced ticket manager (`/vendor/events/{event}/tickets`).
- Public booking matrix (`TicketSelectionForm`, `myeventlane-event-book.html.twig`, `_event-book.scss`).

### Files changed

- **`docs/vendor-console-v2-task20-ticket-ux-smoke.md`** — TASK 20 audit, checklists, verification plan.
- **`docs/vendor-console-v2-route-map.md`** — this subsection.
- **`web/modules/custom/myeventlane_vendor/src/Form/EventTicketManagerForm.php`** — back links (workspace + Studio routes), sync intro before rows, **Save and sync tickets** before collapsed links, renamed tools `<details>` title.
- **`web/modules/custom/myeventlane_vendor/css/event-ticket-manager.css`** — 44px targets, focus rings, back nav, active row, mobile overflow guard.
- **`web/modules/custom/myeventlane_event_studio/src/Form/EventStudioForm.php`** — paid-ticket guidance blurb above embedded manager.
- **`web/modules/custom/myeventlane_vendor/src/Service/EventTicketReconciliationService.php`** — post-save summary treats **`inactive_inverse_ticket_types_ignored`** (info) as non-blocking; optional sentence for legacy inverse rows; clearer warning CTA string.
- **`web/modules/custom/myeventlane_commerce/src/Form/TicketSelectionForm.php`** — primary submit label **Continue to checkout** (panel title remains **Choose your tickets** in Twig).
- **`web/themes/custom/myeventlane_theme/src/scss/components/_event-book.scss`** — narrow-width ticket row / quantity min sizing without horizontal spill.
- **`web/themes/custom/myeventlane_vendor_theme/src/scss/components/_mel-builder.scss`** — Studio paid-ticket guidance styling.

### UX polish completed

- Vendor: three-step copy (add rows → Active → Save and sync); Active label + description unchanged (already correct); save CTA immediately under ticket list; tools collapsed below with disambiguated title.
- Post-save: success when only non-blocking info inverse legacy issue; short checkout-ignore sentence appended; mapping problems point to Advanced manager + Drush.

### Public matrix / checkout smoke

- Matrix: SCSS tweak for small viewports; CTA aligned with TASK 20 wording (**Continue to checkout**). Full browser + cart verification left to operator (see TASK 20 doc log).

### Audit/repair / Watchdog / browser

- **`ddev drush cr`** — OK.
- **`ddev drush mel:tickets:audit --event=1094`** — 0 errors / 0 warnings; **info** `inactive_inverse_ticket_types_ignored` (legacy tiers 109, 110).
- **`ddev drush mel:tickets:repair --event=1094`** — `no_repair_actions` (dry run).
- **`ddev drush mel:tickets:audit --event=1592`** — in this workspace DB, **`variation_without_ticket_type`** (repairable) — differs from TASK 19 “clean” example; see TASK 20 audit doc §8.
- **`ddev drush mel:tickets:repair --event=1592`** — dry run would **`reconcile_event_ticket_references`** (not applied).
- **`ddev drush ws --count=30`** — no ticket-mapping fatals; routine commerce/cart + reconciliation notices.
- **Browser smoke** — not run here.

### Residual risks for TASK 21

- **`both`** mode + autosave ticket subtree parity unchanged from TASK 19.
- Paid matrix end-to-end (cart line variation ID) not automated in CI here.
- Any translation updates for renamed strings need `locale` import if multilingual.

---

## TASK 21 implementation notes

**Date:** 2026-05-05  
**Scope:** Release audit documentation and verification commands only (no production code changes unless a release blocker is found; none applied).

### Deliverables

- **[`docs/vendor-console-v2-task21-release-audit.md`](vendor-console-v2-task21-release-audit.md)** — full release audit: objective, changeset buckets, route/nav, ticket flow, access, debug/logging, legacy grep, build/lint, local smoke, staging checklist, PR pointer, residual risks, 1592 appendix.
- **[`docs/vendor-console-v2-pr-summary.md`](vendor-console-v2-pr-summary.md)** — GitHub-ready PR summary draft.

### Route / nav status

- `ddev drush route` confirms: dashboard, events, Event Studio create/edit, event workspace, advanced tickets, vendor analytics, vendor attendees, settings, messaging brand, Pro branding (all **present** in this environment).
- Vendor theme shell primary action and menus use **`myeventlane_event_studio.create`** with route access checks; **`OrganiserContextBlock`** matches.
- Attendees top-level nav item is **omitted** when `myeventlane_checkout_flow.vendor_attendees` is not accessible.

### Ticket flow status

- **1094:** `field_ticket_types` includes active tiers **111**, **112**; paid product **54**; `mel:tickets:audit` → **0 errors / 0 warnings**; info-only inactive inverse tiers **109**, **110**; `mel:tickets:repair` dry run → **no actions** (Drush may still print a “no_repair_actions” notice).
- **1592:** audit reports **`variation_without_ticket_type`** (repairable `reconcile_event_ticket_references`); **`--apply` not run** in TASK 21 — document as **local data drift**; optional `ddev drush mel:tickets:repair --event=1592 --apply` after human review.

### Debug / log status

- `mel.debug_boost_candidates` and `mel.debug_http_response_trace` state keys **not set** (treated as disabled).
- Watchdog sample: routine commerce/cart/reconciliation; **`TEMP_DEBUG`** notices in commerce attendee paths — flag for **production hygiene** follow-up.

### Build / lint / cache results

- **`php -l`**: all changed PHP files in diff — **no syntax errors**.
- **`composer validate`**: **OK**.
- **`npm run mel:lint`**: **OK**.
- **`npm run mel:build`**: **OK** (public + vendor theme Vite builds).
- **`ddev drush cr`**: **OK**.

### Browser smoke status

- **Not run** in TASK 21 session; use release audit **§10 staging smoke checklist**.

### Staging smoke checklist

- Captured in **`vendor-console-v2-task21-release-audit.md` §10** (pre-deploy / deploy / post-deploy).

### Legacy link grep

- No **`myeventlane_vendor.console.create_event`** in `web/modules/custom` or `web/themes/custom` code (docs/history only).
- Remaining `/vendor/event/`, `/vendor/studio`, `/vendor/events/add`, `/node/add/event` hits classified as **routing**, **redirects**, **internal API**, **tests**, **install shortcuts**, or **documentation** — see TASK 21 audit **§7**.

### Residual risks (TASK 21)

- Confirm **deleted `commerce_price.commerce_currency.USD.yml`** is intentional.
- Reduce or gate **`TEMP_DEBUG`** commerce logging before production if still enabled in code paths.
- Replace hardcoded **`waitlist_url`** legacy singular path in `VendorDashboardController` with canonical route generation when convenient (non-blocking for this release audit).
- **1592** ticket reference drift until optional repair applied.
