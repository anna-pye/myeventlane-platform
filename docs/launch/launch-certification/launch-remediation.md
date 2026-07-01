# Launch Remediation — Final Programme

**Date:** 2026-06-26 · **Environment:** DDEV live (authenticated Pro + non-Pro probes).
**Branch:** `fix/mel-p1-financial-accuracy-checkout-copy` (carries prior P1 work + this programme).
**Inputs (authoritative, not reopened):** `docs/launch/customer-verification/`,
`customer-acceptance/`, `organiser-acceptance/`.

Every remaining verified item ends in one of: **PASS · IMPLEMENTED · RISK ACCEPTED · DEFERRED.**

## Phase 1 — Verified P1 issues

### OB-1 — Event Insights 500 → **IMPLEMENTED**
Three distinct root causes, each fixed with a minimal, isolated change:

1. **FQCN access callback on a DI-less base controller.** Routes used
   `_custom_access: '\Drupal\…\EventInsightsController::access'`. `VendorConsoleBaseController`
   does not implement `ContainerInjectionInterface`, so Drupal's `ClassResolver` instantiated the
   controller with `new …()` → `ArgumentCountError` (0 args) **during the access check** → 500.
   **Fix:** point `_custom_access` at the working service (`myeventlane_reporting.event_insights:access`),
   applied to all affected reporting routes (event_insights ×5, chart_data ×4, export_centre ×2).
   *File:* `myeventlane_reporting.routing.yml`.
2. **Permissions admin-only.** `view event insights`, `view vendor insights`, `request exports`
   were granted only to `administrator`, so Pro organisers got 403 on their own Pro-gated insights.
   **Fix:** grant the three permissions to the `mel_pro` role (config), exported to sync.
   *File:* `config/sync/user.role.mel_pro.yml`.
3. **Attendees tab called a non-existent method.** `attendees()` / `buildAttendeeKpis()` called
   `$attendee->getSource()` on `AttendeeInterface` DTOs that have no such method → 500.
   **Fix:** derive the source bucket from the concrete DTO type (`instanceof TicketAttendee`),
   matching the existing attendee model. *File:* `EventInsightsController.php`.

**Result:** all five tabs (overview, sales, attendees, check-ins, traffic) + `/vendor/insights`
+ `/vendor/exports` return **HTTP 200** for a Pro organiser; admin unaffected.

### OB-2 — "Message Attendees 404" → **PASS (false positive corrected)**
Investigation showed the earlier finding was a **probe of a legacy plural URL**
(`/vendor/events/{id}/comms`) that was migrated during Studio consolidation. The feature is live
and Studio-integrated:
- `/vendor/events/{node}/studio/messaging` → **HTTP 200** (registered `MessagingSection` plugin + `MessagingForm`, linked in Studio nav).
- `/vendor/event/{event}/comms` (singular) → 302 redirects into Studio.
Nothing in the UI links the dead plural path. **No code change required.** The `organiser-acceptance`
OD-2 finding is corrected.

### OB-3 — Pro upgrade experience → **IMPLEMENTED**
New exception subscriber converts Pro-gated denials into a conversion opportunity **without
touching the global 403 page**:
- *File:* `web/modules/custom/myeventlane_pro/src/EventSubscriber/ProUpgradeRedirectSubscriber.php`
  (+ service registration in `myeventlane_pro.services.yml`).
- **Scope guard:** acts **only** when the route carries the `_myeventlane_pro_access` requirement
  **and** the user is authenticated **and** not Pro-active. Redirects to `/vendor/pro`
  (existing value-prop + CTA page) with a contextual warning ("That's a MyEventLane Pro feature.
  Upgrade to unlock it…") and a `return_to` query param.
- **Untouched:** Pro members (pass the gate → normal access), anonymous users (normal 403/login
  flow), the global 403 page, and `/vendor/pro/manage` (gated by `accessProVendor`; subscription
  management, not a feature lock point — non-Pro reach Pro via the accessible `/vendor/pro`).

## Phases 2–5 — Verification

| Phase | Outcome |
| --- | --- |
| **2 Accessibility** | Primitives strong on every organiser page (skip link, `lang`, abundant `aria`, form `<label>`s, `<h1>` present). **OD-6 corrected:** the dashboard *does* have an `<h1>` element. Minor refinements: confirm dashboard h1 text is non-empty; some pages carry multiple `<h1>`. **Full WCAG 2.1 AA (contrast/keyboard/screen-reader) → DEFERRED** to a live axe-core + manual pass (no a11y tooling in this environment). |
| **3 Mobile** | Responsive shells + PWA check-in present. **On-device task completion → DEFERRED** (no device lab here). |
| **4 Empty states** | Governed empty-state framework exists (verified in customer programme). **Per-organiser-tab empty/loading/error verification → DEFERRED** (requires seeded no-data fixtures). |
| **5 Performance** | **PASS.** All organiser pages render in **0.5–1.23 s** warm/authenticated (dashboard slowest at 1.23 s / 112 KB). No obvious bottlenecks; no premature optimisation undertaken. |

## Phase 6 — Release hygiene
See `launch-validation.md`. composer valid · config in sync · relevant unit suite OK · phpcs clean
on new code · no fresh 500s on organiser routes.

## Change surface (this programme)
~30 insertions across 4 files + 1 new subscriber:
- `config/sync/user.role.mel_pro.yml` (+4)
- `myeventlane_pro/myeventlane_pro.services.yml` (+10)
- `myeventlane_reporting/myeventlane_reporting.routing.yml` (11 routes re-notated)
- `myeventlane_reporting/src/Controller/EventInsightsController.php` (+7/−2)
- **new** `myeventlane_pro/src/EventSubscriber/ProUpgradeRedirectSubscriber.php`

All changes are Drupal 11 / Commerce 3 safe, config-aware, no architecture change, no duplicated
business logic. No STOP conditions were hit — every fix was small and isolated.
