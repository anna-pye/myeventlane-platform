# Task 12 — Vendor access parity hardening and launch-readiness sweep

**Branch:** `cursor/onboard-storage-fix-128b4`  
**Date:** 2026-04-29  
**Scope:** Access parity for vendor analytics, checkout-paragraph exports, order attendee views, and RSVP vendor aggregates. No Stripe, checkout payment logic, Event Studio, public theme, Help, config export, or secrets changes.

---

## Plan (before implementation)

- Introduce a single service matching `VendorConsoleBaseController::assertEventOwnership` for **node author + `field_event_vendor` → `field_vendor_users`**, with **fail closed**; callers add **admin/staff** and **route permission** rules.
- Reuse that service in Pro analytics per-event access, attendee CSV export access, and `VendorOrderController` (access + row filtering for mixed-owner orders).
- Add **team-aware** published-event RSVP aggregation for vendor KPIs; keep legacy `getVendorRsvpCount` for author-UID semantics.
- Verify with Drush `php-eval` (no PII in doc), `composer validate`, `drush cr`, `php -l`, secret grep (custom + docs: labels/redacted examples only), watchdog sample.

---

## Task 7 P1 findings addressed

| P1 (Task 7) | Resolution |
|-------------|------------|
| **1. `AnalyticsDashboardController::accessEvent` owner-only gap** | Per-event access now allows users with `access analytics dashboard` who pass **`EventVendorAccessChecker::accountHasWorkspaceParityForEvent`** (owner or vendor team). **`administer event attendees`** unchanged as staff bypass. |
| **2. `AttendeeExportController::access` owner-only gap** | Same parity checker; **`administer nodes`** unchanged. |
| **3. `VendorOrderController` mixed-owner order risk** | **`access()`** uses parity on events resolved per line item (**`field_target_event` preferred, else variation `field_event`**). **`collectRows()`** outputs holders only for lines the user may manage; non-admin rows without a resolvable event are skipped. Admins still see full order context. |
| **4. `RsvpStatsService::getVendorRsvpCount` author-only aggregate** | **`getVendorRsvpCount(int)`** kept with documented legacy semantics. **`getManagedPublishedEventsRsvpCount(int)`** added: published events owned by UID **or** linked to vendors from **`UserVendorMembershipQuery::getVendorIdsForUser`**. **`MetricsAggregator::getVendorKpis`** now uses the managed-events total for the RSVP KPI. |

---

## Routes / controllers / services changed

| Area | Change |
|------|--------|
| **Service** `myeventlane_vendor.event_access_checker` | New `EventVendorAccessChecker`. |
| **`AnalyticsDashboardController::accessEvent`** | Uses parity checker after permission gates. |
| **`AttendeeExportController::access`** | Uses parity checker; DI wired in `create()`. |
| **`VendorOrderController`** | Injects checker; `collectRows(Order, AccountInterface)` filters lines; `resolveEventFromOrderItem()` prefers `field_target_event`. |
| **`RsvpStatsService`** | Injects `UserVendorMembershipQuery`; new `getManagedPublishedEventsRsvpCount`. |
| **`MetricsAggregator`** | RSVP KPI calls `getManagedPublishedEventsRsvpCount`. |
| **`myeventlane_analytics.info.yml`** | Depends on `myeventlane_vendor`. |
| **`myeventlane_checkout_paragraph.info.yml`** | Depends on `myeventlane_vendor`. |

---

## Access rule summary

### Analytics (`accessEvent`)

**Before:** `administer event attendees` **or** (event **owner** and `access analytics dashboard`).  
**After:** Same staff bypass; **`access analytics dashboard`** plus **workspace parity** (owner **or** vendor team on `field_event_vendor`), aligned with `assertEventOwnership` membership checks.

### Attendee CSV export (`AttendeeExportController::access`)

**Before:** `administer nodes` **or** node owner.  
**After:** `administer nodes` **or** workspace parity.

### Order attendees (`VendorOrderController`)

**Before:** Access if **any** line’s variation `field_event` owner matched account; **`collectRows`** returned **all** holders.  
**After:** Access if admin **or** any line’s resolved event passes parity; **`collectRows`** only includes holders for lines that pass parity (or admin for full visibility). Internal route comment/docblock describes mixed-owner protection.

### RSVP vendor KPI aggregate

