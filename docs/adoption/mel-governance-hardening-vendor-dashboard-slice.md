# MEL governance hardening + vendor dashboard adoption (slice)

## 1. Observability hardening

- **Permission:** `access mel observability diagnostics` in `myeventlane_surface.permissions.yml` (`restrict_access: true`).
- **PHP gate:** `MelObservabilityDiagnosticsAccess::mayViewDiagnostics()` requires `MelSurfaceId::Staff` and the permission. `MelObservabilityManager::buildPagePayload()` returns a minimal payload (no traces, tier `none`) when the gate fails.
- **Defence in depth:** `SurfaceNegotiator::buildObservabilityPanelRenderArray()` and `drupalSettings['melObservability']` use the same gate so staff without the permission never get the panel or JS settings.
- **Redaction:** After `hook_mel_observability_page_payload_alter`, `MelObservabilityTraceSanitizer` normalises trace rows, registry meta, and telemetry reference strings (Stripe-like ids, long digit runs, email-shaped tokens).

## 2. Permission / access map

| Condition | `mel_observability` payload | `mel_observability_panel` | `drupalSettings.melObservability` |
|-----------|----------------------------|---------------------------|-------------------------------------|
| Public / Auth / Customer / Vendor | Minimal (no traces) | `NULL` | Not set |
| Staff, no permission | Minimal | `NULL` | Not set |
| Staff + permission | Full diagnostic payload | Rendered when traces non-empty | Set when traces non-empty |

Cache contexts on restricted payloads include `user.permissions`.

## 3. Vendor dashboard adoption map

| MEL system | Consumption |
|------------|--------------|
| **MELSurfaceSystem** | `hook_preprocess_page` continues to attach negotiator metadata; vendor layout gates governance include on `mel_surface == 'vendor'`. |
| **MELStateSystem** | Readiness summaries from `mel_state` in `mel-vendor-dashboard-governance-stack.html.twig`. |
| **MELWorkflowSystem** | `mel_workflow_region_primary` and `mel_workflow_region_progress` rendered above dashboard content. |
| **MELExperienceSystem** | Resume candidates from `mel_experience.resume_candidates` (categorical `experience_id` labels only). |
| **MELIntelligenceSystem** | `mel_intelligence_panel` embedded in the governance stack. |
| **MELOperationalPolicySystem** | `explainability.lines` rendered as operational notices; **VendorDashboardController** uses `MelOperationalPolicyManager::buildPageInterpretation()` to suppress growth cards and top boost slot when suppression flags demand it (no parallel suppression logic). |
| **MELObservabilitySystem** | Panel region included for staff+permission only (empty on vendor surface). |
| **MELDataPresentationSystem** | Layout/data attributes from `mel_data_presentation_context` on the governance wrapper (`data-mel-layout-profile`, `data-mel-data-density`). |
| **MELInteractionSystem** | Existing page-attached interaction/experience libraries unchanged; checkout unaffected (vendor route only). |

## 4. Duplication cleanup

- **Boost / growth:** Single source for “suppress promotional surfaces”: MELOperationalPolicySystem suppression map. Legacy controller paths for `growth_cards` and `mel_top_boost_opportunity` are skipped when those flags are active (avoids running growth insight/impression side paths when suppressed).

## 5. Governance consumption report

- Operational policy interpretation drives vendor promotional suppression before building boost opportunity and growth cards.
- Governance stack Twig consumes only preprocess-safe variables (no new query layers).

## 6. Accessibility

- Governance wrapper: `role="region"` with `aria-label` for the stack; section headings for state, experience, policy; lists for readiness and explainability lines.
- Existing MEL panel templates retain their accessibility helpers.

## 7. Security / privacy

- No observability trace build for non-authorised surfaces/users (reduces accidental leakage via `mel_observability` Twig variable).
- Sanitiser strips payment-adjacent string patterns from staff-visible copy.
- Vendor isolation unchanged; no cross-vendor analytics added.

## 8. File-by-file summary

| File | Change |
|------|--------|
| `web/modules/custom/myeventlane_surface/myeventlane_surface.permissions.yml` | **New** — diagnostics permission. |
| `web/modules/custom/myeventlane_surface/src/MelObservabilityDiagnosticsAccess.php` | **New** — gate helper. |
| `web/modules/custom/myeventlane_surface/src/MelObservabilityTraceSanitizer.php` | **New** — redaction helper. |
| `web/modules/custom/myeventlane_surface/src/MelObservabilityManager.php` | Gate, restricted payload, post-alter sanitisation. |
| `web/modules/custom/myeventlane_surface/src/SurfaceNegotiator.php` | Inject current user + diagnostics access; gate panel and drupalSettings. |
| `web/modules/custom/myeventlane_surface/myeventlane_surface.services.yml` | Wire new services and constructor arguments. |
| `web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php` | Optional policy manager; suppression helper; conditional boost/growth. |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml` | Pass `operational_policy_manager` into dashboard controller. |
| `web/modules/custom/myeventlane_vendor/myeventlane_vendor.info.yml` | Optional dependency on `myeventlane_surface`. |
| `web/themes/custom/myeventlane_vendor_theme/templates/layout/page.html.twig` | Governance include before dashboard content. |
| `web/themes/custom/myeventlane_vendor_theme/templates/includes/mel-vendor-dashboard-governance-stack.html.twig` | **New** — governed regions. |
| `web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard.scss` | Governance stack layout styles. |
| `docs/architecture/mel-observability-system.md` | Document permission + sanitizer. |

## Validation

- `php -l` on modified PHP files — pass.
- `composer validate` — pass.
- `npm run mel:lint` — pass.
- `npm run mel:build` — pass.
- `ddev drush cr` — pass.

## Residual risk

- Grant **`access mel observability diagnostics`** only to trusted staff roles (administrator role with `is_admin: true` already implies all permissions).
- `MelObservabilityTraceSanitizer` uses pattern heuristics; novel secret formats should extend the sanitizer if they appear in alter hooks.
- Vendor dashboard KPI cards remain on the existing TASK 5 template; wrapping them in `mel_metric_grid` is a follow-up if product wants full MELDataPresentationSystem shells on those metrics.
