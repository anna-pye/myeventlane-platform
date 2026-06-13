# Event Route Ownership Map — Phase 1A (WP-0)

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13 (Phase 1D update)  
**Scope:** Event creation, editing, publishing, and management routes only. Evidence from routing YAML and controller/form class declarations in the repository.

**Phase 1B note:** See `docs/audits/legacy-workflow-verification.md` for WP-1/WP-2 retirement evidence.

**Phase 1D note:** See `docs/audits/vendor-studio-api-usage.md` and `docs/audits/event-management-navigation-map.md` for WP-4/WP-5 consolidation evidence.

**Phase 1E note:** See `docs/audits/staff-shell-retirement-audit.md` and `docs/audits/staff-shell-caller-map.md`. Staff Vendor Studio shell retired; `/vendor/studio` and `/vendor/events/{event}/editor` are redirect-only aliases to Event Studio.

**Status legend**

| Status | Meaning |
|--------|---------|
| **Canonical** | Intended primary surface for this workflow step |
| **Alias** | Registered route that redirects or wraps the canonical route |
| **Legacy** | Superseded; redirect-only or staff-only per code comments |
| **Removed** | Route name/path retired in Phase 1B (replaced by alias redirect at same path) |
| **Duplicate** | Parallel surface rendering or writing the same capability |
| **Unknown** | Route exists; ownership not documented in repository |

---

## 1. Event creation

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_vendor.create_event_gateway` | `/create-event` | `CreateEventGatewayController::gateway` | Public/vendor entry: login, organiser onboarding, draft resume, then redirect to Event Studio create or edit | **Canonical** (entry gateway) |
| `myeventlane_event_studio.create` | `/vendor/events/create` | `myeventlane_event_studio.controller:buildCreate` (`EventStudioController`) | Event Studio create (draft + edit redirect) | **Canonical** (authoring create) |
| `myeventlane_vendor.console.events_add` | `/vendor/events/add` | `VendorEventCreateController::buildForm` | Legacy add URL; redirects to `myeventlane_event_studio.create` | **Alias** |
| `myeventlane_vendor.onboard.first_event` | `/vendor/onboard/first-event` | `VendorOnboardFirstEventController::firstEvent` | Onboarding step linking to Event Studio create | **Alias** (onboarding funnel) |
| `entity.node.add_form` (event bundle) | `/node/add/event` | Core node add form | Access denied for vendors via `EventNodeFormAccessSubscriber` | **Legacy** (blocked) |

**Evidence:** `myeventlane_vendor.routing.yml:167-176`, `508-514`, `237-247`; `myeventlane_event_studio.routing.yml:1-8`; `CreateEventGatewayController.php:79-214`; `VendorEventCreateController.php:48-59`.

---

## 2. Event editing

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_event_studio.edit` | `/vendor/events/{node}/edit` | `EventStudioController::buildEdit` | Event Studio unified edit entry | **Canonical** |
| `myeventlane_vendor.manage_event.edit` | `/vendor/event/{event}/edit` | `ManageEventEditController::edit` | Redirects to `myeventlane_event_studio.edit` | **Alias** |
| `myeventlane_vendor.console.event_editor` | `/vendor/events/{event}/editor` | `VendorStudioRedirectController::eventEditor` | Redirects to `myeventlane_event_studio.edit` | **Alias** |
| `myeventlane_vendor.console.studio` | `/vendor/studio` | `VendorStudioRedirectController::studio` | Redirects to Event Studio create/edit | **Alias** |
| `myeventlane_event_studio.edit_basic` | `/vendor/events/{node}/edit/basic` | `EventStudioController::redirectLegacyEditToInformation` | Bookmark alias → `workspace_information` | **Alias** |
| `myeventlane_event_studio.edit_datetime` | `/vendor/events/{node}/edit/datetime` | `EventStudioController::redirectLegacyEditToInformation` | Bookmark alias → `workspace_information` | **Alias** |
| `myeventlane_event_studio.edit_tickets` | `/vendor/events/{node}/edit/tickets` | `EventStudioController::redirectLegacyEditToTickets` | Bookmark alias → `workspace_tickets`; `EventStudioTicketsForm` retained for workspace embed | **Alias** |
| `myeventlane_event_studio.edit_description` | `/vendor/events/{node}/edit/description` | `EventStudioController::redirectLegacyEditToContent` | Bookmark alias → `workspace_content`; `EventStudioDescriptionForm` retained via `EventContentForm` | **Alias** |
| `myeventlane_event_studio.edit_preview` | `/vendor/events/{node}/edit/preview` | `EventStudioController::redirectLegacyEditToContent` | Preview step removed; path redirects to content section | **Alias** |
| `myeventlane_event_studio.edit_publish` | `/vendor/events/{node}/edit/publish` | `EventStudioController::redirectLegacyEditToSettings` | Bookmark alias → `workspace_settings`; `EventStudioPublishForm` retained via `EventSettingsForm` | **Alias** |
| `myeventlane_event.wizard.basics` | `/vendor/events/{event}/build/basics` | `EventWizardBasicsForm` | Staff-only wizard step; vendors redirected via subscriber | **Legacy** (staff-only) |
| `myeventlane_event.wizard.when_where` | `/vendor/events/{event}/build/when-where` | `EventWizardWhenWhereForm` | Staff-only wizard step | **Legacy** (staff-only) |
| `myeventlane_event.wizard.tickets` | `/vendor/events/{event}/build/tickets` | `EventWizardTicketsForm` | Staff-only wizard step | **Legacy** (staff-only) |
| `myeventlane_event.wizard.details` | `/vendor/events/{event}/build/details` | `EventWizardDetailsForm` | Staff-only wizard step | **Legacy** (staff-only) |
| `myeventlane_event.wizard.review` | `/vendor/events/{event}/build/review` | `EventWizardReviewForm` | Staff-only wizard step | **Legacy** (staff-only) |
| `myeventlane_event.wizard.publish` | `/vendor/events/{event}/build/publish` | `EventWizardPublishForm` | Staff-only wizard publish step | **Legacy** (staff-only) |
| `myeventlane_event.wizard.success` | `/vendor/events/{event}/build/success` | `VendorEventWizardController::success` | Staff-only wizard success page | **Legacy** (staff-only) |
| `entity.node.edit_form` (event) | `/node/{node}/edit` | Core node edit | Blocked for vendor event editing | **Legacy** (blocked) |