**Before:** KPI RSVP total used author-scoped `getVendorRsvpCount`.  
**After:** KPI uses **`getManagedPublishedEventsRsvpCount`** (owner + team-linked published events). **`getVendorRsvpCount`** retained unchanged for callers that need strict author-only totals.

---

## Mixed-owner order protection

- Each order item resolves an event node when possible; holder rows are emitted only when the account may manage that event (or user has `administer nodes`).
- Recent orders sampled via Drush showed **single event per line** in the spot check; no mixed-owner order reproduced locally. Behaviour is safe if multiple events appear on one order.

---

## Commands run

```bash
git branch --show-current
git status --short
git log -12 --oneline
composer validate
ddev drush cr
php -l web/modules/custom/myeventlane_vendor/src/Service/EventVendorAccessChecker.php
php -l web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php
php -l web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php
php -l web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php
php -l web/modules/custom/myeventlane_checkout_paragraph/src/Controller/VendorOrderController.php
ddev drush php-eval "echo \Drupal::service('myeventlane_vendor.event_access_checker') instanceof \Drupal\myeventlane_vendor\Service\EventVendorAccessChecker ? 'ok' : 'fail';"
```

Drush event/order **`php-eval`** snippets from the task (nid/owner/vendor refs and order/item/event IDs only) were executed for local verification.

```bash
grep -R "sk_test_\|sk_live_\|whsec_\|rk_live_\|pk_live_" -n web/modules/custom docs --exclude-dir=vendor  # labels/redacted docs only
ddev drush ws --count=120 | grep -Ei "error|exception|fatal|access denied|forbidden" || true
```

**PHPUnit:** Direct `ddev exec ./vendor/bin/phpunit …` failed (`Drupal\KernelTests\KernelTestBase` not found — project expects bootstrap/configured runner). No new automated tests added (narrow scope; existing kernel suite needs standard runner).

---

## Manual / browser checks

**Pending** (recommended):

1. Vendor owner: `/vendor/dashboard`, `/vendor/analytics/event/{nid}`, `/vendor/export-attendees/{nid}/download`.
2. Vendor team user listed on `field_vendor_users`: same routes for their vendor’s events.
3. Another vendor: denied or empty safe state on deep links.
4. Staff with `administer event attendees` / `administer nodes`: oversight as intended.
5. Anonymous: vendor routes blocked as before.
6. Mixed-owner order (if reproducible): only accessible events’ rows visible.

---

## Launch-readiness sweep (post-change)

| Check | Result |
|-------|--------|
| Working tree | Modified files listed below only (before commit). |
| Secrets in repo (custom + docs) | No live keys; schema/UI strings reference `whsec_…` patterns only. |
| Full Stripe keys in docs | Audit docs describe prefixes/redaction only. |
| Config export | Not run for this task. |
| PHP syntax | All changed PHP files pass `php -l`. |
| Cache rebuild | `ddev drush cr` success. |
| Test NIDs | Paid **1567**, RSVP **1540** remain referenced in prior audits (not altered here). |
| Watchdog (sample) | Informational/errors unrelated to this change set (commerce/ticket map noise). |

---

## Remaining risk classification

| Severity | Items |
|----------|--------|
| **P0** | None identified in this pass. |
| **P1** | Browser matrix above **pending**; broader route audit (`AttendeeCsvController`, check-in) remains out of scope unless escalated. |
| **P2** | Task 7 dual RSVP URLs; CSV Views performance; copy/UI polish. |

---

## Recommended next task

- Execute the **manual/browser matrix** above and optionally add `_custom_access` on checkout_paragraph routes mirroring controller checks for defense-in-depth.
- **Task 13** — not started per brief.

---

## Files touched

**Added**

- `web/modules/custom/myeventlane_vendor/src/Service/EventVendorAccessChecker.php`
- `docs/audits/mel-vendor-access-parity-launch-readiness.md`

**Modified**

- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml`
- `web/modules/custom/myeventlane_vendor/src/Service/RsvpStatsService.php`
- `web/modules/custom/myeventlane_vendor/src/Service/MetricsAggregator.php`
- `web/modules/custom/myeventlane_analytics/myeventlane_analytics.info.yml`
- `web/modules/custom/myeventlane_analytics/src/Controller/AnalyticsDashboardController.php`
- `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.info.yml`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Controller/AttendeeExportController.php`
- `web/modules/custom/myeventlane_checkout_paragraph/src/Controller/VendorOrderController.php`

---

## Suggested commit message

```
fix(vendor): align dashboard analytics and attendee export access
```
