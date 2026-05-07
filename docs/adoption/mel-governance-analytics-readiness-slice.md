# MEL governance slice: analytics + readiness vocabulary consolidation

Presentation rationalisation for the vendor console dashboard (TASK 3/5 view model) and related surfaces. **No** new governance/orchestration/analytics storage layer—only normalised copy, presentation contracts, and ordering/suppression hooks on existing systems.

## 1. Analytics / readiness audit (baseline)

| Surface | Location | Drift before | After |
|--------|----------|--------------|--------|
| Vendor | `VendorActionQueueBuilder`, `VendorDashboardViewModelBuilder`, `dashboard.html.twig`, legacy `VendorDashboardController` KPI strings | Parallel `$this->t()` readiness vs `mel_state.readiness_summaries`; hero/readiness row labels ad hoc; KPI cards without presentation contracts | Copy via `MelReadinessHelper`; KPI strip via `MelDataPresentationManager::decorateVendorDashboardMetricStrip()` |
| Vendor | Stripe / profile / draft / analytics-unavailable prompts | Mixed “Finish payout”, “payments”, urgency lines | Stripe urgent line aligned with readiness blocking line (`vendorStripeReadinessBlockingLine()` ↔ `vendorActionStripePayoutStrings(TRUE)`). |
| Customer / reporting | Account hub, controllers | Out of slice scope this pass; readiness helper extended for reuse only |

## 2. Readiness vocabulary ownership map

All **new/consolidated** vendor-dashboard readiness/action/hero/empty KPI-fallback wording is owned by **MELStateSystem** via **`MelReadinessHelper`** (`myeventlane_surface.state_readiness_helper`).

Examples:

- Blocking Stripe: `vendorStripeReadinessBlockingLine()`
- Profile incomplete narrative: `vendorOrganiserProfileIncompleteLine()`
- First-event narrative: `vendorFirstEventReadinessLine()`, `vendorFirstEventPublishingLine()`
- Readiness grid row states: `vendorReadinessRowCompleteLabel()`, `vendorReadinessRowIncompleteLabel()`
- Lifecycle chip labels on dashboard event rows: `vendorLifecycleDraftLabel()`, `vendorLifecycleUpcomingLabel()`, `vendorLifecyclePastLabel()`
- Hero hints: `vendorHeroShellNeedsAttentionHint()`, `vendorHeroShellEventsComfortableHint()`, `vendorHeroShellLaunchHint()`
- Empty state bundle: `vendorDashboardEmptyStrings()`
- Operational strings for action queue: `vendorAction*Strings()` family

Interpretation payloads for full-page `mel_state` remain **`MelStateManager::buildPageInterpretation()`**; this slice wires **presentation** to the same lexical source (`MelReadinessHelper`) used inside that stack.

## 3. VendorActionQueue rationalisation report

Class: `Drupal\myeventlane_vendor\Service\VendorActionQueueBuilder`

- **Readiness wording**: delegated to `MelReadinessHelper` (no inline duplicate copy).
- **Workflow priority**: `MelVendorDashboardActionQueueGovernance` maps action keys to vendor workflow IDs and applies weighted priority when those workflows are **incomplete** (`MelWorkflowResolver` + `MelWorkflowRegistry`), then re-sorts with severity tie-breakers.
- **Duplication vs primary CTA**: when `MelWorkflowManager::willRenderPrimaryWorkflowRegion()` is true, `profile_incomplete` and `stripe_payout_incomplete` actions are demoted (+140 priority) and tagged `governance_duplicate_workflow_primary`.
- **Operational suppression**: when `MelOperationalPolicyManager` reports `suppress_marketing_guidance`, `suppress_nonessential_notifications`, or `suppress_cross_sell_guidance`, `analytics_unavailable` actions are removed.

Service: `myeventlane_surface.vendor_dashboard_action_queue_governance`.

## 4. Governed analytics card / metric adoption report

- **`MelDataPresentationManager::decorateVendorDashboardMetricStrip()`** adds per-KPI:

  - `mel_data_presentation_contract` (`METRIC_SALES`, `METRIC_RSVP`, `METRIC_DASHBOARD_SUMMARY`)
  - `mel_component_contract` (`mel_card_section`)
  - `mel_data_presentation_description` when the registry resolves
  - `metric_presentation` (normalised shell from `MelMetricHelper`)

- **Twig**: `dashboard.html.twig` exposes `data-mel-presentation-contract` and `data-mel-component-contract` on KPI links/articles for downstream CSS/telemetry without inventing parallel card types.

