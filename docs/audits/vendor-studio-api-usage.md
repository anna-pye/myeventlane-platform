# Vendor Studio API Usage Audit — Phase 1C

**Repository:** `/Users/anna/myeventlane`  
**Date:** 2026-06-13  
**Scope:** `/vendor/studio/*` JSON write/read API and related shell routes. Audit-only; no routes/controllers removed in Phase 1C.

**Evidence method:** Static grep across `web/` (PHP, Twig, JS, YAML, libraries). No runtime traffic analysis in repository.

---

## Executive summary

| Finding | Evidence |
|---------|----------|
| Vendor Studio JSON POST endpoints are **wired in PHP + JS** but **lack user-facing save UI** in the current staff shell template | `studio/studio.html.twig` has no `data-mel-tab-save`, `data-mel-overview-save`, `data-mel-publish-event`, or `data-mel-dynamic-save` elements; `vendor-studio.js` only POSTs when those exist |
| `event_data` GET may run on **staff** `/vendor/studio` page load | `vendor-studio.js:1050-1051` calls `fetchEventData` for initial card; cards also set `data-studio-link` causing navigation instead of fetch on click (`vendor-studio.js:997-1000`) |
| **Vendors never reach** the staff JSON shell | `VendorStudioController::studio()` and `::eventEditor()` redirect non-`administer nodes` users to `myeventlane_event_studio.create` / `edit` (`VendorStudioController.php:92-114, 150-152`) |
| **Canonical vendor writes** use Event Studio autosave + publish | `EventStudioController::workspace` sets `autosaveUrl` + `publishUrl` in `drupalSettings` (`EventStudioController.php:310-325`); `mel-event-studio-shell.js` POSTs to those routes |
| Vendor Studio POST handlers duplicate/overlap Event Studio persistence | `saveOverview` calls `EventStudioSaveService::patchOverviewBasics`; other saves write JSON config fields directly on the node |

---

## Route inventory

| Route | Path | Controller | JS caller | Twig caller | PHP caller | Active? |
|-------|------|------------|-----------|-------------|------------|---------|
| `myeventlane_vendor.console.studio` | `/vendor/studio` | `VendorStudioController::studio` | `vendor-studio.js` behavior attaches on `[data-mel-studio]` only | `studio/studio.html.twig` (via `#theme` => `studio`) | Theme nav: `myeventlane_vendor_theme.theme:2202`; module preprocess `myeventlane_vendor.module:343` | **ALIAS** for vendors (302 → Event Studio); **ACTIVE** staff shell entry |
| `myeventlane_vendor.console.event_editor` | `/vendor/events/{event}/editor` | `VendorStudioController::eventEditor` | Same as above when staff lands with active event | — | `buildEventCards()` sets `edit_url`, `studio_link` (`VendorStudioController.php:1057-1058`) | **ALIAS** for vendors; **ACTIVE** staff entry |
| `myeventlane_vendor.studio_event_schema` | `/vendor/studio/schema/event` | `VendorStudioSchemaController::eventSchema` | `vendor-studio.js:475` (`loadEventSchema`) — only if `[data-mel-dynamic-save]` present | — | — | **UNUSED** (no `[data-mel-dynamic-save]` in any Twig template) |
| `myeventlane_vendor.console.studio.event_data` | `/vendor/studio/event/{event}/data` | `VendorStudioController::eventData` | `vendor-studio.js:812` (`fetchEventData` GET) | — | `buildStudioEndpoints()` / event cards `data-read-url` (`VendorStudioController.php:1162, 1059-1060`) | **LEGACY** — staff shell initial load only; no vendor UI |
| `myeventlane_vendor.studio_event_save` | `/vendor/studio/event/{event}/save` | `VendorStudioController::saveEvent` | `vendor-studio.js:644-648` (`saveEvent`) — only if `[data-mel-dynamic-save]` clicked | — | Endpoint default in JS | **UNUSED** (no dynamic-save button in current template) |
| `myeventlane_vendor.console.studio.event_overview_save` | `/vendor/studio/event/{event}/overview` | `VendorStudioController::saveOverview` | `vendor-studio.js:857` (`saveOverview`) — requires `[data-mel-overview-save]` | — | `buildStudioEndpoints()` (`VendorStudioController.php:1163`) | **UNUSED** (no overview save button/inputs in `studio.html.twig`) |
| `myeventlane_vendor.console.studio.event_tickets_save` | `/vendor/studio/event/{event}/tickets` | `VendorStudioController::saveTickets` | `vendor-studio.js:940-946` (`postTabAction`) — requires `[data-mel-tab-save]` | — | `buildStudioEndpoints()` (`VendorStudioController.php:1164`) | **UNUSED** (no tab save UI) |
| `myeventlane_vendor.console.studio.event_attendees_save` | `/vendor/studio/event/{event}/attendees` | `VendorStudioController::saveAttendees` | Same (`postTabAction`) | — | `buildStudioEndpoints()` (`VendorStudioController.php:1165`) | **UNUSED** |
| `myeventlane_vendor.console.studio.event_promotion_save` | `/vendor/studio/event/{event}/promotion` | `VendorStudioController::savePromotion` | Same (`postTabAction`) | — | `buildStudioEndpoints()` (`VendorStudioController.php:1166`) | **UNUSED** |
| `myeventlane_vendor.console.studio.event_settings_save` | `/vendor/studio/event/{event}/settings` | `VendorStudioController::saveSettings` | Same (`postTabAction`) | — | `buildStudioEndpoints()` (`VendorStudioController.php:1167`) | **UNUSED** |
| `myeventlane_vendor.console.studio.event_publish` | `/vendor/studio/event/{event}/publish` | `VendorStudioController::publishEvent` | `vendor-studio.js:951-958` — requires `[data-mel-publish-event]` | — | `buildStudioEndpoints()` (`VendorStudioController.php:1168`) | **UNUSED** (superseded by `myeventlane_event_studio.publish` for vendors) |
| `myeventlane_vendor.console.studio.submit_review` | `/vendor/studio/event/{event}/submit-review` | `VendorStudioController::submitReview` | — | — | — | **UNUSED** (no form/link POSTing to this route in `web/`) |

