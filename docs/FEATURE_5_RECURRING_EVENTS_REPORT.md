# Feature 5 — Recurring Events (Series Model)

**Date:** 2026-02-02  
**Status:** Complete

---

## Phase A — Foundations Confirmed

- rrule.js: `web/libraries/rrule/2.6.8/rrule.min.js` present ✅
- Event date fields: `field_event_start`, `field_event_end` in myeventlane_schema ✅
- VendorEventRsvpController: `$series = []` placeholder (no existing series logic) ✅
- No existing recurrence fields or entities ✅

---

## Phase B — Design by Extension

- **Option A: Series Template Event** — Add fields to existing Event node (no new entity)
- **Fields:** `field_is_series_template`, `field_series_rrule`, `field_series_timezone`, `field_series_parent`, `field_series_instance_id`
- **Generator:** EventRecurrenceGenerator — reads RRULE, creates child event nodes
- **Trigger:** Manual (Generate instances button); cron/Drush optional later

---

## Phase C — Implementation

### C1. Field + Schema Setup

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_schema/config/install/field.storage.node.field_*.yml` | New — field_is_series_template, field_series_rrule, field_series_timezone, field_series_parent, field_series_instance_id |
| `web/modules/custom/myeventlane_schema/config/install/field.field.node.event.field_*.yml` | New — field instances on Event bundle |
| `web/modules/custom/myeventlane_schema/myeventlane_schema.install` | Added myeventlane_schema_update_9005(), update_9006() for existing sites |

### C2. Series Generator Service

| File | Change |
|------|--------|
| `composer.json` | Added rlanvin/php-rrule:^2.0 |
| `web/modules/custom/myeventlane_event/src/Service/EventRecurrenceGenerator.php` | New — parses RRULE, creates/updates child events |
| `web/modules/custom/myeventlane_event/myeventlane_event.services.yml` | Added myeventlane_event.recurrence_generator |

### C3. Manual Trigger

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_event/src/Form/GenerateSeriesInstancesForm.php` | New — confirmation form to trigger generator |
| `web/modules/custom/myeventlane_event/myeventlane_event.routing.yml` | Added myeventlane_event.generate_series_instances route |

### C4. Vendor UX

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_vendor/src/Controller/ManageSeriesInstancesController.php` | New — lists child instances, links to Generate button |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml` | Added myeventlane_vendor.manage_event.series route |
| `web/modules/custom/myeventlane_vendor/src/Service/ManageEventNavigation.php` | Conditional "Series" step when event is series template |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.module` | Added myeventlane_manage_series_instances_content theme hook |
| `web/modules/custom/myeventlane_vendor/templates/myeventlane-manage-series-instances-content.html.twig` | New — Generate button + instance table |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` | Added is_series_template, series_url to event data |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/event-table.html.twig` | Series badge, Manage instances link |
| `web/themes/custom/myeventlane_vendor_theme/templates/components/vendor-event-performance.html.twig` | Series badge |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/components/_badges.scss` | Added .mel-badge--series |

---

## Phase D — Verification & Safety Checks

### Manual Verification Checklist

1. [ ] Run `ddev drush updatedb -y` (myeventlane_schema updates 9005, 9006)
2. [ ] Create/edit an Event; set field_is_series_template = 1
3. [ ] Set field_event_start, field_event_end, field_series_rrule (e.g. `FREQ=WEEKLY;COUNT=4`)
4. [ ] Save event; navigate to vendor event management → Series
5. [ ] Click "Generate instances" → confirm; verify child events created
6. [ ] Confirm child events have field_series_parent, field_series_instance_id
7. [ ] Confirm Series badge appears on vendor dashboard for series templates
8. [ ] Confirm "Manage instances" in actions dropdown for series templates
9. [ ] Single-instance events: no Series step; no badge; unchanged behavior

### Failure Scenarios

- **Invalid RRULE:** EventRecurrenceGenerator logs error; no instances created
- **Missing DTSTART (field_event_start):** Generator skips or fails gracefully
- **Non-vendor access:** ManageEventControllerBase enforces vendor ownership

### Security/Access Validation

- ManageSeriesInstancesController extends ManageEventControllerBase — vendor ownership enforced
- GenerateSeriesInstancesForm — same access as event edit
- Child events inherit vendor from template; no new access paths

### Existing Flows Unchanged

- Single-instance event flow — not touched
- OrderCompletedSubscriber — not touched
- TicketIssuer, EventAttendee — not touched
- Checkout, capacity enforcement — not touched

---

## Phase E — Feature Lock Report

### Files Modified

- myeventlane_schema (install config, update hooks)
- myeventlane_event (EventRecurrenceGenerator, GenerateSeriesInstancesForm, routing)
- myeventlane_vendor (ManageSeriesInstancesController, ManageEventNavigation, VendorDashboardController, routing, module, template)
- myeventlane_vendor_theme (event-table, vendor-event-performance, badges)
- composer.json (rlanvin/php-rrule)

### Services Extended

- None

### New Components Added

- EventRecurrenceGenerator (myeventlane_event.recurrence_generator)
- GenerateSeriesInstancesForm
- ManageSeriesInstancesController
- Routes: myeventlane_event.generate_series_instances, myeventlane_vendor.manage_event.series
- Theme hook: myeventlane_manage_series_instances_content
- Template: myeventlane-manage-series-instances-content.html.twig
- Series fields: field_is_series_template, field_series_rrule, field_series_timezone, field_series_parent, field_series_instance_id

### Explicit Confirmation

- **No duplicated logic:** Reused Event node; rrule via rlanvin/php-rrule (server-side)
- **No refactors:** Additive only; single-instance events unchanged
- **No unrelated changes:** Isolated to series-specific code paths

### Note — Future Extensions

- Cron/Drush trigger for automatic instance generation (optional)
- Series-aware capacity (per-instance vs series-total) — isolated extension
- Views filters for series vs instance — extend existing event views

---

*Feature 5 complete. All five features (1–5) implemented.*