Upstream numeric sources (`MetricsAggregator`, orders, RSVPs) unchanged.

## 5. Status language consolidation report

- Readiness checklist: **“Complete”** / **“Action required”** (replacing informal “Done” / “Needs attention”) via model keys `readiness.row_complete_label` / `readiness.row_incomplete_label`.
- Event row statuses on the compact dashboard list use shared lifecycle helpers (draft/upcoming/past) from `MelReadinessHelper`.

Legacy `VendorDashboardController` mega-dashboard strings are **untouched** in this slice to avoid unrelated churn.

## 6. CTA alignment report

- Action queue honours **workflow primary region** duplication rule (above).
- No change to Stripe route targets or Commerce access—you still assemble URLs in the vendor module; governance only adjusts priority/suppression/copy source.

## 7. Accessibility validation report

- KPI regions retain semantic structure (`article` / `a`) with additional **machine-readable** governance attributes (no `aria-live` changes in this slice).
- Readiness status text remains in the same DOM positions; wording only became more aligned with operational vocabulary (“Action required”).

## 8. Security / privacy validation report

- **Vendor isolation**: still enforced by Commerce/vendors/order access in producers; decorators add no queries and no cross-vendor merges.
- **Operational policy**: suppression only removes non-essential analytics nudges—no exposure of moderation or staff payloads.
- **`myeventlane_vendor` dependency**: `myeventlane_surface` is now a **required** dependency (`myeventlane_vendor.info.yml`) so governed presentation services always resolve.

## 9. JS authority reduction report

- **None in this slice**: no new orchestration scripts; Twig adds static `data-mel-*` hooks only.

## 10. File-by-file implementation summary

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_surface/src/MelReadinessHelper.php` | Canonical vendor strings; hero/empty/lifecycle/readiness row lines; action queue copy bundles; readiness summary uses same Stripe/profile/first-event lines. |
| `web/modules/custom/myeventlane_surface/src/MelVendorDashboardActionQueueGovernance.php` | **New** — workflow-weighted priority, suppression, primary-CTA demotion. |
| `web/modules/custom/myeventlane_surface/src/MelDataPresentationManager.php` | `decorateVendorDashboardMetricStrip()`. |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.services.yml` | Registers governance service. |
| `web/modules/custom/myeventlane_vendor/src/Service/VendorActionQueueBuilder.php` | Inject readiness helper + governance; remove dead sort helper after governance owns ordering. |
| `web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php` | Inject readiness helper + data presentation manager; decorate KPIs; hero hint resolver; readiness row labels; readiness item labels (`Stripe payouts`). |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` | Updated constructor injections. |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.info.yml` | Required `myeventlane_surface`. |
| `web/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig` | Governed hero hint; readiness labels; KPI `data-mel-*` attributes. |
| `web/phpunit-governance.xml` | Registers `MelVendorDashboardReadinessGovernanceKernelTest`. |
| `web/modules/custom/myeventlane_surface/tests/src/Kernel/MelVendorDashboardReadinessGovernanceKernelTest.php` | **New** — Stripe copy parity + governance smoke test. |

## Validation commands (completed in this workspace)

| Command | Result |
|---------|--------|
| `php -l` on changed PHP files | OK |
| `composer validate --no-check-publish` | OK |
| `vendor/bin/phpunit -c web/phpunit-governance.xml --filter MelVendorDashboardReadinessGovernanceKernelTest` | 2 tests OK |
| `npm run mel:lint` | OK |
| `npm run mel:build` | OK |
| `ddev drush cr` | Run locally when DDEV is available |

## Residual risk

- **Legacy dashboard controller** (`VendorDashboardController`) still carries parallel KPI/dashboard copy; converging it would touch a very large surface and was intentionally out of scope.
- **`vendorReadinessPresentationLabels()['analytics_unavailable']`** remains available for future use; headline copy for the analytics-unavailable action comes from `vendorActionAnalyticsUnavailableStrings()` for consistency with the historical title string.
- **Workflow weighting coefficient** (`WORKFLOW_CTA_PRIORITY_WEIGHT`) is heuristic; tune with product if ordering feels wrong under edge onboarding states.

## Manual smoke (vendor)

1. Open `/vendor/dashboard`: hero copy follows action queue presence; readiness rows show **Complete / Action required**; KPI tiles include `data-mel-presentation-contract`.
2. With marketing suppression policies active on a shell: confirm analytics-unavailable prompt disappears from the queue when suppression maps apply.
3. When MEL workflow primary renders: profile/stripe duplicates should rank lower than before.
