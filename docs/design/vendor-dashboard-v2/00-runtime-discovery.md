# Vendor Dashboard v2 — Stage 1.5 Runtime Discovery

**Status:** Discovery only (pre-implementation)  
**Branch:** `feature/vendor-dashboard-v2` @ `origin/main` (`e450a5e96`)  
**Route:** `/vendor/dashboard` · `myeventlane_vendor.console.dashboard`  
**Date:** 2026-07-25

No Twig, SCSS, PHP, or config was changed for this document.

---

## 1. Twig templates

| Template | Role |
| --- | --- |
| `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` | **Primary** — theme hook `myeventlane_vendor_dashboard` |
| `templates/page--vendor-dashboard.html.twig` | Page suggestion → shell |
| `templates/layout/page.html.twig` | Vendor shell (sidebar/header/footer) |
| `templates/includes/vendor-shell-main-content.html.twig` | Main content slot + governance stack |
| `templates/includes/mel-vendor-dashboard-governance-stack.html.twig` | Collapsed governance details |
| `templates/includes/footer-dashboard-light.html.twig` | Light footer |
| `templates/includes/dashboard-mel-support-strip.html.twig` | Support strip |
| `templates/components/stripe-panel.html.twig` | Stripe status |
| `templates/components/vendor-kpi-strip.html.twig` | STAGE A2 KPI strip (**not included** by current dashboard Twig) |
| `templates/components/kpi-card.html.twig` | Reusable KPI card |
| `templates/components/empty-state.html.twig` | Empty state card (title, message, one CTA) |

---

## 2. Preprocess

| Hook | File | Behaviour |
| --- | --- | --- |
| `myeventlane_vendor_theme_preprocess_myeventlane_vendor_dashboard` | `myeventlane_vendor_theme.theme` ~1914 | Adds `timestamp_label` on `dashboard_activity_items` via `myeventlane_core.date_formatter` |
| Theme registration | `hook_theme` → `myeventlane_vendor_dashboard` | Declares allowed `#` variables (view model, stripe, Pro, growth, etc.) |

No other dashboard-specific preprocess found. Business data is assembled in the controller / view model — not Twig.

---

## 3. Controller & view model

| Class / service | Role |
| --- | --- |
| `Drupal\myeventlane_vendor\Controller\VendorDashboardController::dashboard()` | Assembles page vars; attaches libraries; returns `buildVendorPage('myeventlane_vendor_dashboard', …)` |
| `VendorDashboardViewModelBuilder` (`myeventlane_vendor.dashboard_view_model_builder`) | `vendor`, readiness, `kpis` (≤4), `action_queue`, events, upcoming, current_event, activity, `empty_state`, organiser_overview |
| `VendorActionQueueBuilder` | Severity-ordered queue (max 6); Twig must not re-sort |
| `MelVendorDashboardActionQueueGovernance` | Presentation reorder/suppress after build |

---

## 4. VendorActionQueueBuilder payload

Each item (confirmed keys):

| Key | Present? | Notes |
| --- | --- | --- |
| `key` | Yes | e.g. `no_vendor_profile`, `stripe_payout_incomplete` |
| `priority` | Yes | Numeric sort input |
| `severity` | Yes | `error` · `warning` · `info` · `success` |
| `title` | Yes | From `MelReadinessHelper` |
| `message` | Yes | **Maps to PDS “reason”** — no separate `reason` key |
| `action_label` | Yes | CTA label |
| `url` | Yes | `Url` object or null (access-checked) |
| `context` | Yes | type / entity_id |

**Slice 1 decision:** Present `message` as the Action Card reason. Do **not** invent a parallel `reason` field or new builder. Extending the builder solely to alias `reason` is unnecessary for presentation.

---

## 5. KPI components & data

| Source | Shape | Count | Used on first paint today? |
| --- | --- | --- | --- |
| `model.kpis` | key, label, value, context, trend, severity, url | **4** (revenue, tickets_sold, rsvps, upcoming_events) | Partially (hero uses 2–3 keys) |
| `model.organiser_overview` | live/draft/upcoming/bookings/… | More than 4 | Yes — **duplicate** “Workspace overview” |
| `vendor_kpis` (controller / `VendorKpiService`) | label, value, sub | **4** when store exists | Built but **not** rendered by current Twig |
| `templates/components/vendor-kpi-strip.html.twig` | Expects `vendor_kpis` | — | Unused by dashboard Twig |
| `templates/components/kpi-card.html.twig` | Rich card + icons | — | Available |
| SCSS | `_kpi-cards.scss`, live-ops header metrics | — | Loaded via `main.scss` |

