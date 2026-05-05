# MEL Vendor Console v2 — TASK 12 navigation cleanup audit

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Purpose:** Classify all grep hits for legacy/canonical create paths and vendor nav surfaces before/after TASK 12 fixes. No replacement for `docs/vendor-console-v2-route-map.md`.

## 1. Grep scope

Patterns:

- `myeventlane_vendor.console.create_event`
- `myeventlane_vendor.console.events_add`
- `myeventlane_event_studio.create`
- `/node/add/event`
- `/vendor/events/add`
- `/vendor/event/` (legacy prefix)
- `/vendor/studio`

Sources: `web/modules/custom`, `web/themes/custom`, `config/sync` (plus repo docs where referenced).

## 2. Hit classification

### 2.1 `myeventlane_vendor.console.create_event`

| Location | Classification |
| -------- | -------------- |
| Historical mention in `docs/vendor-console-v2-route-map.md` (TASK 1 notes) | **docs only** — superseded by TASK 11 |
| `OrganiserContextBlock.php` (post TASK 11) | **fixed in TASK 11** — uses `myeventlane_event_studio.create`; TASK 12 **re-verified** + gate aligned with `VendorConsoleTrust` / staff bypass |

No remaining PHP/Twig references to this invalid route name after TASK 11.

### 2.2 `myeventlane_vendor.console.events_add` (`/vendor/events/add`)

| Location | Classification |
| -------- | -------------- |
| `myeventlane_vendor.routing.yml` | **safe legacy route definition** — redirect controller retained |
| `LaunchRequestProtectionSubscriber` | **safe legacy** — rate-limit list keeps legacy route name for bookmarks; canonical create also listed |
| `myeventlane_vendor_theme.theme` active-section map (`events_add` → `events`) | **safe internal** — deep-link section helper only |
| Docs (`vendor-console-v2-route-map.md`, `mel-event-studio-stripe-publish-gate.md`, `qa/journeys.md`, etc.) | **docs only** or **follow-up QA** |

**TASK 12:** No new UI links to `events_add`; menu YAML already uses `myeventlane_event_studio.create` (TASK 2).

### 2.3 `myeventlane_event_studio.create`

| Location | Classification |
| -------- | -------------- |
| Routing, controllers, onboarding, PostLoginRouter, vendor bulk forms, Event Studio Twig CTAs | **canonical** |
| Public marketing Twig / preprocess using **direct** studio path for anonymous-friendly CTAs | **fixed in TASK 12** — public marketing uses **`myeventlane_vendor.create_event_gateway`** (`/create-event`) where the link is shown to anonymous or mixed audiences (includes `myeventlane_radix` header) |
| Vendor shell header primary action + vendor-only CTAs | **retained** — access-checked `myeventlane_event_studio.create` |

### 2.4 `/node/add/event`

| Location | Classification |
| -------- | -------------- |
| `myeventlane_theme/templates/layout/footer-internal.html.twig` (hardcoded href) | **fixed in TASK 12** — replaced with access-aware `vendor_console_urls` pattern (aligned with vendor theme internal footer) |
| Staff/admin theme docs (`myeventlane_admin_theme/*.md`), `TESTING_GUIDE.md`, `DDEV_MULTI_DOMAIN_CHECKLIST.md` | **docs / QA only** |
| `myeventlane_vendor_theme.theme` comment (node add staff-only) | **docs in code** |

### 2.5 `/vendor/event/` (legacy singular)

| Location | Classification |
| -------- | -------------- |
| `myeventlane_vendor.routing.yml`, RSVP routing, functional tests | **safe legacy route definitions + tests** |
| `hide-admin-sidebar.css` path selectors | **safe internal** — Gin/layout hiding |
| Historical docs (`VENDOR_EVENT_WORKFLOW.md`, etc.) | **docs only** |

**TASK 12:** No change to routes; no new UI links added.

### 2.6 `/vendor/studio`

| Location | Classification |
| -------- | -------------- |
| `myeventlane_vendor.routing.yml` + API POST siblings | **safe internal/API** |
| `VendorStudioController`, `vendor-studio.js` defaults | **safe internal/API** |
| `myeventlane_vendor_theme.theme` path prefix for active section | **safe internal** — not a user-facing CTA |