---

## Status definitions (Task 4)

| Status | Meaning in this audit |
|--------|------------------------|
| **ACTIVE** | Reachable in normal vendor or staff workflow with repository-confirmed UI or redirect chain |
| **LEGACY** | Implemented and referenced in code, but superseded by Event Studio or staff-only with reduced UI |
| **ALIAS** | Route exists primarily to redirect to canonical Event Studio routes |
| **UNUSED** | No repository-confirmed Twig/menu/link/form caller; JS caller exists but UI hooks absent |
| **UNKNOWN** | Cannot classify from repository (none remain after this audit) |

---

## Library and JS attachment chain

| Asset | Attached where | Calls Vendor Studio API? |
|-------|----------------|--------------------------|
| `myeventlane_vendor_theme/vendor-workspace` | Globally via `myeventlane_vendor_theme.info.yml:10`; explicitly in `VendorStudioController::buildStudioRenderArray()` (`VendorStudioController.php:177-179`) | **Conditional** — `vendor-studio.js` only activates on `[data-mel-studio]` |
| `vendor-studio.js` | `myeventlane_vendor_theme.libraries.yml:85` | Defines fetch/save handlers for all `/vendor/studio/event/*` endpoints |
| `mel-event-studio-shell.js` | Event Studio workspace (`EventStudioController::workspace`) | Uses **`myeventlane_event_studio.autosave`** and **`myeventlane_event_studio.publish`** — not Vendor Studio JSON API |
| `mel-publish-panel.js` | `myeventlane_vendor.libraries.yml` (`mel_publish_panel`) | Client-side checklist only; **does not POST** to Vendor Studio routes |

---

## PHP internal references (non-UI)

| File | Reference |
|------|-----------|
| `VendorStudioController.php:1160-1168` | `buildStudioEndpoints()` — builds URL map embedded in JSON payload and event cards |
| `myeventlane_vendor_theme.theme:2070-2073, 2202` | Route → sidebar section mapping includes studio routes |
| `HelpContextResolver.php:229` | Dynamic help context for routes starting with `myeventlane_event_studio.` (not wizard/studio API directly) |

---

## Overlap with Event Studio (canonical)

| Vendor Studio endpoint | Event Studio equivalent | Notes |
|------------------------|-------------------------|-------|
| `event_overview_save` / generic `save` | Form submit + `EventStudioSaveService::save()` / `patchOverviewBasics()` | Overview save reuses same service (`VendorStudioController.php:442`) |
| `event_tickets_save` | `workspace_tickets` → `EventStudioTicketsForm` + operational forms | Studio uses Form API + Commerce services, not `field_ticket_config` JSON blob alone |
| `event_attendees_save` | `workspace_questions` → `EventCheckoutQuestionsForm` | Different field model (paragraph questions vs JSON config) |
| `event_promotion_save` | `workspace_messaging` → `MessagingForm` | Studio form-based |
| `event_settings_save` | `workspace_settings` → `EventSettingsForm` | Studio publish/readiness in shell |
| `event_publish` | `myeventlane_event_studio.publish` POST | Studio uses `EventStudioPublishController`; Vendor Studio sets `moderation_state=review` only |
| Autosave (none on Vendor Studio API) | `myeventlane_event_studio.autosave` POST | **Canonical** draft path for workspace sections |

---

## Dead endpoint candidates (Phase 1D input)

**High confidence UNUSED (no UI caller):**

- `myeventlane_vendor.studio_event_save`
- `myeventlane_vendor.console.studio.event_overview_save`
- `myeventlane_vendor.console.studio.event_tickets_save`
- `myeventlane_vendor.console.studio.event_attendees_save`
- `myeventlane_vendor.console.studio.event_promotion_save`
- `myeventlane_vendor.console.studio.event_settings_save`
- `myeventlane_vendor.console.studio.event_publish`
- `myeventlane_vendor.console.studio.submit_review`
- `myeventlane_vendor.studio_event_schema`

**Retain until staff shell decision:**

- `myeventlane_vendor.console.studio.event_data` — still fetched on staff shell load
- `myeventlane_vendor.console.studio` / `event_editor` — staff shell + vendor redirect alias

**Risk before removal:** `vendor-studio.js` is loaded globally (vendor theme `info.yml`); removing routes without removing JS could cause console errors if legacy UI is reintroduced. Confirm staff workflow with product before WP-4.

---

## Validation grep (Phase 1C)

```bash
rg "/vendor/studio/event" web/
rg "VendorStudioController" web/
rg "myeventlane_vendor\.console\.studio" web/
rg "vendor-studio\.js" web/
```
