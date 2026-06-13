# Event Management Navigation Map — Phase 1C

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** User-facing navigation paths to Vendor Workspace and Event Studio using **actual route names** from routing YAML and confirmed Twig/menu/PHP link builders.

**Note:** Vendors with vendor-console access hitting legacy `/vendor/events/{event}/*` routes are **302 redirected** to Event Studio sections by `VendorLegacyWizardRedirectSubscriber` unless they have `administer nodes`.

---

## 1. Homepage → Create event

```
Public homepage (myeventlane_front)
  → myeventlane_front.front_page (or node/view routes)
  → myeventlane_vendor.create_event_gateway          [/create-event]
      (header CTA: region--header.html.twig, hero.twig, mel-footer-cta-band.html.twig)
  → [gateway checks: login, vendor profile, onboarding, legal, draft resume]
  → myeventlane_event_studio.create                   [/vendor/events/create]
  → myeventlane_event_studio.workspace                [/vendor/events/{node}/studio]
```

**Alternate entry (account menu):**

```
Account menu
  → myeventlane_vendor.menu_account.create_event
  → myeventlane_vendor.create_event_gateway
  → (same gateway chain as above)
```

**Footer:**

```
footer-host menu
  → myeventlane_core.footer_host.create_event
  → myeventlane_vendor.create_event_gateway
```

---

## 2. Vendor dashboard → Manage event

```
Account menu / vendor console
  → myeventlane_vendor.console.dashboard             [/vendor/dashboard]
  → myeventlane_vendor.console.events                [/vendor/events]
      (event list: VendorEventsController)
  → [Manage link on card]
  → myeventlane_vendor.console.event_overview        [/vendor/events/{event}/overview]
      (node--event--vendor-card.html.twig)
  → [VendorLegacyWizardRedirectSubscriber — vendor]
  → myeventlane_event_studio.workspace               [/vendor/events/{node}/studio]
```

**Dashboard quick actions (already canonical):**

```
myeventlane_vendor.console.dashboard
  → VendorDashboardViewModelBuilder / VendorDashboardController
  → myeventlane_event_studio.edit                    [/vendor/events/{node}/edit]
  → myeventlane_event_studio.workspace               (edit route redirects to workspace)
```

---

## 3. Create event (full chain)

```
myeventlane_vendor.create_event_gateway
  → CreateEventGatewayController::gateway
  → [anonymous] user.login → return to /create-event
  → [no vendor] myeventlane_vendor.onboard.profile
  → [draft exists] myeventlane_event_studio.edit → workspace
  → [else] myeventlane_event_studio.create
      → EventStudioController::buildCreate
      → myeventlane_event_studio.workspace
```

**Onboarding funnel:**

```
myeventlane_vendor.onboard.first_event
  → myeventlane_event_studio.create
  → myeventlane_event_studio.workspace
```

**Legacy add URL:**

```
myeventlane_vendor.console.events_add                [/vendor/events/add]
  → VendorEventCreateController
  → redirect myeventlane_event_studio.create
```

---

## 4. Manage event (Vendor workspace tabs — legacy console)

```
myeventlane_vendor.console.event_workspace           [/vendor/events/{event}]
  → EventWorkspaceController::workspace
  → VendorEventTabsService tabs:
      → myeventlane_vendor.console.event_workspace     (overview)
      → myeventlane_vendor.console.event_tickets       [/tickets]
      → myeventlane_vendor.console.event_rsvps         [/rsvps]
      → myeventlane_event_attendees.vendor_list        [/vendor/events/{node}/attendees]
      → myeventlane_vendor.console.event_orders        [/orders]
      → myeventlane_vendor.console.event_analytics     [/analytics]
      → myeventlane_vendor.console.event_promotion     [/promotion]
      → myeventlane_vendor.console.event_settings      [/settings]
  → [vendor redirect subscriber maps each to Studio section]
```

**Drupal local tasks** (`myeventlane_vendor.links.task.yml`) mirror the same console routes under base `event_workspace`.

**Open Event Studio CTA (from vendor workspace pages):**

```
VendorEventOverviewController / VendorEventSettingsController
  → myeventlane_event_studio.edit
  → myeventlane_event_studio.workspace
```

