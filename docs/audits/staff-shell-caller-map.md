# Staff Shell Caller Map — Phase 1E

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** All references to legacy Vendor Studio shell routes and controllers under `web/`.

---

## `myeventlane_vendor.console.studio` (`/vendor/studio`)

| Caller type | Location | Usage | Post-1E action |
|-------------|----------|-------|----------------|
| Route definition | `myeventlane_vendor.routing.yml:322-330` | Route registration | Keep; redirect-only controller |
| Controller | `VendorStudioController::studio()` | Vendor redirect + staff shell | Replace with redirect-only |
| Theme nav | `myeventlane_vendor_theme.theme:2197-2202` | Sidebar item "Event Editor" → this route | Update route target to `myeventlane_event_studio.create` |
| Active section map | `myeventlane_vendor_theme.theme:2070, 2009-2010` | Path prefix `/vendor/studio` → `event_editor` section | Keep for redirect route UX |
| Node preprocess | `myeventlane_vendor.module:343-355` | Desk overview teaser URL override on studio route | Remove (shell retired) |
| Comment | `myeventlane_vendor.routing.yml:317` | Dashboard theme pattern reference | Keep comment |

**No** menu links, local tasks, View models, or JS hard-coded paths beyond theme nav.

---

## `myeventlane_vendor.console.event_editor` (`/vendor/events/{event}/editor`)

| Caller type | Location | Usage | Post-1E action |
|-------------|----------|-------|----------------|
| Route definition | `myeventlane_vendor.routing.yml:332-346` | Route registration | Keep; redirect-only |
| Controller | `VendorStudioController::eventEditor()` | Vendor redirect + staff shell | Replace with redirect-only |
| Legacy redirect subscriber | `VendorLegacyWizardRedirectSubscriber.php:58, 207` | Vendor → `workspace_information` | Remove from list (route redirects at source) |
| Onboarding gate | `EventStudioVendorOnboardingGateSubscriber.php:69` | Treat as Event Studio gate route | Keep |
| Notifications | `BusinessNotificationTriggerService.php:311` | "Open event" link on approval | Update → `myeventlane_event_studio.workspace` |
| Boost | `BoostController.php:358` | Edit URL in vendor workspace boost flow | Update → `myeventlane_event_studio.edit` |
| Help centre | `SupportActionBuilder.php:229` | "Finish your event" draft action | Update → `myeventlane_event_studio.edit` |
| Active section map | `myeventlane_vendor_theme.theme:2069` | Maps to `event_editor` nav section | Keep |
| Internal (removed with controller) | `VendorStudioController::buildEventCards()` | `edit_url`, `studio_link` | Removed with controller |

---

## `myeventlane_vendor.console.studio.event_data` (`/vendor/studio/event/{event}/data`)

| Caller type | Location | Usage | Post-1E action |
|-------------|----------|-------|----------------|
| Route definition | `myeventlane_vendor.routing.yml:356-369` | GET JSON endpoint | **Remove** |
| Controller | `VendorStudioController::eventData()` | Returns `buildEventPayload()` | **Remove** |
| JS | `vendor-studio.js` `fetchEventData()` | GET on card click | **Remove with JS** |
| PHP endpoint builder | `VendorStudioController::buildStudioEndpoints()` | `overview_read` URL | **Remove with controller** |
| Active section map | `myeventlane_vendor_theme.theme:2071` | Nav section mapping | Remove mapping entry |

**No** Twig, menu, or PHP callers outside removed controller/JS.

---

## `myeventlane_vendor.studio_event_schema` (`/vendor/studio/schema/event`)

| Caller type | Location | Usage | Post-1E action |
|-------------|----------|-------|----------------|
| Route definition | `myeventlane_vendor.routing.yml:348-354` | GET schema JSON | **Remove** |
| Controller | `VendorStudioSchemaController::eventSchema()` | Field schema for dynamic forms | **Remove** |
| Active section map | `myeventlane_vendor_theme.theme:2072` | Nav section mapping | Remove mapping entry |

**No** callers in current `vendor-studio.js` (Phase 1D removed `loadEventSchema`).

---

## `VendorStudioController`

| Reference | Location | Post-1E |
|-----------|----------|---------|
| Route controllers | `myeventlane_vendor.routing.yml` | → `VendorStudioRedirectController` |
| Shell render | `#theme => 'studio'` | **Deleted** |
| Library attach | `vendor-workspace` on shell | **Deleted** |

---

## `VendorStudioSchemaController`

| Reference | Location | Post-1E |
|-----------|----------|---------|
| Route only | `myeventlane_vendor.routing.yml:348-354` | Controller deleted |

---

## Templates and JS

| Asset | Callers | Post-1E |
|-------|---------|---------|
| `studio/studio.html.twig` | `VendorStudioController` only | Delete |
| `components/vendor-event-card.html.twig` | `studio/studio.html.twig:15` | Delete |
| `myeventlane-vendor-studio.html.twig` | Theme hook only; no PHP `#theme` | Delete |
| `myeventlane-studio-inspector.html.twig` | Theme hook only; no PHP `#theme` | Delete |
| `vendor-studio.js` | `vendor-workspace` library | Delete; keep `workspace.css` |

---

## Subscriber rules (Phase 1E cleanup)

| Rule | Still needed? | Reason |
|------|---------------|--------|
| `event_editor` in `VENDOR_LEGACY_OPERATION_ROUTES` | **No** | Route controller redirects all users to Event Studio edit before shell render |
| `console.studio` in subscriber | **Never was listed** | Controller handled redirects; redirect-only controller continues that |

Other `VENDOR_LEGACY_OPERATION_ROUTES` entries (mission control, wizard, tickets, etc.) remain required for vendor operational URL consolidation.
