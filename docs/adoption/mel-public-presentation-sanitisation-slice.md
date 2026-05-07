# Public presentation sanitisation + recommendation contract convergence

This slice establishes a **single PHP-side presentation boundary** for MEL intelligence and related governance-derived UI, without adding a new governance, observability, recommendation, or interaction framework.

## 1. Pre-implementation audit (leak paths)

| Source | Leak path | Surfaces affected | Correct tier | Sanitisation owner |
|--------|-----------|-------------------|--------------|-------------------|
| `MelIntelligenceManager::buildItem()` | `trigger_signal_keys`, `why_shown`, `state_snapshot`, registry metadata on each item | Any page with `mel_intelligence_panel` (public theme `page.html.twig`, vendor governance stack) | Staff: full explainability; Vendor/Customer/Public/Auth: product-safe fields only | `MelGovernancePresentationSanitizer` |
| `mel-intelligence-panel.html.twig` | `<details>` + “Why you are seeing this” + “Signals evaluated” | Same | Staff only | Template gated by `explainability_details`; sanitizer strips fields for defense in depth |
| `SurfaceNegotiator` | `mel_intelligence` page variable mirrored full orchestration payloads | Twig/preprocess consumers | Staff: retained where needed internally; themes now receive **sanitised** payload | `MelGovernancePresentationSanitizer` + negotiator wiring |
| `MelInsightHelper` | `meta.trigger` on insight rows | Panel / future consumers | Staff: full row; other tiers: headline-only projection | Sanitizer `sanitizeInsight()` |
| `EventStudioGovernanceBuilder::buildJsPayload()` | `suppressionActiveIds`, `experience.escalation_active_ids` | Event Studio governance JSON | Staff: retained; Vendor/Customer: cleared | `MelGovernancePresentationSanitizer::sanitizeEventStudioGovernanceJs()` |
| `MelGovernancePayloadInspector::buildStaffDebugSummary()` | IDs + suppression keys | `drupalSettings.melGovernanceDebug` | Staff + permission | Unchanged; negotiator passes **raw** intelligence for this path only |
| `SurfaceNegotiator` `data-mel-*` | `data-mel-state-tone`, `data-mel-experience-primary`, `data-mel-policy-profile` | `<html>` / `<body>` | Product shell hints (not signal dumps) | No change this slice; not equivalent to `trigger_signal_keys` |
| `drupalSettings.melOperationalPolicy` | Tone/automation/lifecycle hints | All shells with surface libraries | Already non-diagnostic; not suppression traces | No change |

MELObservabilitySystem traces remain **staff-gated** via existing diagnostics access; raw intelligence is still passed into `MelObservabilityManager::buildPagePayload()` for trace construction.

## 2. Tier-aware presentation contract (summary)

- **Staff**: Full intelligence items, insights `meta`, prioritisation trace, adaptive profiles, nested `operational_policy`, orchestration including suppression fields; panel shows explainability `<details>`.
- **Vendor**: Items reduced to headline/action/id/lane/category/dismissible; vendor orchestration subset without suppression fields; insights without `meta`; no nested intelligence `operational_policy`; Event Studio JS suppression/escalation id lists cleared.
- **Customer / Auth**: Same item/insight projection as vendor-tier intelligence; orchestration emptied in the page payload; no nested `operational_policy`.
- **Public**: Customer-class item shaping plus **insights forced empty** for minimal surface copy.

Canonical implementation: `MelGovernancePresentationSanitizer`.

## 3–5. Implementation notes

- **Sanitisation layer**: `web/modules/custom/myeventlane_surface/src/MelGovernancePresentationSanitizer.php`, wired in `SurfaceNegotiator` and `EventStudioGovernanceBuilder` **before** theme/JSON emission.
- **Intelligence panel**: `explainability_details` theme variable + conditional Twig; Event Studio passes the flag from the resolved surface.
- **Recommendation language**: Guest organiser prompts for analytics + events index centralised on `MelReadinessHelper::{vendorOrganiserPortalSignInTitle,…Body}`.

## 6. Guest analytics alignment

- `VendorAnalyticsViewModelBuilder::emptyGuestModel()` and `VendorEventIndexViewModelBuilder::guestModel()` now share the same readiness helper vocabulary for titles/messages (action labels remain route-specific where needed).

## 7. Interaction authority reduction plan (no rewrite)

| Target | Migration | Exception / keep | Priority |
|--------|-----------|------------------|----------|
| `mel_modal` + overlay | Prefer native `<dialog>` for simple confirmations once browser matrix is verified | Complex multi-step flows stay on `mel_modal` until parity | Medium |
| `mel_drawer` | Consolidate mobile filters vs vendor workspace drawers | Checkout trust overlays: keep isolated | Medium |
| Loading | Reuse `mel-empty-loading` / `mel-processing-state` contracts | Event Studio field tips list: governed, no second spinner taxonomy | Low |
| Overlay duplication | Inventory `mel-modal` + inline `role="dialog"` in themes | Document exceptions in MELObservability registry | Low |

**Exception registry (initial)**: checkout trust flow (`data-mel-checkout-trust`), Event Studio governance regions (`data-mel-governance-component`), staff observability panel.

## 8. Accessibility

- Non-staff panels keep `aria-live="polite"` on the items region; insights region label switches to **“Helpful reminders”** when staff explainability is off.
- Staff explainability remains in a native `<details>` with unchanged semantics.

## 9. Performance / cacheability

- One sanitiser pass per presentation use (negotiator page + Event Studio bundle); observability continues to memoise raw intelligence via existing negotiator memo.
- Cache metadata remains merged from **unsanitised** intelligence payload in the negotiator so contexts/tags do not narrow incorrectly.

## 10. Template parity hardening

- `mel-template-parity.json` adds `governance_leakage_guard` and `scripts/governance/template-parity-audit.php` scans custom **theme** Twig for forbidden explainability fragments (`Why you are seeing this`, `Signals evaluated`, `trigger_signal_keys`). Module canonical templates remain staff-capable.

## 11. Stale legacy cleanup

- Removed reliance on Twig-only hiding for intelligence explainability; no duplicate recommendation strings were added beyond Readiness helper centralisation.

## 12. File touch list

- `MelGovernancePresentationSanitizer.php` (new), `SurfaceNegotiator.php`, `myeventlane_surface.services.yml`, `myeventlane_surface.module`, `mel-intelligence-panel.html.twig`
- `EventStudioGovernanceBuilder.php`, `EventStudioGovernanceComponentBuilder.php`, `myeventlane_event_studio.services.yml`
- `MelReadinessHelper.php`, `VendorAnalyticsViewModelBuilder.php`, `VendorEventIndexViewModelBuilder.php`, `myeventlane_vendor.services.yml`
- Tests: `MelGovernancePresentationSanitizerTest.php`, `EventStudioGovernanceComponentBuilderTest.php`, `phpunit-governance.xml`
- Tooling: `mel-template-parity.json`, `scripts/governance/template-parity-audit.php`

## Manual smoke (targeted)

- **Public / customer / vendor**: View source / DOM — no `trigger_signal_keys` text, no “Signals evaluated” / “Why you are seeing this” for non-staff accounts.
- **Staff** (governance debug + diagnostics permissions): Observability + explainability details still available; `melGovernanceDebug` still populated from raw payload.
