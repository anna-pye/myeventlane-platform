<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Single canonical catalogue of MEL observability / explainability contracts.
 */
final class MelObservabilityRegistry {

  /**
   * @return array<string, ObservabilityDefinition>
   */
  public function all(): array {
    $all = [];
    foreach ($this->definitions() as $def) {
      $all[$def->id] = $def;
    }
    ksort($all);
    return $all;
  }

  /**
   * @return list<ObservabilityDefinition>
   */
  private function definitions(): array {
    $s = MelObservabilityVisibilityLevel::Minimal;
    $o = MelObservabilityVisibilityLevel::Operational;
    $d = MelObservabilityVisibilityLevel::Diagnostic;

    $customer_vendor_staff = [MelSurfaceId::Customer, MelSurfaceId::Vendor, MelSurfaceId::Staff];
    $vendor_staff = [MelSurfaceId::Vendor, MelSurfaceId::Staff];
    $staff_only = [MelSurfaceId::Staff];
    $customer_auth = [MelSurfaceId::Customer, MelSurfaceId::Auth];
    $experience_surfaces = [MelSurfaceId::Customer, MelSurfaceId::Vendor, MelSurfaceId::Auth, MelSurfaceId::Staff];

    return [
      new ObservabilityDefinition(
        id: 'mel.obs.experience.cross_surface_continuity',
        category: MelObservabilityTraceCategory::Experience,
        sourceSystems: ['MELExperienceSystem', 'MELWorkflowSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Opaque transition hints and workflow edges only; no routing or entity payloads.',
        privacyBoundaries: 'No cross-account hints; no Commerce payment context.',
        surfaceVisibility: $experience_surfaces,
        accessibilityNotes: 'Summaries as plain text; optional details behind disclosure on staff.',
        sortOrder: 10,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.experience.escalation_continuity',
        category: MelObservabilityTraceCategory::Experience,
        sourceSystems: ['MELExperienceSystem', 'MELStateSystem'],
        minimumVisibility: $s,
        explainabilityBoundaries: 'Boolean escalation posture only; no moderation queue or reasoning.',
        privacyBoundaries: 'Vendor/customer scoped; staff diagnostics stay staff-only.',
        surfaceVisibility: [MelSurfaceId::Customer, MelSurfaceId::Vendor, MelSurfaceId::Staff],
        accessibilityNotes: 'Use calm, non-alarming copy; polite live region if values change.',
        sortOrder: 20,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.experience.resume_continuity',
        category: MelObservabilityTraceCategory::Experience,
        sourceSystems: ['MELExperienceSystem'],
        minimumVisibility: $s,
        explainabilityBoundaries: 'Resume candidate presence and ranking keys only; no PII.',
        privacyBoundaries: 'Continuation memory keys are non-sensitive contracts only.',
        surfaceVisibility: $customer_auth,
        accessibilityNotes: 'Single primary continuation when possible.',
        sortOrder: 30,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.intelligence.engagement_orchestration',
        category: MelObservabilityTraceCategory::Intelligence,
        sourceSystems: ['MELIntelligenceSystem', 'MELWorkflowSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Density caps, de-dupe rules, and orchestration kinds — no messaging sends.',
        privacyBoundaries: 'No hidden scores; ordering uses published tier/weight/id sort.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Staff panel may list ids; vendor/customer get summaries only.',
        sortOrder: 40,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.intelligence.prioritisation',
        category: MelObservabilityTraceCategory::Intelligence,
        sourceSystems: ['MELIntelligenceSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Reuses prioritisation_trace from intelligence payload; deterministic sort key.',
        privacyBoundaries: 'Never exposes recommendation scoring beyond tier/weight.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Ordered list readable as table on staff; summary line elsewhere.',
        sortOrder: 50,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.intelligence.recommendation_ordering',
        category: MelObservabilityTraceCategory::Intelligence,
        sourceSystems: ['MELIntelligenceSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Explains relative rank using existing trace rows only.',
        privacyBoundaries: 'No cross-account comparisons.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Pair with intelligence region landmark.',
        sortOrder: 60,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.intelligence.suppression',
        category: MelObservabilityTraceCategory::Intelligence,
        sourceSystems: ['MELIntelligenceSystem', 'MELOperationalPolicySystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Suppression reasons are categorical (checkout, policy, density).',
        privacyBoundaries: 'No moderation reasoning; no trust numeric thresholds.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Suppressions described as outcomes, not accusations.',
        sortOrder: 70,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.policy.automation_boundary',
        category: MelObservabilityTraceCategory::Policy,
        sourceSystems: ['MELOperationalPolicySystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Automation interpretation flags from policy helper only.',
        privacyBoundaries: 'No enforcement bypass; interpretation for UX tone.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Policy region uses polite landmark from policy accessibility helper.',
        sortOrder: 80,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.policy.communication',
        category: MelObservabilityTraceCategory::Policy,
        sourceSystems: ['MELOperationalPolicySystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Tone hints and communication policy labels only.',
        privacyBoundaries: 'No private message content.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Hints are supplementary to visible headings.',
        sortOrder: 90,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.policy.suppression',
        category: MelObservabilityTraceCategory::Policy,
        sourceSystems: ['MELOperationalPolicySystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Active suppression map keys; staff may see registry snapshot separately.',
        privacyBoundaries: 'Staff registry snapshot never leaves staff surface.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Avoid empty-error affordances when guidance suppressed.',
        sortOrder: 100,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.policy.trust_threshold',
        category: MelObservabilityTraceCategory::Policy,
        sourceSystems: ['MELOperationalPolicySystem'],
        minimumVisibility: $d,
        explainabilityBoundaries: 'Trust threshold interpretation is staff-governed diagnostic only.',
        privacyBoundaries: 'Never expose numeric thresholds to vendor/public/customer payloads.',
        surfaceVisibility: $staff_only,
        accessibilityNotes: 'Diagnostics behind staff disclosure pattern.',
        sortOrder: 110,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.state.eligibility',
        category: MelObservabilityTraceCategory::State,
        sourceSystems: ['MELStateSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Feature eligibility ids are contract ids, not entitlements.',
        privacyBoundaries: 'No financial-review or moderation queue exposure.',
        surfaceVisibility: $vendor_staff,
        accessibilityNotes: 'List as definition ids or counts per surface tier.',
        sortOrder: 120,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.state.lifecycle_evaluation',
        category: MelObservabilityTraceCategory::State,
        sourceSystems: ['MELStateSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Primary lifecycle id and grouped lifecycle evaluations only.',
        privacyBoundaries: 'Checkout-sensitive routes avoid expansion in customer shell.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Customer shell: summarise without exhaustive id lists.',
        sortOrder: 130,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.state.readiness_evaluation',
        category: MelObservabilityTraceCategory::State,
        sourceSystems: ['MELStateSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Readiness summaries from MelReadinessHelper contracts.',
        privacyBoundaries: 'No hidden enforcement systems.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Readable sentences; avoid raw signal dumps on customer.',
        sortOrder: 140,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.state.trust_interpretation',
        category: MelObservabilityTraceCategory::State,
        sourceSystems: ['MELStateSystem', 'MELOperationalPolicySystem'],
        minimumVisibility: $d,
        explainabilityBoundaries: 'Trust indicator contract ids for staff diagnostics only.',
        privacyBoundaries: 'Vendor/customer shells receive counts or omit entirely.',
        surfaceVisibility: $staff_only,
        accessibilityNotes: 'Keep trust copy non-judgmental when surfaced outside staff.',
        sortOrder: 150,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.telemetry.drupal_settings_boundary',
        category: MelObservabilityTraceCategory::TelemetryGovernance,
        sourceSystems: ['MELObservabilitySystem'],
        minimumVisibility: $d,
        explainabilityBoundaries: 'Describes what may appear in drupalSettings for MEL shells.',
        privacyBoundaries: 'No PII, scores, or moderation metadata in settings.',
        surfaceVisibility: $staff_only,
        accessibilityNotes: 'Reference documentation rows; not user alerts.',
        sortOrder: 160,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.telemetry.drupal_log_boundary',
        category: MelObservabilityTraceCategory::TelemetryGovernance,
        sourceSystems: ['MELObservabilitySystem'],
        minimumVisibility: $d,
        explainabilityBoundaries: 'Clarifies Drupal logger ownership vs MEL explainability.',
        privacyBoundaries: 'Never instructs logging PII; governance text only.',
        surfaceVisibility: $staff_only,
        accessibilityNotes: 'Static reference content.',
        sortOrder: 170,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.telemetry.twig_boundary',
        category: MelObservabilityTraceCategory::TelemetryGovernance,
        sourceSystems: ['MELObservabilitySystem'],
        minimumVisibility: $d,
        explainabilityBoundaries: 'Twig may render pre-shaped variables only.',
        privacyBoundaries: 'No ad-hoc debug dumps in templates.',
        surfaceVisibility: $staff_only,
        accessibilityNotes: 'Theme templates consume structured arrays.',
        sortOrder: 180,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.workflow.activation',
        category: MelObservabilityTraceCategory::Workflow,
        sourceSystems: ['MELWorkflowSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Active workflow ids and completion ids from workflow attachments.',
        privacyBoundaries: 'No onboarding entity payloads.',
        surfaceVisibility: $customer_vendor_staff,
        accessibilityNotes: 'Pair with workflow region landmarks.',
        sortOrder: 190,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.workflow.continuity_activation',
        category: MelObservabilityTraceCategory::Workflow,
        sourceSystems: ['MELWorkflowSystem', 'MELExperienceSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Signals that continuity helpers consumed workflow edges.',
        privacyBoundaries: 'No cross-account workflow comparisons.',
        surfaceVisibility: $experience_surfaces,
        accessibilityNotes: 'Operational summaries on vendor; terse on customer.',
        sortOrder: 200,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.workflow.cta_suppression',
        category: MelObservabilityTraceCategory::Workflow,
        sourceSystems: ['MELWorkflowSystem', 'MELStateSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'CTA governance flags from state interpretation (defer/hide).',
        privacyBoundaries: 'No Commerce payment or refund internals.',
        surfaceVisibility: $vendor_staff,
        accessibilityNotes: 'Explain suppressions as governance outcomes.',
        sortOrder: 210,
      ),
      new ObservabilityDefinition(
        id: 'mel.obs.workflow.onboarding_progression',
        category: MelObservabilityTraceCategory::Workflow,
        sourceSystems: ['MELWorkflowSystem'],
        minimumVisibility: $o,
        explainabilityBoundaries: 'Progress checklist presence derived from workflow manager output.',
        privacyBoundaries: 'Vendor onboarding state remains permission-governed elsewhere.',
        surfaceVisibility: [MelSurfaceId::Customer, MelSurfaceId::Vendor, MelSurfaceId::Staff],
        accessibilityNotes: 'Checklist region labelled in workflow accessibility helper.',
        sortOrder: 220,
      ),
    ];
  }

}