### 2.7 Config sync

No persistent menu/config hits requiring TASK 12 edits beyond theme/module link sources above.

## 3. Final decisions

### 3.1 Account menu / dropdown

- **Drupal `account` menu links** (`myeventlane_vendor.links.menu.yml`): Already use `myeventlane_event_studio.create` for Create event and console routes for Dashboard / Events / Settings; visibility relies on core menu access + route access (**unchanged**).
- **Vendor theme custom header dropdown** (`myeventlane_vendor_theme_preprocess_page` `user_menu`): **TASK 12** injects the same four links **before** Profile when each route passes `$url->access()`, so vendors/staff see console links without duplicating a second visibility system beyond route access.
- **Vendor theme `quick_actions` “+ Create Event”:** **TASK 12** shows only when `myeventlane_event_studio.create` is accessible (matches shell primary action posture). Ordinary authenticated customers no longer see this button on the vendor shell.

### 3.2 Public theme marketing “Create event”

- **Decision:** Use **`path('myeventlane_vendor.create_event_gateway')`** (route `/create-event`) for public header, homepage host CTA, footer create link, hero secondary CTA, mobile drawer, and related templates — anonymous users hit login/onboarding flow; trusted users are routed to Event Studio by the gateway controller.
- **`hook_preprocess_site_header` / `hook_preprocess_mobile_drawer`:** Build `create_event_url` from the gateway route (fallback `/create-event` string if route missing).

### 3.3 Organiser context block

- **Decision:** Replace narrow gate `access vendor dashboard` with **`VendorConsoleTrust::accountIsTrustedForVendorConsole()` OR `administer nodes`**, matching vendor-console trust used by `VendorConsoleAccess` for non-dashboard routes; individual links still use `Url::access()` so Dashboard remains permission-gated.

### 3.4 Vendor shell primary nav

- **Verification:** `_myeventlane_vendor_theme_build_full_vendor_shell_nav_items()` already implements Dashboard → Events → Analytics (access) → Attendees (access) → Profile; secondary items follow.
- **TASK 12:** No structural change required.

### 3.5 Public vs vendor internal footer

- **Vendor theme** `footer-internal.html.twig`: Already uses `footer_context.vendor_console_urls` from PHP.
- **Public theme** `footer-internal.html.twig`: **TASK 12** aligns markup with vendor theme (conditional URLs, no `/node/add/event`).
- **`footer_context` on public pages:** **TASK 12** sets `footer_context` in `myeventlane_theme_preprocess_page` via `FooterContextService` + `vendor_console_urls` helper when `is_vendor` is TRUE (role-based flag unchanged).

### 3.6 Launch protection subscriber

- **Decision:** Keep `myeventlane_vendor.console.events_add` in the rate-limit list for legacy POSTs; remove duplicate `myeventlane_event_studio.create` entry in the array (hygiene only). **`myeventlane_vendor.create_event_gateway` not added** to the rate-limit list in TASK 12 (gateway is primarily redirects; avoid new caps without product sign-off).

### 3.7 Local tasks / actions

- No invalid `events_add` / `create_event` references found in `*.links.task.yml` / `*.links.action.yml` under custom modules.

## 4. Residual / TASK 13

- **`FooterContextService::is_vendor`** remains role-based (`vendor` role); team-only accounts without the role may still miss internal footer vendor accordion until trust logic is centralized in that service (out of TASK 12 allowed file list).
- **Dashboard KPI / legacy controllers** may still emit singular `/vendor/event/…` strings — grep periodically after workspace consolidation.
- **Duplicate RSVP/workspace URLs** remain a product/redirect task.

## 5. Verification commands (post-change)

```bash
grep -R "myeventlane_vendor.console.create_event\|myeventlane_vendor.console.events_add\|/node/add/event\|/vendor/events/add\|/vendor/event/\|/vendor/studio" -n web/modules/custom web/themes/custom config/sync
```

Classify any new hits using §2 legend.