**Slice 1 decision:** One strip only from **`model.kpis`** (always ≤4, already decorated). Do not also render `vendor_kpis` or organiser_overview on first paint (avoids duplicate metrics / invented analytics).

---

## 6. Empty-state components

| Asset | Notes |
| --- | --- |
| `empty-state.html.twig` | `mel-empty-state-card`; single CTA |
| `_empty-states.scss` | `.mel-empty-state` + `__actions` (supports multiple actions) |
| `MelReadinessHelper::vendorDashboardEmptyStrings()` | First-run vs events-present copy — **not** the Slice 1 “You’re all caught up” queue-empty copy |

**Slice 1 decision:** Reuse `.mel-empty-state` / extend `empty-state.html.twig` with optional secondary link. Queue-empty copy per Slice 1 brief (Australian English, sentence-case buttons per PDS `15`).

---

## 7. SCSS partials (dashboard-relevant)

| File | Role |
| --- | --- |
| `pages/_dashboard-live-ops.scss` | Primary `.mel-vendor-dashboard` composition (**main edit surface**) |
| `pages/_dashboard.scss` | Older `.mel-action-card`, empty-state-card |
| `pages/_dashboard-mel-support.scss` | Support strip |
| `pages/_mel-dashboard.scss` | Broader shell |
| `components/_kpi-cards.scss` | Metric cards |
| `components/_empty-states.scss` | Empty states |
| Loaded via | `src/scss/main.scss` → `dist/main.css` |

Layout token: `--mel-layout-dashboard` / `$mel-layout-dashboard` (already applied as max-width on `.mel-vendor-dashboard`).

---

## 8. Libraries attached

From `VendorDashboardController::dashboard()`:

- `myeventlane_vendor_theme/global-styling`
- `myeventlane_vendor_theme/dashboard` (alias → global-styling)
- `myeventlane_vendor_theme/mel_event_card_remove`
- Conditionally: `myeventlane_growth/dashboard_cards` + `drupalSettings.melGrowth`

Shell may also attach `footer-internal`.

**Slice 1:** No new libraries; no routing/library changes.

---

## 9. Cache contexts & tags

| Mechanism | Evidence |
| --- | --- |
| Contexts | Controller merges `user`, `user.roles` onto `$pageVars['#cache']['contexts']` |
| Max-age | Pro welcome one-shot sets `max-age = 0` |
| Tags | **No dashboard-specific entity cache tags** set on this render path |

**Slice 1:** Theme-only presentation; do not expand uncacheable surface. Cache tag hardening → later slice if Product prioritises.

---

## 10. Current first-paint order (problem)

1. Pro welcome  
2. Identity + **header metrics** + Create event  
3. Pro panel  
4. Stripe panel  
5. Workspace overview (**duplicate KPIs**)  
6. Needs attention (Action Queue) — **only if non-empty**  
7. Current focus  
8. Upcoming  
9. Activity  
10. Tools (homepage, analytics, growth, …)

Gaps vs Slice 1 / PDS `12`: queue not first content; empty queue omitted; metrics duplicated; marketing/Pro above queue.

---

## 11. Pattern gap / DDR check

| Need | Covered by | Gap? |
| --- | --- | --- |
| Action Cards | PDS `05` + existing `.mel-action-card` SCSS | No — present `message` as reason |
| Task list / queue | `VendorActionQueueBuilder` | No |
| Empty caught-up | PDS empty states + existing SCSS | No |
| ≤4 Metric Cards | `model.kpis` | No |
| Workspace Hero | Existing organiser header | No |
| Shell | DDR-001 / existing shell | No |

**No DDR required** for Slice 1.

---

## 12. Implementation constraints (from discovery)

1. Theme presentation only — **do not** create a new builder.  
2. Map `message` → reason in Twig.  
3. Single KPI strip from `model.kpis`.  
4. Demote Pro / Stripe / growth / Tools below Activity.  
5. Always render Action Queue region (populated or calm empty).  
6. Reuse shell, libraries, preprocess as-is.

**Discovery complete. Slice 1 implementation may begin.**
