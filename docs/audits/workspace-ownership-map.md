# Workspace Ownership Map — Phase 1C

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** Vendor Event Workspace (`/vendor/events/{event}/*`) vs Event Studio workspace (`/vendor/events/{node}/studio/*`).

**Redirect evidence:** `VendorLegacyWizardRedirectSubscriber.php` sends **non-staff vendors** from most `/vendor/events/{event}/*` routes to matching Event Studio sections. Staff with `administer nodes` bypass redirects and may still render vendor workspace pages.

---

## Feature comparison matrix

| Feature | Vendor workspace route | Vendor workspace controller/form | Event Studio route | Event Studio render target | Duplicate? |
|---------|------------------------|----------------------------------|--------------------|----------------------------|------------|
| **Overview** | `myeventlane_vendor.console.event_workspace` (`/vendor/events/{event}`) | `EventWorkspaceController::workspace` + `VendorEventWorkspaceViewModelBuilder` | `myeventlane_event_studio.workspace` | `OverviewSection` → `overview` | **Yes** — parallel mission-control shell; vendor redirected to Studio |
| **Overview (alt path)** | `myeventlane_vendor.console.event_overview` (`/overview`) | `VendorEventOverviewController::overview` | `myeventlane_event_studio.workspace` | Same | **Yes** — linked from `node--event--vendor-card.html.twig`; redirects to Studio for vendors |
| **Information** | — (embedded in overview/edit CTAs) | CTAs → `myeventlane_event_studio.edit` | `myeventlane_event_studio.workspace_information` | `InformationSection` → `EventInformationForm` | **Partial** — vendor workspace links out to Studio for edit |
| **Branding** | `myeventlane_vendor.manage_event.design` | `ManageEventDesignController` | `myeventlane_event_studio.workspace_branding` | `BrandingSection` → `EventBrandingForm` | **Yes** — manage route redirected to Studio branding |
| **Content** | `myeventlane_vendor.manage_event.content` | `ManageEventContentController` | `myeventlane_event_studio.workspace_content` | `ContentSection` → `EventContentForm` | **Yes** |
| **Tickets (authoring)** | `myeventlane_vendor.console.event_tickets` | `EventTicketManagerForm` | `myeventlane_event_studio.workspace_tickets` | `TicketsSection` → `tickets_stack` (`EventStudioTicketsForm` + operational) | **Yes** — advanced ticket manager vs Studio stack |
| **Questions** | `myeventlane_vendor.manage_event.checkout_questions` | `ManageEventCheckoutQuestionsController` | `myeventlane_event_studio.workspace_questions` | `QuestionsSection` → `EventCheckoutQuestionsForm` | **Yes** |
| **Capacity** | — | — | `myeventlane_event_studio.workspace_capacity` | `CapacitySection` → `capacity_summary` (readonly) | **Studio-only** |
| **Extras** | — | Panels in `EventWorkspaceController` via sales builders | `myeventlane_event_studio.workspace_extras` | `ExtrasSection` → `EventStudioEventExtrasForm` | **Partial** — vendor workspace shows summary panels; authoring in Studio |
| **Fulfilment** | — | — | `myeventlane_event_studio.workspace_fulfilment` | `FulfilmentSection` → operational capability form | **Studio-only** |
| **Orders** | `myeventlane_vendor.console.event_orders` | `VendorEventOrdersController` | `myeventlane_event_studio.workspace_orders` | `OrdersSection` → readonly summary | **Yes** — vendor console has full orders UI; Studio section readonly/deferred |
| **Attendees** | `myeventlane_event_attendees.vendor_list` (tab) | Attendees module list | `myeventlane_event_studio.workspace_attendees` | `AttendeesSection` → readonly summary | **Partial** — list vs Studio summary |
| **RSVPs** | `myeventlane_vendor.console.event_rsvps` | `VendorEventRsvpController` | Redirect → `workspace_attendees` | — | **Yes** |
| **Analytics** | `myeventlane_vendor.console.event_analytics` | `VendorEventAnalyticsController` | `myeventlane_event_studio.workspace_analytics` | `AnalyticsSection` → readonly summary | **Yes** — full analytics page vs Studio readonly panel |
| **Messaging / promotion** | `myeventlane_vendor.console.event_promotion` | `VendorEventCommsForm` | `myeventlane_event_studio.workspace_messaging` | `MessagingSection` → `MessagingForm` | **Yes** |
| **Branding (comms)** | `myeventlane_vendor_comms.branding` | `EventBrandOverrideForm` | Redirect → `workspace_branding` | — | **Yes** |
| **Settings** | `myeventlane_vendor.console.event_settings` | `VendorEventSettingsController` | `myeventlane_event_studio.workspace_settings` | `SettingsSection` → `EventSettingsForm` + readiness | **Yes** |
| **Publish** | `myeventlane_vendor.console.event_publish` | `EventWorkspaceController::publish` → redirect | `myeventlane_event_studio.publish` POST | `EventStudioPublishController` | **Yes** — vendor publish route is redirect-only (`EventWorkspaceController.php:87-111`) |
| **Unpublish** | `myeventlane_vendor.console.event_unpublish` | `EventUnpublishForm` | Redirect → `workspace_settings` | Settings form | **Partial** |

