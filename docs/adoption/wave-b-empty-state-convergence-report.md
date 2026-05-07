# Wave B — Governed empty-state convergence + governance tooling (2026-05-07)

Single adoption report covering the Wave B slice: highest-traffic operational empties, vocabulary ownership, template parity tooling, test fixture notes, accessibility, performance, safety, cleanup, and implementation index.

## 1. Empty-state audit (before)

| Surface | Location | Before | Finding |
| --- | --- | --- | --- |
| Customer — My Events | `myeventlane_dashboard/templates/myeventlane-customer-dashboard.html.twig` | Ad hoc `mel-card--empty` + front link | Duplicate CTA/route vs governed “Browse events” vocabulary; no what/why/next slots |
| Customer — Categories (per-category quiet week) | `myeventlane_core/templates/myeventlane-my-categories.html.twig` | Single translated paragraph in `mel-card--empty` | Not `mel_empty_state`; drift from `MelReadinessHelper` customer category story |
| Customer — tickets / dashboard / categories list / past events / vendor attendees / analytics | Checkout flow + account + core + analytics | Already routed through `GovernedOperationalTemplates` + preprocess | Prior slice work retained |
| Vendor — analytics events list empty | `myeventlane_analytics` view model + preprocess | Legacy `empty_state` rows mapped via `analyticsEventsEmptyFromLegacy` only | Gap when `events` empty but legacy `title`/`message` blank — no governed fallback |
| Other ad hoc `mel-empty-state` CSS (search, venues, reporting, check-in…) | Various modules | Class-based placeholders | Out of Wave B convergence scope unless touched for regression |

Documented duplication is intentional to avoid churn on low-traffic or staff-only surfaces in this bounded slice.

## 2. Empty-state convergence report

Implemented or hardened:

- **My Events (customer)** — `myeventlane_customer_dashboard`: `GovernedOperationalTemplates::customerMyEventsDashboardUpcomingEmpty()` from `MelReadinessHelper::customerMyEventsDashboardUpcomingEmptySlots()`; dashboard module depends on `myeventlane_surface` so the service is always available where this theme exists.
- **Categories — quiet week** — When the user follows categories but a category has no new events this week: `categoryFollowWeeklyQuietEmpty()` + shared variable `mel_category_follow_section_no_new_events` (theme variable on `myeventlane_my_categories`).
- **Vendor analytics** — When `events` is empty: always attach `mel_analytics_events_empty` — either `analyticsEventsEmptyFromLegacy($empty_state)` or `vendorAnalyticsNoEventRowsEmpty()` (readiness + studio CTA).
- **Accessibility on canonical component** — `mel_empty_state` preprocess now applies `role="status"` and `aria-live="polite"` when not already set (`MelComponentAccessibilityHelper::applyEmptyStateSemantics`).

## 3. Vocabulary alignment report

New **MelReadinessHelper** contracts:

- `customerCategoryFollowWeeklyQuietSlots()`
- `customerMyEventsDashboardUpcomingEmptySlots()`

These join existing customer empty slots (`customerMyTicketsOverviewEmptySlots`, account dashboard slots, past events page, etc.). No new operational strings were added in Twig for the converged paths.

## 4. Template parity protection report

- `scripts/governance/template-parity-audit.php` now outputs `duplicate_path_warnings` when two registry pairs share the same canonical or override path (accidental duplicate ownership).
- `mel-template-parity.json` description updated to reference that behaviour.
- No new theme override pairs were required (customer dashboard has no `myeventlane_theme` twin in this repo).

## 5. Governance test fixture stabilisation report

- Extended **`mel_kernel_route_fixtures`** with stub **`commerce_checkout.order_information`** (`/checkout/order`) alongside existing `commerce_checkout.review`, so kernel tests that push that route name get a matching `RouteProvider` entry (same pattern as review).
- **`composer governance:test`** was already green with `commerce_checkout.review`; it remains **16/16** after changes.
- `CustomerHubPageAccountKernelTest` was **not** wired to `mel_kernel_route_fixtures` because that test module lives under `myeventlane_surface/tests/modules` and is not reliably discoverable from the `myeventlane_account` kernel test namespace; that test does not exercise checkout URL generation.

## 6. Accessibility consistency report

