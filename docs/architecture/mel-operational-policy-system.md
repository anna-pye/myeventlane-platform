# MELOperationalPolicySystem

Canonical **interpretation-only** governance for operational policy: trust thresholds, suppression, automation boundaries, communication hints, lifecycle safety, and explainability. Drupal permissions, moderation enforcement, Commerce, entity access, and workflow engines remain authoritative.

## 1. Architecture map

```
MelWorkflowResolver / MelStateResolver (signals + evaluations)
        │
        ▼
MelPolicyResolver + MelOperationalPolicyRegistry (activation rules)
        │
        ▼
MelOperationalPolicyManager (portable page payload + alter hook)
        ├── MelTrustThresholdHelper
        ├── MelEscalationPolicyHelper
        ├── MelSuppressionPolicyHelper
        ├── MelAutomationBoundaryHelper
        ├── MelCommunicationPolicyHelper
        └── MelPolicyAccessibilityHelper
        │
        ├──► SurfaceNegotiator → mel_operational_policy + drupalSettings
        ├──► MelExperienceManager (CTA governance hints)
        └──► MelIntelligenceManager (orchestration filter + explainability)
```

## 2. Policy registry map

| Category | Policy IDs | Resolver source |
|----------|------------|-----------------|
| Trust | `payout_review_required`, `moderation_attention_required`, `trust_warning_active`, `verified_vendor_required`, `escalation_review_required` | `MelOperationalPolicyRegistry::all()` |
| Suppression | `suppress_*` (5) | Same |
| Communication | `trust_sensitive_tone`, `escalation_tone`, `moderation_safe_tone`, `reduced_urgency_mode`, `reassurance_priority` | Same |
| Lifecycle safety | `stale_draft_protection`, `abandoned_checkout_safety`, `inactive_vendor_safeguard`, `cancelled_event_suppression` | Same |
| Automation (reference) | `allow_*`, `restrict_*` | `MelOperationalPolicyRegistry::automationReference()` + `MelAutomationBoundaryHelper` booleans |

## 3. Trust-threshold governance map

`MelTrustThresholdHelper` projects **boolean flags** from active policy ids (no scores, no queue metadata). Staff shells may combine with `registry_snapshot` for diagnostics.

## 4. Suppression governance map

`MelSuppressionPolicyHelper::suppressionMap()` merges direct suppression policies plus `suppressionImplications` from trust/lifecycle contracts. `intelligenceDefinitionIdsToSuppress()` maps to **MELIntelligenceSystem** definition ids (centralised; not Twig conditionals).

## 5. Automation-boundary governance map

`MelAutomationBoundaryHelper::interpret()` returns booleans for engagement, recovery, resume, escalation prompt restriction (public/auth), and checkout interruption density. Does not enqueue jobs or alter Commerce.

## 6. Communication-policy governance map

`MelCommunicationPolicyHelper` exposes **active tone hint keys** only. Copy remains in owning systems; hints drive CSS `data-mel-policy-tone-*` via `mel-policy.js`.

## 7. Lifecycle-safety governance map

Lifecycle policies activate from **MelStateSystem** evaluations (`draft`, `cart_ready`, `onboarding_complete`, `cancelled`, checkout route). No entity lifecycle enforcement here.

## 8. Explainability report

`explainability.lines` uses **categorical** `t()` strings (no moderation internals, no risk scores). `hook_mel_operational_policy_interpretation_alter` may append staff-only diagnostic rows if product allows.

## 9. Accessibility report

`MelPolicyAccessibilityHelper` documents polite status defaults, reduced-motion pairing, and WCAG notes. Intelligence and state accessibility helpers remain primary for panel semantics.

## 10. Duplication cleanup report

- **Centralised** intelligence suppression that would otherwise require per-template branching: governed via `MelSuppressionPolicyHelper` + `MelIntelligenceManager::applyOperationalPolicyToOrchestration()`.
- **Extended** `MelExperienceManager` CTA governance using operational policy (`experience_governance.elevate_competing_cta_suppression`) instead of new Twig checks.
- **Left intact** `MelStateManager` / `MelEscalationContinuityHelper` — they remain source signals; policy layer composes cross-cutting interpretation.

## 11. File-by-file implementation summary

| File | Role |
|------|------|
| `MelOperationalPolicyCategory.php` | Enum of policy categories |
| `MelPolicyActivationRule.php` | Activation AND-clauses |
| `OperationalPolicyDefinition.php` | Contract value object |
| `MelOperationalPolicyRegistry.php` | `all()` + `automationReference()` |
| `MelPolicyResolver.php` | Active policy id resolution |
| `MelTrustThresholdHelper.php` | Trust threshold flags |
| `MelEscalationPolicyHelper.php` | Escalation interpretation |
| `MelSuppressionPolicyHelper.php` | Suppression map + intelligence id map |
| `MelAutomationBoundaryHelper.php` | Automation booleans |
| `MelCommunicationPolicyHelper.php` | Tone hint booleans |
| `MelPolicyAccessibilityHelper.php` | WCAG-oriented metadata |
| `MelOperationalPolicyManager.php` | Page payload + alter |
| `MelExperienceManager.php` | Consumes preloaded policy |
| `MelIntelligenceManager.php` | Policy-aware orchestration |
| `SurfaceNegotiator.php` | Memoisation + attachments |
| `myeventlane_surface.services.yml` | DI wiring |
| `js/mel-policy.js` | Drupal behavior (data attributes) |
| Theme `components/_mel-*.scss` | Token-only shell hooks |

## Hooks

- `hook_mel_operational_policy_interpretation_alter(&$interpretation, $context, $merged_signals, $evaluations)`

## Assumptions

- State evaluation strings match `MelStateEvaluation::value`.
- `route.commerce_checkout` and checkout-sensitive context from existing `MelWorkflowResolver` / `SurfaceAccessHelper` remain the checkout boundary signals.
