# Staff Shell Retirement Audit — Phase 1E

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** Legacy Vendor Studio staff shell at `/vendor/studio` and related infrastructure. Evidence from routing YAML, controllers, Twig, JS, and caller grep in `web/`.

**Prior phases:** Phase 1C/1D confirmed vendors do not use Vendor Studio JSON APIs; POST routes removed in 1D. Phase 1E retires the remaining staff render shell.

---

## Inventory

| Item | Purpose | Still used? | Evidence |
|------|---------|-------------|----------|
| `VendorStudioController` | Staff shell render + vendor/staff redirects; `eventData` GET | **Staff shell only (pre-1E)** | `myeventlane_vendor.routing.yml:322-369`; `VendorStudioController.php` — vendors redirected at lines 76-96, 132-135; staff calls `buildStudioRenderArray()` at line 137 |
| `VendorStudioSchemaController` | JSON schema for dynamic Studio forms | **No** | Route `myeventlane_vendor.studio_event_schema`; `loadEventSchema` removed from `vendor-studio.js` in Phase 1D; no `[data-mel-dynamic-save]` in any Twig under `web/` |
| `studio/studio.html.twig` | Staff MEL Event Editor shell (`[data-mel-studio]`) | **Staff only** | `#theme => 'studio'` in `VendorStudioController::buildStudioRenderArray()` only |
| `vendor-studio.js` | Fetch event JSON, metrics/checklist on staff shell | **Staff shell only** | Attached via `vendor-workspace` library globally (`myeventlane_vendor_theme.info.yml:10`) but behavior attaches only on `[data-mel-studio]` (`vendor-studio.js:Drupal.behaviors.melVendorStudio`) — present only in `studio/studio.html.twig` |
| `myeventlane_vendor.console.studio.event_data` | GET JSON payload for staff card selection | **Staff shell only** | Built in `VendorStudioController::buildStudioEndpoints()`; consumed by `vendor-studio.js` `fetchEventData()` only |
| `myeventlane_vendor.console.studio` | `/vendor/studio` entry | **Alias + staff shell** | Vendors: 302 → Event Studio create/edit (`VendorStudioController::studio()`); staff: rendered shell |
| `myeventlane_vendor.console.event_editor` | `/vendor/events/{event}/editor` | **Alias + staff shell** | Vendors: 302 → `myeventlane_event_studio.edit`; staff: rendered shell |
| `myeventlane_vendor.studio_event_schema` | Schema GET for dynamic forms | **Unused** | No JS caller after Phase 1D trim |
| `vendor-workspace` library | `workspace.css` + `vendor-studio.js` | **Partial** | CSS: global vendor theme (`info.yml`); JS: staff shell only |
| `myeventlane_vendor_studio` theme hook | Alternate studio template registration | **Unused** | Registered in `myeventlane_vendor.module:156-164`; no `#theme => 'myeventlane_vendor_studio'` in `web/` PHP |
| `myeventlane_studio_inspector` theme hook | Inspector panel template | **Unused** | Registered in `myeventlane_vendor.module:166-181`; no render caller in `web/` |
| `components/vendor-event-card.html.twig` | Staff studio navigator cards | **Staff shell only** | Included from `studio/studio.html.twig:15` only |

---

## Classification (Phase 1E decision)

| Item | Status | Action |
|------|--------|--------|
| `VendorStudioController` (shell + eventData) | **Staff-only → replaced** | Replace with redirect-only controller; delete shell methods |
| `VendorStudioSchemaController` | **Unused** | Remove controller + route |
| `studio.html.twig`, `vendor-event-card.html.twig`, `myeventlane-vendor-studio.html.twig`, `myeventlane-studio-inspector.html.twig` | **Unused after shell removal** | Delete templates |
| `vendor-studio.js` | **Unused after shell removal** | Delete; remove from `vendor-workspace` library |
| `event_data` route | **Unused after shell removal** | Remove route + `eventData()` method |
| `studio_event_schema` route | **Unused** | Remove route + controller |
| `console.studio` route | **Alias** | Keep as 302 → Event Studio create/edit |
| `console.event_editor` route | **Alias** | Keep as 302 → Event Studio edit |

---

## Safety checks (unchanged)

Not modified in Phase 1E:

- `EventStudioAutosaveController`, `EventStudioPublishController`, `EventStudioSaveService`
- Readiness/governance services, Commerce, orders, tickets, RSVP, permissions, access callbacks, moderation

---

## Evidence commands

```bash
rg "VendorStudioController|VendorStudioSchemaController" web/
rg "/vendor/studio" web/
rg "data-mel-studio" web/
rg "#theme.*studio" web/modules/custom/myeventlane_vendor/
```
