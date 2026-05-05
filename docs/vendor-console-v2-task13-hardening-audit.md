# MEL Vendor Console v2 — TASK 13 hardening audit

**Branch:** `feature/mel-vendor-console-v2`  
**Date:** 2026-05-05  
**Scope:** Final styling/consistency pass, accessibility polish, log-noise cleanup, vendor-visible broken-state surfacing (presentation only). No business-logic rebuild.

---

## 1. Vendor Console pages reviewed (routes)

| Path | Route name | Notes |
| ---- | ---------- | ----- |
| `/vendor/dashboard` | `myeventlane_vendor.console.dashboard` | Hero, readiness, KPIs, action queue, event cards |
| `/vendor/events` | `myeventlane_vendor.console.events` | Filters, grid cards, bulk/advanced ticket manager |
| `/vendor/events/{event}` | `myeventlane_vendor.console.event_workspace` | Header, next action, readiness, metrics, tabs |
| `/vendor/events/create` | `myeventlane_event_studio.create` | Shell + Event Studio entry |
| `/vendor/events/{node}/edit` | `myeventlane_event_studio.edit` | Event Studio sections |
| `/vendor/analytics` | `myeventlane_analytics.dashboard` | Vendor-wide analytics |
| `/vendor/events/{event}/analytics` | `myeventlane_vendor.console.event_analytics` | Workspace-scoped analytics |
| `/vendor/settings` | `myeventlane_vendor.console.settings` | Profile/settings form |
| `/vendor/dashboard/messaging/brand` | `myeventlane_vendor.console.messaging_brand` | Branding form |

---

## 2. SCSS / CSS inspected

- `web/themes/custom/myeventlane_vendor_theme/src/scss/main.scss` — entry; `.mel-vendor` scope
- `layout/_navigation.scss`, `pages/_mel-dashboard.scss`, `pages/_vendor-events.scss`, `pages/_analytics.scss`, `pages/_settings.scss`
- `components/_workspace.scss`, `components/_vendor-alert.scss`, `components/_buttons.scss`, `components/_tabs.scss`, `components/_mel-vendor-events.scss`
- `workspace.scss` (if present in pages list — verified via workspace partial)
- `myeventlane_vendor_settings/css/mel-vendor-settings.scss`
- `myeventlane_messaging/css/vendor-branding.css`
- `myeventlane_event_studio/css/mel-event-studio-nav.css`

**Note:** `_vendor-events.scss` contained legacy hex accents (`#f26d5b`, `#7c83fd`); TASK 13 prefers tokens — incremental alignment where touched.

---

## 3. Component consistency checklist

| Area | Status (TASK 13) |
| ---- | ----------------- |
| Cards | Dashboard/events/workspace use 16px-radius surfaces; continued alignment |
| Feature panels | Workspace/dashboard sections use warm surface + headings |
| Buttons | Primary coral / secondary outline; 44px min-height on key CTAs |
| Links vs buttons | Advanced ticket manager stays tertiary / link-style |
| Chips / severity | Status uses label + class modifier; **new** presentation chips for mapping/pricing issues (icon + text) |
| Tabs | Horizontal scroll preserved; focus-visible reinforced |
| Empty states | Custom copy; dashboard avoids duplicate primary when hero has CTA |
| Action queue | Severity dot + title + message pattern retained |
| Forms (settings/brand) | Card sections; file/colour fields — incremental polish |
| Mobile nav | 390px-first grids and overflow tabs |
| Footer / internal nav | No changes unless regression |

---

## 4. Log cleanup findings

### 4.1 `BOOST CANDIDATE` / `mel_debug` Notice

| Item | Detail |
| ---- | ------ |
| **Source** | `VendorDashboardController::getTopBoostOpportunity()` logs via `logger.channel.mel_debug` when `state.mel.dev_mode` forces boost candidates visible |
| **Issue** | `notice()` floods Watchdog when dev UI fallback is on |
| **Decision** | Change to `debug()` — diagnostics remain available when log verbosity includes Debug; no loss of production errors (`melDebugLogger->error` unchanged) |

### 4.2 `FINAL RESPONSE` (`mel_debug`)

| Item | Detail |
| ---- | ------ |
| **Source** | `myeventlane_debug\EventSubscriber\ResponseDebugSubscriber::onResponse()` |
| **Issue** | Logs **every** main request at **notice** via `\Drupal::logger()` |
| **Decision** | Gate logging behind `state.get('mel.debug_http_response_trace', FALSE)`; inject `StateInterface` + `LoggerChannelFactory`; use channel `mel_debug` at **debug** level only when flag enabled; remove `\Drupal::` static call |

### 4.3 Ticket mapping (`No mel_ticket_type maps variation`)

| Item | Detail |
| ---- | ------ |
| **Source** | `TicketAvailabilityService` — `error()` when `resolveTierForVariation()` returns NULL before blocking purchase |
| **Decision** | **Keep** error logging and enforcement. **UI:** New `VendorEventPresentationAlertsBuilder` uses **public** `resolveTierForVariation()` + published `ticket_variation` rows on the event product — same mapping rule as checkout, no purchase-path changes. Surfaced on events index cards, dashboard event cards, and workspace alert strip. |

### 4.4 Paid display pricing warning

| Item | Detail |
| ---- | ------ |
| **Source** | `BookingFlowResolver::getDisplayPricing()` — `warning()` when `loadPublishedPaidTicketPrices()` is empty in MODE_PAID |
| **Decision** | **Keep** warning (real data issue). **UI:** Presentation builder calls `TicketTypeManager::loadPublishedPaidTicketPrices()` + `BookingFlowResolver::getBookingMode()` — **does not** call `getDisplayPricing()` to avoid duplicate watchdog noise on vendor pages. |

---

## 5. Remaining visible issues

| Category | Item |
| -------- | ---- |
| **Fixed in TASK 13** | Boost candidate notice flood; global response notice flood; vendor-visible ticket mapping / paid price display gaps; SCSS/twig polish for alerts and chips |
| **Deferred TASK 14** | Data repair tooling for orphaned Commerce variations; bulk ticket-type reconciliation; any backend signal when inverse-only ticket types diverge from `field_ticket_types` (mapping uses same field set as `resolveTierForVariation`) |
| **Intentionally retained** | Legacy route definitions (`/vendor/event/…`, `/vendor/studio`, `/vendor/events/add`); internal JS/schema URLs; admin-sidebar CSS selectors |

---

## 6. Legacy link regression

Re-run grep after implementation (see TASK 13 verification). Expected: no **new** vendor-facing UI links to `/node/add/event`, `/vendor/events/add`, `/vendor/event/…`, `/vendor/studio`; route YAML and redirects may still define paths.

---

## 7. Verification commands (post-change)

- `git status -sb`
- `php -l` on changed PHP files
- `composer validate`
- `ddev drush cr`
- `npm run mel:lint` && `npm run mel:build`
- Optional: `ddev drush ws --count=50` after browsing dashboard/events

---

## 8. Browser smoke

Manual smoke recommended on listed routes at ~390px width and keyboard focus. **Agent environment:** automated browser smoke **not** executed unless CI runs visual/e2e — record in route-map TASK 13 notes.
