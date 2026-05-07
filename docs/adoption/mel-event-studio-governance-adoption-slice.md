# Event Studio — server-governed governance adoption slice

This document is the deliverable bundle for **server-governed Event Studio payload adoption**: one facade consumes existing MEL surface managers; Twig renders governance regions; JS defers orchestration ordering to the server payload where enabled.

## 1. Event Studio governance payload map

| Region / client key | Source system | PHP entry | JS / Twig |
| --- | --- | --- | --- |
| `state` (readiness lines) | MELStateSystem | `MelStateManager::buildPageInterpretation()` | Twig: `mel-state-summary`; `drupalSettings.melEventStudioGovernance.publishReadinessLead` |
| `workflow_primary` | MELWorkflowSystem | `MelWorkflowManager::buildPageAttachments()` → `region_primary` | Twig: `mel-workflow-panel`; CTA via primary descriptor + `data-mel-primary-cta-href` |
| `experience` | MELExperienceSystem | `MelExperienceManager::buildPageAttachments($policy)` | Twig: `mel-experience-panel`; JS `experience` summary (counts only) |
| `intelligence` | MELIntelligenceSystem | `MelIntelligenceManager::buildPagePayload($experience, $policy)` | Twig: `@myeventlane_surface/components/mel-intelligence-panel.html.twig`; JS `checklist` + `nextBestText` |
| `policy` | MELOperationalPolicySystem | `MelOperationalPolicyManager::buildPageInterpretation()` | Twig: `mel-operational-policy-panel` (translated hint lines only); JS `policy.surface_profile`, `reduced_urgency` |
| `observability_panel` | MELObservabilitySystem | `MelObservabilityManager::buildPagePayload(...)` + diagnostics gate | Twig: staff-only `#theme` render array; JS `observabilityTier` mirrors negotiator-style attachment when non-empty |
| **Cache** | — | Merged `#cache` from all subsystems + `node:{nid}` | `EventStudioForm` + `CacheableMetadata::applyTo($form)` |

**Order of composition** (matches `SurfaceNegotiator` memoisation): workflow context is implied by route; policy before experience; experience + policy before intelligence; observability last with full prior payloads.

## 2. Governance adoption report

- **Dependency pattern**: `myeventlane_event_studio.info.yml` adds `optional_dependencies: myeventlane_surface` (same class as vendor dashboard), with all manager arguments as `@?` in `myeventlane_event_studio.governance_builder` so the module stays installable if surface is off.
- **Single facade**: `EventStudioGovernanceBuilder` only calls existing managers; it does not re-rank, re-suppress, or add business rules.
- **Attachment**: `EventStudioForm` merges cacheability, sets `#mel_studio_governance` for Twig, and `drupalSettings.melEventStudioGovernance` for JS. When enabled, surface libraries `interactions`, `experience`, `intelligence`, `operational_policy`, and conditionally `observability` are attached (aligned with `SurfaceNegotiator`).

## 3. CTA consolidation report

- **Authority**: `drupalSettings.melEventStudioGovernance.primaryCta` is built from the workflow `region_primary` link (if present) via render-array walk, else falls back to publish-step CTA with intelligence headline context.
- **JS**: `melUpdatePrimaryCta()` uses governance when `enabled`; otherwise legacy `buildStructuredInsights()` ordering. Click handler navigates to `data-mel-primary-cta-href` when set (workflow route), else field jump / publish scroll.

## 4. JS authority reduction report

- **No longer sole authority** (when governance enabled): primary CTA label/mode, next-best line text, intelligence checklist **prefix** (server rows first in fixed order), publish readiness **lead** sentence (state summaries).
- **Still client** (per slice): field-level `buildStructuredInsights()` rows **appended after** server checklist (no re-sort across the boundary); progress score, preview hints, autosave, tickets, AI diff unchanged.

## 5. Twig consolidation report

- **New regions** (when `mel_studio_governance.enabled`): `mel-state-summary`, `mel-workflow-panel`, `mel-experience-panel`, `mel-intelligence-panel-region` (includes surface template), `mel-operational-policy-panel`, `mel-observability-panel` (staff), plus `id="mel-studio-content"` on `<main>`.
- **Legacy path**: if surface optional module absent or services null, previous sidebar layout (next best + publish card only) is preserved.

## 6. Security / privacy validation

- **Vendor route**: Event Studio lives under `/vendor/events/*` → `SurfaceResolver` = **Vendor** (test added).
- **Policy**: Twig uses `hint_lines` / `lifecycle_lines` only (no raw suppression map, no registry snapshot for non-staff).
- **Intelligence**: Same items/insights as surface manager (staff-only insight rows already filtered in `MelIntelligenceManager`).
- **Observability**: Panel only when `MelObservabilityDiagnosticsAccess::mayViewDiagnostics()`; vendor receives empty traces from manager and no panel.
- **Cross-vendor**: No event-specific cross-vendor data in builder; node cache tag only for the current event.

## 7. Accessibility report

- Each new region is a `<section role="region">` with a label.
- ** aria-live **: Server intelligence panel keeps `data-mel-intelligence-live` from surface template. The collapsible “Field tips” list drops `aria-live` when governance is on to reduce duplicate polite announcements; progressive field validation still updates that list.

## 8. File-by-file implementation summary

| File | Change |
| --- | --- |
| `myeventlane_event_studio.info.yml` | `optional_dependencies` → `myeventlane_surface` |
| `myeventlane_event_studio.services.yml` | `myeventlane_event_studio.governance_builder` service (`@?` managers + `@current_user`) |
| `src/Service/EventStudioGovernanceBuilder.php` | **New** facade |
| `src/Form/EventStudioForm.php` | Inject builder; merge cache; `#mel_studio_governance`; `drupalSettings`; libraries |
| `myeventlane_event_studio.module` | Preprocess passes `mel_studio_governance` |
| `templates/mel-event-studio.html.twig` | Governance regions + legacy fallback; `mel-studio-content` id |
| `js/mel-event-studio.js` | `getGovernanceSettings`; CTA / next best / checklist / publish / click handler |
| `myeventlane_surface/.../MelSurfaceRouteGovernanceKernelTest.php` | `testEventStudioCreatePathResolvesVendorSurface` |

## Validation run (this environment)

- `php -l` on new/changed PHP: **OK**
- `composer validate --no-check-publish`: **OK**
- `../vendor/bin/phpunit -c phpunit-governance.xml --filter testEventStudioCreatePathResolvesVendorSurface`: **OK**
- `npm run mel:lint`: **OK**
- `npm run mel:build`: **OK**
- `ddev drush cr`: **OK**

## Residual risk / follow-ups

- **Dynamic form vs server payload**: Intelligence/state reflect route/session signals; live field edits still rely on appended client “field tips” until a refresh or a future autosave→payload endpoint is introduced.
- **Workflow primary empty**: Common on Event Studio when completion workflows win — CTA defaults to publish step; acceptable but worth UX monitoring.
- **Nested render cache**: Observability/intelligence subtrees carry `#cache`; top-level form merge should bubble most contexts; edge cases may need extra tagging if partial caching appears in production.