**Evidence:** `myeventlane_event_studio.routing.yml:10-345`; `myeventlane_event.routing.yml:1-107`; `VendorLegacyWizardRedirectSubscriber.php:31-69`; `myeventlane_vendor.routing.yml:274-286, 322-346`.

---

## 3. Event publishing

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_event_studio.publish` | `/vendor/events/{node}/studio/publish` | `publish_controller:publish` (POST) | Canonical Event Studio publish action | **Canonical** |
| `myeventlane_event_studio.edit_publish` | `/vendor/events/{node}/edit/publish` | `EventStudioController::redirectLegacyEditToSettings` | Bookmark alias → `workspace_settings` | **Alias** |
| `myeventlane_event.wizard.publish` | `/vendor/events/{event}/build/publish` | `EventWizardPublishForm` | Staff-only wizard publish; vendors redirected | **Legacy** (staff-only) |
| `myeventlane_vendor.console.event_publish` | `/vendor/events/{event}/publish` | `EventWorkspaceController::publish` | Vendor console workspace publish | **Duplicate** |
| `myeventlane_vendor.console.studio.event_publish` | `/vendor/studio/event/{event}/publish` | *(removed Phase 1D)* | Legacy Vendor Studio JSON publish | **Removed** |
| `myeventlane_vendor.console.event_unpublish` | `/vendor/events/{event}/unpublish` | `EventUnpublishForm` | Unpublish event | **Canonical** (unpublish) |

**Evidence:** `myeventlane_event_studio.routing.yml:334-370`; `myeventlane_vendor.routing.yml:686-699, 735-748`; Phase 1D removed `myeventlane_vendor.console.studio.event_publish` and related POST routes from `myeventlane_vendor.routing.yml`.

---

## 4. Event management (workspace / console)

### 4.1 Event Studio workspace (canonical)

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_event_studio.workspace` | `/vendor/events/{node}/studio` | `EventStudioController::workspace` | Overview section | **Canonical** |
| `myeventlane_event_studio.workspace_information` | `…/studio/information` | `EventStudioController::workspace` | Information section | **Canonical** |
| `myeventlane_event_studio.workspace_branding` | `…/studio/branding` | `EventStudioController::workspace` | Branding section | **Canonical** |
| `myeventlane_event_studio.workspace_content` | `…/studio/content` | `EventStudioController::workspace` | Content section | **Canonical** |
| `myeventlane_event_studio.workspace_tickets` | `…/studio/tickets` | `EventStudioController::workspace` | Tickets section | **Canonical** |
| `myeventlane_event_studio.workspace_questions` | `…/studio/questions` | `EventStudioController::workspace` | Questions section | **Canonical** |
| `myeventlane_event_studio.workspace_capacity` | `…/studio/capacity` | `EventStudioController::workspace` | Capacity section | **Canonical** |
| `myeventlane_event_studio.workspace_extras` | `…/studio/extras` | `EventStudioController::workspace` | Extras section | **Canonical** |
| `myeventlane_event_studio.workspace_messaging` | `…/studio/messaging` | `EventStudioController::workspace` | Messaging section | **Canonical** |
| `myeventlane_event_studio.workspace_attendees` | `…/studio/attendees` | `EventStudioController::workspace` | Attendees section | **Canonical** |
| `myeventlane_event_studio.workspace_fulfilment` | `…/studio/fulfilment` | `EventStudioController::workspace` | Fulfilment section | **Canonical** |
| `myeventlane_event_studio.workspace_orders` | `…/studio/orders` | `EventStudioController::workspace` | Orders section | **Canonical** |
| `myeventlane_event_studio.workspace_analytics` | `…/studio/analytics` | `EventStudioController::workspace` | Analytics section | **Canonical** |
| `myeventlane_event_studio.workspace_settings` | `…/studio/settings` | `EventStudioController::workspace` | Settings section | **Canonical** |
| `myeventlane_event_studio.workspace_merchandise` | `…/studio/merchandise` | `redirectToExtrasWorkspace` | Alias → extras | **Alias** |
| `myeventlane_event_studio.workspace_addons` | `…/studio/addons` | `redirectToExtrasWorkspace` | Alias → extras | **Alias** |
| `myeventlane_event_studio.workspace_add_ons` | `…/studio/add-ons` | `redirectToExtrasWorkspace` | Alias → extras | **Alias** |
| `myeventlane_event_studio.workspace_promotions` | `…/studio/promotions` | `redirectToMessagingWorkspace` | Alias → messaging | **Alias** |