---

## Vendor workspace navigation sources (still pointing at console routes)

| Source | Routes used | Notes |
|--------|-------------|-------|
| `VendorEventTabsService::buildTabRows()` | `event_workspace`, `event_tickets`, `event_rsvps`, `event_orders`, `event_analytics`, `event_promotion`, `event_settings` | Primary tab builder for workspace Twig |
| `myeventlane_vendor.links.task.yml` | Local tasks on `event_workspace` base | Drupal tabs for orders, tickets, analytics, settings, etc. |
| `VendorConsolePagePreprocess.php` | Same console routes | Legacy workspace tab strip |
| `node--event--vendor-card.html.twig` | `event_overview` for Manage link | Vendor list cards |
| `VendorEventOverviewController` quick actions | `event_tickets`, `event_analytics`, `event_settings` | Mission control shortcuts |
| `EventStudioController::workspace` | `event_workspace` in `manage_event_url` | Studio links back to vendor mission control |
| `EventStudioEventExtrasForm` / product editor | `event_workspace` back links | Cross-link Studio → vendor workspace |

**Effect for vendors:** Most links above hit console routes → **302 to Event Studio** via subscriber. UI labels still say "Manage event", "Settings", etc.

**Effect for staff:** Console routes **render** full vendor workspace pages (subscriber bypass).

---

## Event Studio section ownership (canonical authoring)

All section plugins live under `web/modules/custom/myeventlane_event_studio/src/Plugin/EventStudioSection/`.

| Section ID | Route | Writable | Autosave (plugin flag) |
|------------|-------|----------|------------------------|
| `overview` | `workspace` | Yes | — |
| `information` | `workspace_information` | Yes | Yes |
| `branding` | `workspace_branding` | Yes | Yes |
| `content` | `workspace_content` | Yes | Yes |
| `tickets` | `workspace_tickets` | Yes | Yes |
| `questions` | `workspace_questions` | Yes | — |
| `capacity` | `workspace_capacity` | Readonly | — |
| `extras` | `workspace_extras` | Yes | No |
| `fulfilment` | `workspace_fulfilment` | Yes | Yes |
| `messaging` | `workspace_messaging` | Yes | — |
| `attendees` | `workspace_attendees` | Readonly | — |
| `orders` | `workspace_orders` | Readonly | — |
| `analytics` | `workspace_analytics` | Readonly | — |
| `settings` | `workspace_settings` | Yes | Yes (via form stack) |

---

## Redirect mapping (vendor non-staff)

From `VendorLegacyWizardRedirectSubscriber::sectionRouteForLegacyRoute()`:

| Vendor workspace route | Redirects to Studio route |
|------------------------|---------------------------|
| `console.event_workspace`, `console.event_overview` | `workspace` |
| `console.event_tickets`, ticket module routes | `workspace_tickets` |
| `console.event_rsvps`, attendees routes | `workspace_attendees` |
| `console.event_orders`, `event_order_view` | `workspace_orders` |
| `console.event_analytics` | `workspace_analytics` |
| `console.event_promotion` | `workspace_messaging` |
| `console.event_settings`, `event_publish`, `event_unpublish` | `workspace_settings` |
| `manage_event.content` | `workspace_content` |
| `manage_event.edit`, `console.event_editor` | `workspace_information` |
| `manage_event.design`, comms branding | `workspace_branding` |
| `manage_event.checkout_questions` | `workspace_questions` |

---

## Ownership decision (Phase 1C — evidence only)

| Surface | Canonical for vendors (repository-confirmed) | Parallel surface status |
|---------|-----------------------------------------------|-------------------------|
| Event authoring (fields, tickets setup, publish) | **Event Studio workspace** | Vendor Studio JSON API **UNUSED** for vendors; legacy staff shell |
| Operational management (orders list, full analytics, check-in) | **Vendor workspace routes** (still implemented) | Render blocked for vendors via redirect; **must remain** until Studio reaches parity (WP-5) |
| Staff/support tooling | Vendor workspace + staff Vendor Studio shell + legacy wizard | Intentionally retained in Phase 1B |

---

## Safe redirect candidates (Phase 1D — link updates only, not route removal)

These links could point directly at Studio routes **without removing vendor routes** (reduces redirect hop):

| Current link target | Suggested canonical target | Evidence |
|--------------------|----------------------------|----------|
| `node--event--vendor-card` → `event_overview` | `myeventlane_event_studio.workspace` | Card is vendor-facing; overview redirects anyway |
| `VendorEventWorkspaceViewModelBuilder` edit CTAs already use `event_studio.edit` / `workspace_tickets` | — | Partially migrated |
| `EventStudioController` `manage_event_url` → `event_workspace` | Keep or switch to `workspace` | Cross-navigation design choice for WP-5 |

**Not safe without WP-5:** Replacing `event_orders`, `event_analytics`, or `event_tickets` links — those routes render richer UIs for staff and different capabilities than Studio readonly sections.