---

## 5. Manage event (Event Studio — canonical)

```
myeventlane_event_studio.edit                        [/vendor/events/{node}/edit]
  → EventStudioController::buildEdit
  → myeventlane_event_studio.workspace

Section navigation (shell sidebar):
  → myeventlane_event_studio.workspace                 (overview)
  → myeventlane_event_studio.workspace_information
  → myeventlane_event_studio.workspace_branding
  → myeventlane_event_studio.workspace_content
  → myeventlane_event_studio.workspace_tickets
  → myeventlane_event_studio.workspace_questions
  → myeventlane_event_studio.workspace_capacity
  → myeventlane_event_studio.workspace_extras
  → myeventlane_event_studio.workspace_messaging
  → myeventlane_event_studio.workspace_attendees
  → myeventlane_event_studio.workspace_fulfilment
  → myeventlane_event_studio.workspace_orders
  → myeventlane_event_studio.workspace_analytics
  → myeventlane_event_studio.workspace_settings
```

**Cross-link back to vendor mission control:**

```
EventStudioController::workspace
  → manage_event_url: myeventlane_vendor.console.event_workspace
  → [vendor] redirects back to Studio (loop via subscriber) OR staff sees mission control
```

---

## 6. Publish event

```
Event Studio workspace (any section)
  → Topbar [data-mel-publish-action]
  → POST myeventlane_event_studio.publish              [/vendor/events/{node}/studio/publish]
  → EventStudioPublishController::publish
```

**Legacy/alternate paths:**

```
Vendor workspace tab / preprocess link
  → myeventlane_vendor.console.event_publish           [/vendor/events/{event}/publish]
  → EventWorkspaceController::publish
  → redirect myeventlane_event_studio.edit → workspace

Legacy edit publish bookmark
  → myeventlane_event_studio.edit_publish              [/vendor/events/{node}/edit/publish]
  → redirect myeventlane_event_studio.workspace_settings

Staff Vendor Studio JSON (no current UI button)
  → POST myeventlane_vendor.console.studio.event_publish
  → VendorStudioController::publishEvent

Staff legacy wizard
  → myeventlane_event.wizard.publish
  → EventWizardPublishForm
```

---

## 7. Vendor Studio shell (staff-only path)

```
Vendor theme sidebar
  → myeventlane_vendor.console.studio                  [/vendor/studio]
  → [vendor] redirect myeventlane_event_studio.create or edit
  → [staff] VendorStudioController::buildStudioRenderArray
      → studio.html.twig [data-mel-studio]
      → vendor-studio.js (read event JSON on load)
      → card click → myeventlane_vendor.console.event_editor
  → myeventlane_vendor.console.event_editor            [/vendor/events/{event}/editor]
  → [vendor] redirect myeventlane_event_studio.edit
  → [staff] same studio shell
```

---

## 8. Help / support navigation

```
HelpContextResolver (event routes)
  → surface: event_workspace / event_wizard / vendor_dashboard

SupportActionBuilder
  → myeventlane_vendor.console.event_workspace
  → myeventlane_vendor.console.event_tickets
```

---

## Navigation inconsistency summary

| Entry point | Target today | Vendor effective destination |
|-------------|--------------|------------------------------|
| Event list "Manage" | `console.event_overview` | `workspace` (redirect) |
| Account "Create event" | `create_event_gateway` | `workspace` |
| Dashboard "Edit" | `event_studio.edit` | `workspace` |
| Vendor tabs / local tasks | `console.event_*` | Matching `workspace_*` (redirect) |
| Studio "Manage event" link | `console.event_workspace` | Redirect loop for vendors |
| Staff sidebar "Event Editor" | `console.studio` | Staff shell or vendor redirect |

---

## Phase 1D link-update candidates (documentation only)

| From | To | Rationale |
|------|-----|-----------|
| `node--event--vendor-card` Manage | `myeventlane_event_studio.workspace` | Skips redirect hop; same effective UX |
| Vendor tab definitions | Studio section routes | WP-5; requires parity review for orders/analytics |
| `EventStudioController` manage_event_url | `myeventlane_event_studio.workspace` | Avoid vendor redirect loop |

No link changes were made in Phase 1C (audit-only).
