# Vendor Permission Hardening — Phase 2A.2 Implementation

**Date:** 2026-07-21  
**Branch:** `fix/mel-vendor-access-and-create-flow`  
**Scope:** Launch blockers only — Critical RSVP Views leak, legacy export route hardening, check-in toggle IDOR bind  
**Evidence base:**

- `docs/audits/vendor-permission-inventory.md`
- `docs/audits/vendor-route-access-audit.md`
- `docs/audits/vendor-pii-exposure-audit.md`
- `docs/implementation/vendor-permission-hardening-phase2-plan.md`

**Out of scope (intentionally not done here):** Phase 2A.1–2A.4 Commerce/profile/messaging role strips, Phase 2B ownership consolidation, Stripe, Dashboard UX beyond RSVP view.

---

## 1. Executive summary

Phase 2A.2 closes three confirmed launch-risk paths:

1. **PII-07** — `/dashboard/rsvps` no longer lists other organisers’ attendee names.
2. **PII-09 / soft-gated exports** — checkout_paragraph attendee/export/queue routes and legacy `/dashboard/attendees/export` fail through Drupal `_custom_access` (403), not soft redirects alone.
3. **PII-08** — check-in toggle requires `attendee.event == route.event` before mutation; foreign IDs 403 with no state change and no existence leak.

---

## 2. Root cause (per issue)

| ID | Root cause |
|---|---|
| **PII-07** | View `myeventlane_vendor_rsvps` filtered only `status != cancelled`. Access plugin allowed any user with create/edit event. No organiser/event ownership scope. |
| **PII-09** | Routes used `_permission: access content`. Controllers soft-checked and redirected; `queueExport` had no access call. |
| **PII-08** | `CheckInController::toggle` validated route event ownership only. `CheckInStorage::toggleCheckIn` loaded attendee/RSVP by ID and mutated using *that* entity’s event. |

---

## 3. Design decisions

### Workstream A — RSVP Views

- **Canonical ownership:** `UserVendorMembershipQuery::getManagedEventNodeIds()` (author **or** `field_event_vendor` organiser owner/team) — same set as workspace parity / dashboard KPIs.
- **Views access plugin alone cannot enforce per-row ownership** → `RsvpOrganiserViewScope` applies SQL `event_id IN (…)`, or `1 = 0` when empty (fail closed).
- **Defence in depth:** Views filter `myeventlane_rsvp_organiser_owned` **and** `hook_views_query_alter` for `myeventlane_vendor_rsvps`.
- **Page gate** (`VendorAccess`) remains coarse organiser capability; row isolation is query-scoped. Empty state shown; attendee names of foreign events never enter the result set.
- Staff (`administer nodes` / `administer rsvps`) bypass organiser scope.

### Workstream B — Export routes

- Reuse existing `AttendeeExportController::access` / `VendorOrderController::access` (workspace parity) as `_custom_access`.
- Controllers throw `AccessDeniedHttpException` if reached without allow (no soft front redirect as sole gate).
- Legacy CSV: new `AttendeeCsvExportAccess` — download requires parity; missing/foreign events both forbidden (no disclosure).

### Workstream C — Check-in bind

- `attendeeBelongsToEvent(routeEvent, id, type)` before mutation; mismatch/missing → same 403.
- `toggleCheckIn` now requires the route `NodeInterface` and re-checks bind inside storage.
- Did **not** expand to full check-in route `_custom_access` parity (Phase 2B.3).

---

## 4. Files changed

### Workstream A

- `web/modules/custom/myeventlane_rsvp/src/Service/RsvpOrganiserViewScope.php` (new)
- `web/modules/custom/myeventlane_rsvp/src/Plugin/views/filter/OrganiserOwnedRsvps.php` (new)
- `web/modules/custom/myeventlane_rsvp/src/Plugin/views/access/VendorAccess.php`
- `web/modules/custom/myeventlane_rsvp/src/Entity/RsvpSubmissionViewsData.php`
- `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.services.yml`
- `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.module` — query alter
- `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.views.inc`
- `config/sync/views.view.myeventlane_vendor_rsvps.yml`
- `web/modules/custom/myeventlane_rsvp/config/install/views.view.myeventlane_vendor_rsvps.yml`
- Tests: `RsvpOrganiserViewScopeTest`, `VendorRsvpViewsAccessTest`

### Workstream B