**Evidence:** `myeventlane_event_studio.routing.yml:24-267`.

### 4.2 Event Studio API (authoring support)

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_event_studio.autosave` | `/vendor/events/autosave` | `autosave_controller:handle` | Autosave POST | **Canonical** |
| `myeventlane_event_studio.governance_refresh` | `…/studio/governance-refresh` | `governance_refresh_controller:refresh` | Governance refresh POST | **Canonical** |
| `myeventlane_event_studio.governance_component` | `…/studio/component/{component}` | `governance_component_controller:render` | Governance component POST | **Canonical** |
| `myeventlane_event_studio.ai_assist` | `…/studio/ai/assist` | `ai_controller:assist` | AI assist POST | **Canonical** |
| `myeventlane_event_studio.ticket_link_suggestions` | `/vendor/events/studio/ticket-link-suggestions` | `EventStudioTicketSuggestionsController::suggest` | Ticket link suggestions POST | **Canonical** |

**Evidence:** `myeventlane_event_studio.routing.yml:347-431`.

### 4.3 Vendor console event workspace (parallel management UI)

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_vendor.console.events` | `/vendor/events` | `vendor_events:list` | Event list | **Duplicate** (list; not authoring) |
| `myeventlane_vendor.console.event_workspace` | `/vendor/events/{event}` | `event_workspace:workspace` | Event Manager shell; vendors redirected to Event Studio workspace via subscriber | **Legacy** (staff mission control) |
| `myeventlane_vendor.console.event_overview` | `/vendor/events/{event}/overview` | `vendor_event_overview:overview` | Manage event overview; vendors redirected to Event Studio workspace | **Legacy** (staff mission control) |
| `myeventlane_vendor.console.event_orders` | `/vendor/events/{event}/orders` | `vendor_event_orders:orders` | Event orders | **Duplicate** |
| `myeventlane_vendor.console.event_operational_addon_orders` | `/vendor/events/{event}/addons` | `vendor_operational_addon_orders:addons` | Add-on orders | **Duplicate** |
| `myeventlane_vendor.console.event_order_view` | `/vendor/events/{event}/orders/{order}` | `vendor_event_order_view:view` | Order detail | **Duplicate** |
| `myeventlane_vendor.console.event_tickets` | `/vendor/events/{event}/tickets` | `EventTicketManagerForm` | Ticket manager form | **Duplicate** |
| `myeventlane_vendor.console.event_rsvps` | `/vendor/events/{event}/rsvps` | `vendor_event_rsvps:rsvps` | RSVPs | **Duplicate** |
| `myeventlane_vendor.console.event_analytics` | `/vendor/events/{event}/analytics` | `vendor_event_analytics:analytics` | Event analytics page | **Duplicate** |
| `myeventlane_vendor.console.event_settings` | `/vendor/events/{event}/settings` | `vendor_event_settings:settings` | Event settings | **Duplicate** |
| `myeventlane_vendor.console.event_archive` | `/vendor/events/{event}/archive` | `vendor_event_archive:handle` | Archive POST | **Duplicate** |
| `myeventlane_vendor.console.event_promotion` | `/vendor/events/{event}/promotion` | `VendorEventCommsForm` | Attendee messaging | **Duplicate** |
| `myeventlane_vendor_comms.branding` | `/vendor/events/{event}/promotion/branding` | `EventBrandOverrideForm` | Message branding | **Duplicate** |

