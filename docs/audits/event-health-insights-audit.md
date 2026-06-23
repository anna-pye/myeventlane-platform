# Event Health & Insights Audit

**Module surfaces:** `myeventlane_analytics`, `myeventlane_reporting`, `myeventlane_event_studio`, `myeventlane_growth`, `myeventlane_vendor`
**Date:** 2026-06-23
**Branch:** `feature/mel-workflow-trust-confirmation`
**Author role:** Product/UX + Drupal 11 partner (repository-first)
**Objective:** Turn organiser analytics into *guidance* — confidence, meaning, and a clear next action. Not a BI/reporting project. Interpret existing data only; do not invent data, estimate numbers, or build scoring systems.

---

## 0. Executive summary

MEL already has most of the *guidance* machinery the brief asks for. The problem is **inconsistency**, not absence:

- **Good (already humane):** `/vendor/analytics` (v2 view model) gives KPIs with plain-language context, empty states, and per-event CTAs. Event Studio's **Event health** panel summarises Publish / Promotion / Boost with tone + plain language. `GrowthInsightService` produces deterministic, encouraging, action-linked cards ("Ticket sales are slower than expected → Boost", "Your event is gaining traction → Boost final push") wired to **existing routes**.
- **Weak (raw numbers, feels like Drupal):** the **per-event "Advanced analytics" page** (`analytics-event.html.twig`, route `myeventlane_analytics.event` → `/vendor/analytics/event/{node}`) is the legacy surface. It shows bare metrics, hardcoded colours/currency, a chart with no text alternative, and **no empty states / no "what to do next."**

**Highest-impact, lowest-risk move:** bring the per-event page up to the standard the rest of the product already sets — reusing existing copy patterns from `VendorAnalyticsViewModelBuilder` and existing CTA routes. No new entities, no Commerce/Stripe changes, no scoring.

---

## 1. Inventory — routes, controllers, services, view models, templates

### 1.1 `myeventlane_analytics` (organiser-facing analytics)

| Element | Path / Class |
|---|---|
| Route: vendor dashboard | `myeventlane_analytics.dashboard` → `/vendor/analytics` |
| Route: per-event | `myeventlane_analytics.event` → `/vendor/analytics/event/{node}` |
| Route: exports | `…export_pdf`, `…export_excel` (per-event) |
| Route: admin revenue | `myeventlane_analytics.admin_revenue` (admin, out of scope) |
| Controller | `Controller/AnalyticsDashboardController.php` (`dashboard`, `eventAnalytics`, `exportPdf`, `exportExcel`) |
| View model (dashboard) | `Service/VendorAnalyticsViewModelBuilder.php` — TASK 9 contract: `title, subtitle, date_range, kpis, events, insights, empty_state` |
| Supporting services | `AnalyticsDataService`, `SalesAnalyticsService`, `ConversionAnalyticsService`, `PopularEventsService`, `TrendingCategoriesService`, `OrderItemClassifier`, `ReportGeneratorService` |
| Phase7 query layer | `Phase7/Service/AnalyticsQueryService`, `AdminRevenueQueryService`, `Scope/AnalyticsScopeResolver`, `Guard/AnalyticsQueryGuard` (vendor isolation, currency segmentation — covered by kernel tests) |
| Template: dashboard | `templates/analytics-dashboard.html.twig` (**v2, good**) |
| Template: per-event | `templates/analytics-event.html.twig` (**legacy, raw**) |
| Assets | `css/analytics.css`, `js/analytics.js` (Chart.js canvases) |

### 1.2 `myeventlane_reporting` (event insights)

| Element | Path / Class |
|---|---|
| Routes | `myeventlane_reporting.event_insights.overview` / `.attendees` / `.checkins` → `/vendor/events/{event}/insights/*` |
| Controllers | `EventInsightsController`, `VendorInsightsController`, `AdminReportsController`, `ChartDataController`, `ExportCentreController` |
| Access | `Access/VendorReportingAccess.php` |
| Templates | `myeventlane-reporting-event-insights.html.twig`, `…vendor-insights`, `…kpi-card`, admin variants |

