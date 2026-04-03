# MEL Event Studio — internal architecture (vendor events)

## Canonical vendor routes

- **Create:** `myeventlane_event_studio.create`
- **Edit:** `myeventlane_event_studio.edit` (parameter: `node`)

Vendor console may route through aliases (e.g. `myeventlane_vendor.console.event_editor`); user-facing persistence for core event fields from the Studio UI is still the Studio module.

## Save orchestrator

- **`EventStudioSaveService`** (`myeventlane_event_studio.save`): single writer for vendor-originated event saves from the **Event Studio form** (title, schedule, `field_location`, lat/lng, `field_venue`, publish on non-draft save).
- **Vendor Studio shell** Overview JSON updates title/summary/type via **`EventStudioSaveService::patchOverviewBasics()`** (not duplicate inline `save()` logic).
- **Bulk publish/unpublish** on the vendor events list uses **`EventStudioSaveService::setNodePublishedState()`**.
- Other Studio JSON tabs (tickets, settings, etc.) still save via `VendorStudioController` + `saveStudioEventRevision()` — technical debt; they must not touch `field_location` (location is Studio save only).

## Location ownership

- **`field_location`** (and optional **`field_venue`** + coordinates on the event) are written for vendor flows through **`EventStudioSaveService::save()`** only.
- **`myeventlane_location`** Drush geocoding uses the same service when present (see `MyeventlaneLocationCommands`).
- Venue module: lookup/create only; no direct event node location mutation in vendor UX.

## ID normalization

- Service **`myeventlane_core.entity_id_normalizer`** (`EntityIdNormalizer`): use before any `loadMultiple()` when IDs may be mixed (query results, `target_id` rows, strings).
- Legacy static **`EntityLoadIds::normalizeForLoadMultiple()`** delegates to the same logic (prefer injecting the service in new code).

## Legacy paths (allowed)

- **`myeventlane_event.wizard.*`** step routes: remain for **staff** (`administer nodes` or uid 1); vendors are redirected to Studio via **`VendorLegacyWizardRedirectSubscriber`**.
- **`entity.node.add_form` / `entity.node.edit_form`** for `event`: not linked from vendor surfaces; vendor theme attaches node-form bridge JS **only** for staff.

## Modules / services (quick map)

| Area | Module / service |
|------|------------------|
| Studio UI + form | `myeventlane_event_studio` |
| Save orchestrator | `myeventlane_event_studio.save` |
| ID normalization | `myeventlane_core.entity_id_normalizer` |
| Vendor shell / dashboard | `myeventlane_vendor`, `myeventlane_vendor_theme` |
| Domain projections | `myeventlane_domain_events` (read models + queue projectors) |