**Evidence:** `myeventlane_vendor.routing.yml:500-716`.

### 4.4 Singular `/vendor/event/{event}/*` manage routes

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_vendor.manage_event.design` | `/vendor/event/{event}/design` | `ManageEventDesignController::design` | Page design | **Duplicate** |
| `myeventlane_vendor.manage_event.content` | `/vendor/event/{event}/content` | `ManageEventContentController::content` | Page content | **Duplicate** |
| `myeventlane_vendor.manage_event.tickets` | `/vendor/event/{event}/tickets` | `ManageEventTicketsController::redirectToCanonicalTickets` | Redirect to tickets | **Alias** |
| `myeventlane_vendor.manage_event.checkout_questions` | `/vendor/event/{event}/checkout-questions` | `ManageEventCheckoutQuestionsController` | Checkout questions | **Duplicate** |
| `myeventlane_vendor.manage_event.series` | `/vendor/event/{event}/series` | `ManageSeriesInstancesController::listInstances` | Series instances | **Duplicate** |
| `myeventlane_vendor.manage_event.promote` | `/vendor/event/{event}/promote` | `ManageEventPlaceholderController::placeholder` | Placeholder stub | **Legacy** |
| `myeventlane_vendor.manage_event.payments` | `/vendor/event/{event}/payments` | `ManageEventPlaceholderController::placeholder` | Placeholder stub | **Legacy** |
| `myeventlane_vendor.manage_event.comms` | `/vendor/event/{event}/comms` | `ManageEventPlaceholderController::placeholder` | Placeholder stub | **Legacy** |
| `myeventlane_vendor.manage_event.advanced` | `/vendor/event/{event}/advanced` | `ManageEventPlaceholderController::placeholder` | Placeholder stub | **Legacy** |

**Evidence:** `myeventlane_vendor.routing.yml:786-921`; comment at lines 856-858.

### 4.5 Legacy Vendor Studio JSON API (retired Phase 1D–1E)

| Route Name | Path | Controller/Form | Purpose | Status |
|------------|------|-----------------|---------|--------|
| `myeventlane_vendor.console.studio.event_data` | `/vendor/studio/event/{event}/data` | *(removed Phase 1E)* | Staff shell read JSON | **Removed** |
| `myeventlane_vendor.studio_event_schema` | `/vendor/studio/schema/event` | *(removed Phase 1E)* | Dynamic form schema GET | **Removed** |
| `myeventlane_vendor.studio_event_save` | `/vendor/studio/event/{event}/save` | *(removed Phase 1D)* | Generic save POST | **Removed** |
| `myeventlane_vendor.console.studio.event_overview_save` | `…/overview` | *(removed Phase 1D)* | Overview save POST | **Removed** |
| `myeventlane_vendor.console.studio.event_tickets_save` | `…/tickets` | *(removed Phase 1D)* | Tickets save POST | **Removed** |
| `myeventlane_vendor.console.studio.event_attendees_save` | `…/attendees` | *(removed Phase 1D)* | Attendees save POST | **Removed** |
| `myeventlane_vendor.console.studio.event_promotion_save` | `…/promotion` | *(removed Phase 1D)* | Promotion save POST | **Removed** |
| `myeventlane_vendor.console.studio.event_settings_save` | `…/settings` | *(removed Phase 1D)* | Settings save POST | **Removed** |
| `myeventlane_vendor.console.studio.event_publish` | `…/publish` | *(removed Phase 1D)* | Publish POST | **Removed** |
| `myeventlane_vendor.console.studio.submit_review` | `…/submit-review` | *(removed Phase 1D)* | Submit review POST | **Removed** |

**Evidence:** `myeventlane_vendor.routing.yml` (redirect aliases only at `/vendor/studio`, `/vendor/events/{event}/editor`); `VendorStudioRedirectController.php`; Phase 1E audit docs.

### 4.7 Vendor manage-link consolidation (Phase 1D)

High-confidence vendor-facing manage destinations now point directly to Event Studio workspace (no `event_overview` / `event_workspace` redirect hop):

| Surface | Route before | Route after | Status |
|---------|--------------|-------------|--------|
| Vendor event card (`node--event--vendor-card`) | `myeventlane_vendor.console.event_overview` | `myeventlane_event_studio.workspace` | **Consolidated** |
| Events index view model (`VendorEventIndexViewModelBuilder`) | `myeventlane_vendor.console.event_workspace` | `myeventlane_event_studio.workspace` | **Consolidated** |
| Dashboard event cards (`VendorDashboardViewModelBuilder`) | `myeventlane_vendor.console.event_workspace` | `myeventlane_event_studio.workspace` | **Consolidated** |
| Waitlist management back link | `myeventlane_vendor.console.event_overview` | `myeventlane_event_studio.workspace` | **Consolidated** |
| Event duplicate error fallback | `myeventlane_vendor.console.event_overview` | `myeventlane_event_studio.workspace` | **Consolidated** |
| Boost performance overview link | `myeventlane_vendor.console.event_overview` | `myeventlane_event_studio.workspace` | **Consolidated** |
| Event Studio topbar “Manage event” | `myeventlane_vendor.console.event_workspace` (all users) | Same route, **staff-only** link (`administer nodes` or uid 1) | **Loop removed** |

**Not changed (staff mission control / operational parity pending):** `event_orders`, `event_analytics`, `VendorEventTabsService`, local tasks, extras operational doc links to `event_workspace`.

### 4.6 Related event operations (out of consolidation scope but listed)

| Route Name | Path | Purpose | Status |
|------------|------|---------|--------|
| `myeventlane_event.duplicate` | `/vendor/events/{node}/duplicate` | Duplicate/rebook event | **Canonical** (feature) |
| `myeventlane_event.generate_series_instances` | `/vendor/event/{event}/series/generate` | Generate series instances | **Canonical** |
| `myeventlane_event.calendar_ics` | `/event/{node}/calendar.ics` | ICS download | **Canonical** (public) |
| `myeventlane_event.checkin_door` | `/event/{event}/checkin` | Door check-in | **Canonical** |
| `myeventlane_event.passcode_gate` | `/event/{node}/passcode` | Private event passcode | **Canonical** |

**Evidence:** `myeventlane_event.routing.yml:109-197`.

---

## 5. Target canonical flow (repository-confirmed)

```
Create Event (public/vendor nav)
  → myeventlane_vendor.create_event_gateway  (/create-event)
      → onboarding / draft checks (CreateEventGatewayController)
      → myeventlane_event_studio.create      (/vendor/events/create)
      → myeventlane_event_studio.edit          (draft resume)