### 1.3 `myeventlane_event_studio` (workspace + event health)

| Element | Path / Class |
|---|---|
| Controllers | `EventStudioController`, `EventStudioPublishController` |
| Presentation service | `Service/EventStudioWorkspacePresentation.php` — `buildEventHealth()`, `buildReadinessSummary()`, AJAX payloads |
| Templates | `mel-event-studio-event-health.html.twig` (**accessible: `role="region"`, `aria-labelledby`, `dl/dt/dd`**), `mel-event-studio-workspace.html.twig` |
| Tests | `EventStudioWorkspaceStateMatrixTest`, `…PresentationContractTest`, `…UxConsolidationTest` |

### 1.4 `myeventlane_growth` (deterministic guidance cards)

| Element | Path / Class |
|---|---|
| Service | `Service/GrowthInsightService.php` (+ `GrowthTrackingService`, `GrowthOrganiserSignals`) |
| Config | `myeventlane_growth.settings` (thresholds, cooldowns, `limits.max_dashboard_cards`), schema present |
| Form | `Form/GrowthSettingsForm.php` |
| Output | Cards: `key, type, severity, title, message, cta_route, cta_params, card_class, event_id` → CTAs to `myeventlane_boost.*`, `myeventlane_event.duplicate`, `myeventlane_vendor.console.audience`, `myeventlane_pro.*` |

---

## 2. Per-metric review — *what does it mean / is it understandable / does the organiser know what to do?*

### 2.1 `/vendor/analytics` dashboard KPIs (view model) — **mostly good**

| Metric | Meaning clear? | Guidance? | Verdict |
|---|---|---|---|
| Revenue | Yes — context: "Net ticket revenue after refunds · completed orders only" | Indirect (per-event CTAs) | Useful |
| Tickets sold | Yes — context: "Published events you manage · completed ticket orders" | Indirect | Useful |
| RSVPs | Yes — "Confirmed RSVPs on published events you manage" | Indirect | Useful |
| Upcoming events | Yes — "Published events starting soon or in progress" | Indirect | Useful |
| `insights` array | N/A — **currently passed as `[]`** by the view model | None rendered | **Gap: template renders insights, model never fills them** |

> The dashboard template (`analytics-dashboard.html.twig:69-78`) has a complete insights region, but `VendorAnalyticsViewModelBuilder` sets `'insights' => []` (lines ~108, 126). Guidance that exists in `GrowthInsightService` is **not surfaced on this page**. (See Missing Guidance §4.)

### 2.2 Per-event "Advanced analytics" (`analytics-event.html.twig`) — **raw numbers**

| Metric | Meaning clear to organiser? | Knows what to do? | Verdict |
|---|---|---|---|
| Total Revenue | Partially — no "after refunds" qualifier here; hardcoded `$` (multi-currency unsafe) | No | Confusing |
| Tickets Sold | Yes | No | Raw |
| Average per Day | **No** — average of what window? since publish? | No | Confusing |
| Sales Trend (↗/↘/→) | Partially — colour-only meaning (green/red) | No | **Confusing + a11y risk** |
| Sales Over Time (chart) | Chart only — **no text/table alternative** | No | **a11y fail** |
| Conversion Funnel (views→cart→checkout→completed) | Labels OK, but bare counts | No | Raw |
| Conversion Rates (4×%) | **No** — "is 12% good?" unanswered | No | Confusing |
| Ticket Type Breakdown | Yes (has table) | No | Useful-ish |
| Conversion Bottlenecks | **Yes — includes `recommendation` text** | Partially | **Good pattern (already guidance)** |

---

## 3. Confusing / raw / duplicate / empty findings

### 3.1 Raw numbers without interpretation
- Per-event page: "Average per Day", conversion rates, funnel counts, trend — presented as figures with no "good/normal/needs attention" framing and no next action.

### 3.2 Unexplained metrics
- "Average per Day" — undefined window.
- Conversion rate percentages — no benchmark or interpretation (brief explicitly wants "Are these numbers good?" answered).