- `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.routing.yml`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Controller/VendorOrderController.php`
- `web/modules/custom/myeventlane_views/src/Access/AttendeeCsvExportAccess.php` (new)
- `web/modules/custom/myeventlane_views/myeventlane_views.services.yml` (new)
- `web/modules/custom/myeventlane_views/myeventlane_views.routing.yml`
- `web/modules/custom/myeventlane_views/myeventlane_views.info.yml` — depends on `myeventlane_vendor`
- `web/modules/custom/myeventlane_views/src/Controller/AttendeeCsvController.php`
- Tests: `CheckoutParagraphExportRoutingSafetyTest`, `AttendeeExportAccessTest`, `AttendeeCsvExportAccessTest`, `LegacyAttendeeExportRoutingSafetyTest`

### Workstream C

- `web/modules/custom/myeventlane_checkin/src/Service/CheckInStorageInterface.php`
- `web/modules/custom/myeventlane_checkin/src/Service/CheckInStorage.php`
- `web/modules/custom/myeventlane_checkin/src/Controller/CheckInController.php`
- `web/modules/custom/myeventlane_checkin/myeventlane_checkin.services.yml`
- Tests: `CheckInAttendeeEventBindTest`, updated `CheckinRoutingSafetyTest`

---

## 5. Tests

| Area | Coverage |
|---|---|
| RSVP scope | Empty managed set → `1=0`; managed IDs → `event_id IN`; staff bypass; page access anonymous deny |
| Export routes | YAML asserts `_custom_access` (not `access content`); A allowed / B denied; non-event forbidden |
| Legacy CSV | Foreign/missing download forbidden; owned allowed; routing safety |
| Check-in | Belong/foreign/missing bind; toggle foreign throws; no repository call on foreign |

---

## 6. Manual QA (DDEV)

Accounts used for probes: organiser uid **2** (event 1594) vs uid **74** (event 1678) — distinct `field_event_vendor` entities, no shared parity. (Do not use same-workspace seed pairs such as 101/102.)

| Surface | Result (2026-07-21) |
|---|---|
| View execute as A | Only A-scoped RSVP rows (`NO_FOREIGN_ROWS`) |
| View execute as B | 0 rows; A name `CrossTenantA` absent |
| Export/queue route access B→A | **DENY** |
| Export/queue route access A→A | **ALLOW** |
| Legacy CSV download B→A / A→A | **DENY** / **ALLOW** |
| Bind A RSVP on A event / on B event | **Y** / **N** |
| VendorAccess plugin A/B | **Y** / **Y** (page capability) |

Note: `AccessManager::checkNamedRoute` for the Views page may return **neutral** because `VendorAccess::alterRouteDefinition()` does not attach a route requirement (existing Views plugin pattern). Views still enforces the access plugin on execute; query scope is authoritative for PII.

---

## 7. Security verification

- Cross-organiser isolation: query-level for RSVP list; route `_custom_access` + parity for exports; entity bind for toggle.
- GET does not mutate (toggle remains POST-only).
- POST toggle: ownership + CSRF (existing) + attendee∈event.
- 403 for foreign/missing without distinguishing existence in export/toggle paths.

---

## 8. Config impact

- **Only** `config/sync/views.view.myeventlane_vendor_rsvps.yml` (organiser_owned filter).
- No role YAML changes in this slice.
- Import: `ddev drush cim -y` (or partial import of that view) + `ddev drush cr`.

---

## 9. Launch impact

| Blocker | Status after 2A.2 |
|---|---|
| PII-07 RSVP Views | **Closed** |
| PII-09 soft export gates | **Closed** (hard access) |
| PII-08 check-in toggle bind | **Closed** |
| PII-01…PII-06 Commerce/profile/resend | **Still open** — Phase 2A.1–2A.4 |

---

## 10. Remaining Phase 2 work

From plan (not this PR slice):

- 2A.1 strip authenticated `view default commerce_order`
- 2A.2 strip vendor Commerce admin grants
- 2A.3 strip vendor profile/email grants
- 2A.4 messaging resend ownership
- 2A.7 fuller A/B kernel suite across Commerce surfaces
- 2B.* ownership consolidation, refund/attendee parity, check-in route `_custom_access`, order detail IDOR
- 2C.* long-term consolidation

---

## 11. PR review recommendation

1. Confirm View config import includes `organiser_owned` filter.
2. Confirm no unrelated role/config churn.
3. Run unit groups below; spot-check A/B in DDEV.
4. Do not merge Commerce permission strips in this PR — keep review focused on these three workstreams.