- Governed `mel_empty_state` regions gain non-interruptive live region semantics by default; explicit `role` on the attribute bag is left untouched.
- Customer dashboard empty section uses `aria-label` on the wrapping section; inner empty state supplies the visible heading (`h2`).

## 7. Performance / cacheability report

- One additional governed render array may be built on **My Categories** when the user has followed categories (reused for each quiet category row; same underlying render array reference from preprocess).
- Analytics empty path now always builds one `mel_analytics_events_empty` render array when `events` is empty (negligible vs page work); no extra JS or hydration added.

## 8. Observability / safety validation report

- No changes to observability payloads, permissions, or public exposure of governance diagnostics.
- Customer/vendor copy remains sourced from `MelReadinessHelper` / existing vendor analytics builders — no new debug strings.

## 9. Stale legacy cleanup report

- **Removed** only the ad hoc empty card bodies replaced by governed variables in the two converged customer templates.
- **Not removed** (still in use or out of scope): search/venue/reporting/check-in `mel-empty-state` CSS blocks; vendor theme `empty-state.html.twig` component.

## 10. File-by-file implementation summary

| File | Change |
| --- | --- |
| `web/modules/custom/myeventlane_surface/src/MelReadinessHelper.php` | New customer vocabulary for My Events empty + category quiet week |
| `web/modules/custom/myeventlane_surface/src/GovernedOperationalTemplates.php` | `categoryFollowWeeklyQuietEmpty`, `customerMyEventsDashboardUpcomingEmpty`, `vendorAnalyticsNoEventRowsEmpty` |
| `web/modules/custom/myeventlane_surface/src/MelComponentAccessibilityHelper.php` | `applyEmptyStateSemantics()` |
| `web/modules/custom/myeventlane_surface/src/MelComponentPreprocess.php` | Wire empty-state preprocess to accessibility helper |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.module` | Categories preprocess branch + analytics empty fallback |
| `web/modules/custom/myeventlane_core/myeventlane_core.module` | Theme variable `mel_category_follow_section_no_new_events` |
| `web/modules/custom/myeventlane_core/templates/myeventlane-my-categories.html.twig` | Render governed quiet-week empty |
| `web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.info.yml` | Dependency on `myeventlane_surface` |
| `web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.module` | Theme variable + preprocess for customer dashboard empty |
| `web/modules/custom/myeventlane_dashboard/templates/myeventlane-customer-dashboard.html.twig` | Replace ad hoc card with governed empty |
| `web/modules/custom/myeventlane_surface/tests/modules/mel_kernel_route_fixtures/mel_kernel_route_fixtures.routing.yml` | Stub `commerce_checkout.order_information` |
| `scripts/governance/template-parity-audit.php` | Duplicate path warnings in JSON output |
| `mel-template-parity.json` | Description note for audit behaviour |
| `web/modules/custom/myeventlane_surface/tests/src/Unit/MelReadinessHelperCustomerTest.php` | Cover new readiness slots |
| `web/modules/custom/myeventlane_surface/tests/src/Unit/MelComponentAccessibilityHelperTest.php` | New unit tests |

## Manual smoke checks (Step 12)

**Customer:** `/my-events` with no rows — single governed empty, CTA order matches account vocabulary; `/my-categories` — list empty vs per-category “quiet week” empty; `/my-tickets` unchanged behaviour.

**Vendor:** `/vendor/analytics` with no event rows — empty uses `mel_empty_state` (legacy or fallback).

**Staff / public:** unchanged; confirm observability still permission-gated on real site.

## Validation commands (Step 11)

- `php -l` on touched PHP: OK  
- `composer validate`: OK  
- `composer governance:test`: 16/16 OK  
- `composer governance:audit`: OK (incl. template parity; `duplicate_path_warnings`: [])  
- PHPUnit (targeted): `core/phpunit.xml.dist` — `MelComponentAccessibilityHelperTest`, `MelReadinessHelperCustomerTest`: OK  
- `npm run mel:lint`, `npm run mel:build`: OK  
- `ddev drush cr`: OK (when DDEV available)

## Residual risk / follow-ups

- Re-run `composer update` / deployment ordering if Composer flags the new **`myeventlane_dashboard` → `myeventlane_surface`** dependency until lock/install is refreshed on all environments.
- Support-console empty states (`myeventlane_help_assistant`, staff ticketing) were not part of this pass; converge when those routes are prioritised.