### 3.3 Duplicate / overlapping surfaces (navigation confusion, not data duplication)
- Per-event analytics exists in **three** places: `/vendor/analytics/event/{node}` (advanced), `/vendor/events/{event}/insights/*` (reporting), and Event Studio workspace → Analytics. The per-event template's own subtitle admits this: *"Primary workspace analytics live under Event workspace → Analytics."* → **organiser disorientation risk.** (Document, do not silently merge — see Risks.)

### 3.4 Empty charts / empty tables
- Per-event page sections are wrapped in `{% if … is not empty %}` and simply **vanish** when empty. No "no data yet" explanation. A newly published event shows a near-blank page → "is it broken?"
- Dashboard insights region is structurally present but never populated (empty by construction).

### 3.5 Feels like Drupal / Bootstrap (not MEL)
- `analytics-event.html.twig` uses **inline `style=""` throughout**, **hardcoded hex** (`#28a745`, `#dc3545`, `#666`, `#fff3cd`) instead of MEL tokens, and a hardcoded **`$`** currency symbol. This is the single most "un-MEL" page in the set and contradicts the token/contrast direction in `DESIGN_SYSTEM.md`.

---

## 4. Missing guidance

1. **Per-event page has no "what to do next."** It has metrics but (except Bottlenecks) no CTAs to existing routes (Share, Grow/Boost, View attendees, Duplicate). Dead-end analytics.
2. **Growth insights not surfaced on `/vendor/analytics`.** The view model passes `insights => []` while `GrowthInsightService` already produces relevant cards. The interpretation layer exists but isn't connected to this page.
3. **No plain-language event-health line on the per-event page** equivalent to Event Studio's "Published · Ready" / "Needs attention".
4. **Conversion numbers lack interpretation.** Brief wants "Are these good?" — answerable with existing data via simple deterministic bands (e.g. "Most views are converting" vs "Few views are converting yet") **without** inventing a score.

---

## 5. Empty states — required: *what happened / what it means / what to do next*

| State | Current | Target (existing data + existing routes only) |
|---|---|---|
| No views yet | Section hidden | "No views yet. Your event needs to be seen before it can sell. → **Share event**" |
| No attendees / no sales | Section hidden | "No bookings yet. This is normal for a newly published event. → **Share event** / **Grow event**" |
| No insights | Region renders nothing | "Nothing needs your attention right now — your event is on track." |
| Newly published | Near-blank page | "Your event is newly published. Numbers appear as people discover it. → **Share event**" |
| Metrics failed to load | Sections vanish | Reuse view model's existing "Not available yet · Try again shortly" pattern |

The dashboard already models this well (`empty_state` in the view model, `mel_analytics_events_empty`); the per-event page should adopt the same approach.

---

## 6. Event health interpretation (existing data only — no scoring, no estimates)

Reuse the deterministic, tone-based pattern already in `EventStudioWorkspacePresentation::buildEventHealth()` and the encouraging copy style in `GrowthInsightService`. Allowed interpretive statements, all derivable from existing fields:

- "Your event is newly published." (published recently, low/zero views)
- "Your event is gaining interest." (views rising, mirrors existing `boost_traction` logic inputs)
- "Bookings are increasing." (tickets/RSVPs trend `increasing` — already computed in `sales_velocity.trend`)
- "Your event is almost full." (`percent_sold` near capacity — field already used by GrowthInsightService)
- "Sales are slower than expected — more people need to see it." (mirrors existing `boost_slow_sales` threshold)

No new thresholds invented where `myeventlane_growth.settings` already defines them (`slow_sales_percent`, `traction_percent`, `strong_event_min_filled`). **Reuse config, do not add a parallel scoring system.**

---

## 7. Growth actions (use existing routes only)

| Situation | Action label | Existing route |
|---|---|---|
| Low/no views | Share event | (vendor share/event canonical URL — confirm in controller) |
| Slow sales, boostable | Grow event / Boost | `myeventlane_boost.wizard.step1` |
| Has attendees | View attendees | `myeventlane_vendor.console.audience` |
| Event ended well | Duplicate event | `myeventlane_event.duplicate` |
| View public listing | View event | event node canonical |