Edit / Manage
  → myeventlane_event_studio.edit
  → myeventlane_event_studio.workspace_{section}
  → (staff only) myeventlane_vendor.console.event_workspace mission control

Publish
  → myeventlane_event_studio.publish (POST)
```

Vendor-facing manage CTAs (dashboard cards, event list, waitlist back links) now target `myeventlane_event_studio.workspace` directly per Phase 1D.

**Evidence:** `CreateEventGatewayController.php:79-214`; Phase 1C/1D audits in `docs/audits/vendor-studio-api-usage.md`, `docs/audits/event-management-navigation-map.md`.

---

## 6. Route count summary

| Category | Canonical | Alias | Legacy | Removed | Duplicate |
|----------|-----------|-------|--------|---------|-----------|
| Creation | 2 | 2 | 1 | 0 | 0 |
| Editing | 1 | 9 | 7 | 0 | 0 |
| Publishing | 2 | 0 | 2 | 1 | 2 |
| Management (Studio) | 18 + 5 API | 4 | 0 | 0 | 0 |
| Management (Vendor parallel) | 0 | 1 | 6 | 0 | 20+ |
| Vendor Studio JSON API | 0 | 0 | 2 | 8 | 0 |

**Total event workflow routes inventoried:** 80+ (across `myeventlane_event_studio`, `myeventlane_vendor`, `myeventlane_event` routing files).