All already used by `GrowthInsightService` — no new routes needed.

---

## 8. Accessibility audit

| Check | Event Studio health | `/vendor/analytics` dashboard | Per-event advanced |
|---|---|---|---|
| Semantic regions / landmarks | ✅ `role="region"`, `aria-labelledby` | ✅ `aria-label`/`aria-labelledby` | ⚠️ generic `<div>`s, inline-styled |
| Plain language | ✅ | ✅ | ❌ raw metrics |
| Colour independence | ✅ tone class + text | ✅ | ❌ trend conveyed by colour + arrow only; hardcoded hex |
| Chart alternative | n/a | n/a | ❌ time-series `<canvas>` has **no** table/text alt (funnel & ticket breakdown do have tables) |
| Keyboard support | ✅ (links) | ✅ | ⚠️ links OK, but no focus styling guarantees with inline styles |
| Contrast (tokens) | ✅ | ✅ | ❌ hardcoded greys (#666) on white, unverified |

**Priority a11y fixes (per-event page):** (1) text alternative for the time-series chart, (2) trend not by colour alone, (3) move off inline styles/hardcoded hex onto MEL tokens.

---

## 9. Risks register (for the implementation phase)

| Risk | Severity | Mitigation |
|---|---|---|
| Touching Commerce/Stripe/order data | High (forbidden) | All proposed changes are **presentation-layer only**; read existing view-model fields. No query/Commerce edits. |
| Vendor data isolation regression | High | Do not alter `AnalyticsQueryGuard` / `AnalyticsScopeResolver`; rely on existing access checks. Per-event route already access-controlled. |
| Multi-currency: hardcoded `$` | Medium | Replace with currency-aware formatting **only if** view model already exposes a formatted/currency value; otherwise document and leave. Do not introduce currency logic. |
| Creating a parallel "scoring" system | Medium (forbidden) | Reuse `myeventlane_growth.settings` thresholds + existing `sales_velocity.trend`; deterministic statements only. |
| Duplicating components / second token system | Medium | Reuse `mel-kpi-card`, event-health classes, MEL tokens (`DESIGN_SYSTEM.md`). No new parallel CSS systems. |
| Surfacing GrowthInsights on dashboard changes behaviour | Medium | Treat as separate, optional step; gate behind explicit approval (it changes what organisers see). |
| Three overlapping per-event analytics destinations | Low-Med | **Document only.** Do not merge/redirect routes in this pass (navigation/IA decision, needs product sign-off). |

---

## 10. Recommended changes (scoped, presentation-only)

**Tier 1 — Quick wins (low risk, high clarity):**
1. Add empty states to `analytics-event.html.twig` (what happened / what it means / what to do + existing-route CTA).
2. Add a text alternative (visually-hidden table or summary) for the time-series chart.
3. Make trend not colour-dependent (already has arrow + word — ensure the word is always present and not colour-only for meaning).

**Tier 2 — Clarity & MEL feel:**
4. Replace inline styles + hardcoded hex/`$` with MEL tokens and existing `mel-kpi-card` / analytics classes.
5. Add a one-line plain-language **event-health summary** at the top of the per-event page, reusing `EventStudioWorkspacePresentation` patterns (deterministic, existing data).
6. Add interpretive captions under conversion metrics ("Most views are converting" / "Few views converting yet") using existing thresholds.

**Tier 3 — Connect existing guidance (needs explicit approval — changes what organisers see):**
7. Populate the dashboard `insights` from `GrowthInsightService` (currently `[]`).

**Out of scope / document only:** merging the three per-event destinations; any Commerce, Stripe, permission, entity, or third-party-analytics change; AI summaries.

---

## 11. Validation plan (run before commit)

```bash
git status --short
ddev composer validate --check-lock     # composer validate --check-lock
ddev drush cr
# PHP lint on any changed PHP:
find <changed php> -name '*.php' -print0 | xargs -0 -n1 php -l
# Theme assets if SCSS/Twig touched:
npm run mel:lint && npm run mel:build
```

No change should claim "validated" until these run.
