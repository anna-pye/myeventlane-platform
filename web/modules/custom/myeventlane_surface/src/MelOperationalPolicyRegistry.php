<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Single canonical catalogue of MEL operational policy interpretation contracts.
 */
final class MelOperationalPolicyRegistry {

  /**
   * @return array<string, \Drupal\myeventlane_surface\OperationalPolicyDefinition>
   */
  public function all(): array {
    $s = static fn (MelSurfaceId ...$ids): array => array_map(
      static fn (MelSurfaceId $id): string => $id->value,
      $ids,
    );

    return [
      // --- Trust policy ------------------------------------------------------
      'payout_review_required' => new OperationalPolicyDefinition(
        id: 'payout_review_required',
        category: MelOperationalPolicyCategory::Trust,
        sourceSystems: ['stripe_connect', 'commerce', 'mel_state'],
        interpretationRuleSummary: 'Vendor/staff payout readiness is not satisfied; Commerce remains authoritative for payouts.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateUnsatisfied: ['payout_ready'],
          ),
        ],
        suppressionImplications: ['suppress_marketing_guidance', 'suppress_nonessential_notifications'],
        ctaImplications: ['Defer growth prompts; elevate factual payout next steps from existing Commerce flows.'],
        escalationImplications: ['Treat as trust-sensitive continuity; no new enforcement paths.'],
        communicationImplications: ['trust_sensitive_tone', 'reduced_urgency_mode', 'reassurance_priority'],
        privacyBoundaries: 'Never expose Stripe requirement hashes, bank metadata, or internal review scores.',
        accessibilityNotes: 'Use status semantics and plain-language next steps; avoid alarmist alert roles.',
      ),
      'moderation_attention_required' => new OperationalPolicyDefinition(
        id: 'moderation_attention_required',
        category: MelOperationalPolicyCategory::Trust,
        sourceSystems: ['moderation', 'mel_state'],
        interpretationRuleSummary: 'Active moderation hold or vendor/staff trust flag requires attention-oriented UX only.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold']),
          new MelPolicyActivationRule(
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateSatisfied: ['flagged'],
            requireAuthentication: TRUE,
          ),
        ],
        suppressionImplications: ['suppress_recommendations', 'suppress_boost_prompts', 'suppress_cross_sell_guidance'],
        ctaImplications: ['Defer competing primary CTAs where MELWorkflowSystem already defers; never bypass moderation routes.'],
        escalationImplications: ['Prefer continuity and resolution entry points owned by moderation/support modules.'],
        communicationImplications: ['trust_sensitive_tone', 'moderation_safe_tone'],
        privacyBoundaries: 'Do not expose queue positions, reviewer identities, or investigation notes.',
        accessibilityNotes: 'Prefer polite live regions; reserve assertive announcements for blocking errors only.',
      ),
      'trust_warning_active' => new OperationalPolicyDefinition(
        id: 'trust_warning_active',
        category: MelOperationalPolicyCategory::Trust,
        sourceSystems: ['moderation', 'support', 'mel_state'],
        interpretationRuleSummary: 'Coarse trust warning interpretation for eligible shells only.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateSatisfied: ['flagged'],
          ),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: [MelSurfaceId::Customer->value],
            anyOfStateSatisfied: ['support_attention'],
          ),
        ],
        suppressionImplications: ['suppress_marketing_guidance'],
        ctaImplications: ['Elevate help/support CTAs moderately where workflow contracts allow.'],
        escalationImplications: [],
        communicationImplications: ['trust_sensitive_tone', 'reassurance_priority'],
        privacyBoundaries: 'Customer path uses support_attention only; never mirror staff diagnostics.',
        accessibilityNotes: 'Pair warnings with explicit next actions; maintain keyboard focus order.',
      ),
      'verified_vendor_required' => new OperationalPolicyDefinition(
        id: 'verified_vendor_required',
        category: MelOperationalPolicyCategory::Trust,
        sourceSystems: ['moderation', 'mel_state'],
        interpretationRuleSummary: 'Vendor shell where verified vendor trust signal is not satisfied.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: [MelSurfaceId::Vendor->value],
            anyOfStateUnsatisfied: ['verified_vendor'],
          ),
        ],
        suppressionImplications: ['suppress_boost_prompts'],
        ctaImplications: ['Keep listing/publish decisions with existing moderation and access checks.'],
        escalationImplications: [],
        communicationImplications: ['trust_sensitive_tone'],
        privacyBoundaries: 'Public shells must not surface unpublished verification detail.',
        accessibilityNotes: 'Verification prompts remain descriptive, not score-based.',
      ),
      'escalation_review_required' => new OperationalPolicyDefinition(
        id: 'escalation_review_required',
        category: MelOperationalPolicyCategory::Trust,
        sourceSystems: ['support', 'moderation', 'mel_state'],
        interpretationRuleSummary: 'Escalation review signal from trust state or support workflow alias.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['escalation_review']),
          new MelPolicyActivationRule(anyOfMergedTruthy: ['support.flags.escalation']),
        ],
        suppressionImplications: ['suppress_recommendations', 'suppress_boost_prompts', 'suppress_marketing_guidance', 'suppress_nonessential_notifications'],
        ctaImplications: ['Reduce competing prompts; emphasise resolution continuity from workflow engines.'],
        escalationImplications: ['Escalation continuity overrides engagement density hints.'],
        communicationImplications: ['escalation_tone', 'reduced_urgency_mode', 'trust_sensitive_tone'],
        privacyBoundaries: 'No cross-account escalation reasons; staff diagnostics stay staff-only.',
        accessibilityNotes: 'Differentiate urgent operational errors from policy-driven tone shifts.',
      ),

      // --- Suppression policy -------------------------------------------------
      'suppress_recommendations' => new OperationalPolicyDefinition(
        id: 'suppress_recommendations',
        category: MelOperationalPolicyCategory::Suppression,
        sourceSystems: ['mel_state', 'mel_workflow', 'mel_intelligence'],
        interpretationRuleSummary: 'Suppress recommendation-style intelligence when trust/checkout contexts demand minimal discovery.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold', 'escalation_review']),
          new MelPolicyActivationRule(
            requireCheckoutSensitive: TRUE,
            surfacesAny: $s(MelSurfaceId::Customer, MelSurfaceId::Public, MelSurfaceId::Vendor, MelSurfaceId::Auth),
          ),
        ],
        suppressionImplications: [],
        ctaImplications: ['Hide cross-sell and discovery prompts in MELIntelligenceSystem output.'],
        escalationImplications: ['Escalation contexts cap density before recommendations return.'],
        communicationImplications: [],
        privacyBoundaries: 'Suppression reasons are categorical, never moderation-queue specific.',
        accessibilityNotes: 'When panels empty, maintain landmark structure so assistive tech does not jump.',
      ),
      'suppress_marketing_guidance' => new OperationalPolicyDefinition(
        id: 'suppress_marketing_guidance',
        category: MelOperationalPolicyCategory::Suppression,
        sourceSystems: ['mel_state', 'mel_intelligence', 'mel_experience'],
        interpretationRuleSummary: 'Suppress marketing tone prompts during payout review, moderation, escalation, or checkout.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold', 'escalation_review']),
          new MelPolicyActivationRule(requireCheckoutSensitive: TRUE),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateUnsatisfied: ['payout_ready'],
          ),
        ],
        suppressionImplications: [],
        ctaImplications: ['Marketing CTAs remain permission-governed; interpretation hides soft prompts only.'],
        escalationImplications: [],
        communicationImplications: ['reduced_urgency_mode'],
        privacyBoundaries: 'No financial-review narrative beyond what Commerce already exposes to the account.',
        accessibilityNotes: 'Avoid flashing promotional chrome under reduced motion.',
      ),
      'suppress_boost_prompts' => new OperationalPolicyDefinition(
        id: 'suppress_boost_prompts',
        category: MelOperationalPolicyCategory::Suppression,
        sourceSystems: ['mel_state', 'mel_intelligence'],
        interpretationRuleSummary: 'Suppress boost prompts during moderation, escalation, or payout readiness gaps.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold', 'escalation_review']),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: [MelSurfaceId::Vendor->value],
            anyOfStateUnsatisfied: ['payout_ready'],
          ),
        ],
        suppressionImplications: [],
        ctaImplications: ['Interpretation aligns with MELStateManager boost CTA governance.'],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'Boost eligibility facts remain server-side; do not leak internal campaign flags.',
        accessibilityNotes: 'When suppressed, avoid empty placeholders that look like errors.',
      ),
      'suppress_nonessential_notifications' => new OperationalPolicyDefinition(
        id: 'suppress_nonessential_notifications',
        category: MelOperationalPolicyCategory::Suppression,
        sourceSystems: ['mel_state', 'mel_interaction'],
        interpretationRuleSummary: 'Reduce nonessential notification density during payout review, escalation, or payment processing.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold', 'escalation_review', 'payment_processing', 'refund_pending']),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateUnsatisfied: ['payout_ready'],
          ),
        ],
        suppressionImplications: [],
        ctaImplications: ['Prefer single primary status channel per screen.'],
        escalationImplications: [],
        communicationImplications: ['reduced_urgency_mode'],
        privacyBoundaries: 'Do not expose processor timelines as guarantees.',
        accessibilityNotes: 'Stack polite updates; avoid competing assertive regions.',
      ),
      'suppress_cross_sell_guidance' => new OperationalPolicyDefinition(
        id: 'suppress_cross_sell_guidance',
        category: MelOperationalPolicyCategory::Suppression,
        sourceSystems: ['mel_state', 'mel_workflow', 'mel_intelligence'],
        interpretationRuleSummary: 'Suppress cross-sell prompts during checkout-sensitive routes or trust holds.',
        activationRules: [
          new MelPolicyActivationRule(requireCheckoutSensitive: TRUE),
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold', 'escalation_review']),
        ],
        suppressionImplications: [],
        ctaImplications: ['Aligns with MELExperienceSystem competing CTA suppression hints.'],
        escalationImplications: [],
        communicationImplications: ['reduced_urgency_mode'],
        privacyBoundaries: 'Checkout interpretation never reads other customers’ carts.',
        accessibilityNotes: 'Keep checkout step summaries as the primary reading order.',
      ),

      // --- Communication policy ---------------------------------------------
      'trust_sensitive_tone' => new OperationalPolicyDefinition(
        id: 'trust_sensitive_tone',
        category: MelOperationalPolicyCategory::Communication,
        sourceSystems: ['mel_state'],
        interpretationRuleSummary: 'Hint: prefer trust-sensitive copy patterns (no generated prose).',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['moderation_hold', 'flagged']),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateUnsatisfied: ['payout_ready'],
          ),
        ],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'Hints are categorical; never include moderation narratives.',
        accessibilityNotes: 'Plain language; sufficient context in headings.',
      ),
      'escalation_tone' => new OperationalPolicyDefinition(
        id: 'escalation_tone',
        category: MelOperationalPolicyCategory::Communication,
        sourceSystems: ['support', 'mel_state'],
        interpretationRuleSummary: 'Hint: escalation-safe phrasing for eligible shells.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['escalation_review']),
          new MelPolicyActivationRule(anyOfMergedTruthy: ['support.flags.escalation']),
        ],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'No ticket contents or investigator notes.',
        accessibilityNotes: 'Prefer status role over alert when no immediate user action is required.',
      ),
      'moderation_safe_tone' => new OperationalPolicyDefinition(
        id: 'moderation_safe_tone',
        category: MelOperationalPolicyCategory::Communication,
        sourceSystems: ['moderation', 'mel_state'],
        interpretationRuleSummary: 'Hint: moderation-safe reassurance when hold is inactive.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            allOfStateSatisfied: ['moderation_safe'],
            noneOfStateSatisfied: ['moderation_hold'],
          ),
        ],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'Positive coarse signal only; no investigation detail.',
        accessibilityNotes: 'Positive trust chips include visible text labels.',
      ),
      'reduced_urgency_mode' => new OperationalPolicyDefinition(
        id: 'reduced_urgency_mode',
        category: MelOperationalPolicyCategory::Communication,
        sourceSystems: ['commerce_checkout', 'mel_state', 'mel_workflow'],
        interpretationRuleSummary: 'Hint: reduce urgency and promotional cadence.',
        activationRules: [
          new MelPolicyActivationRule(requireCheckoutSensitive: TRUE),
          new MelPolicyActivationRule(anyOfStateSatisfied: ['payment_processing']),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateUnsatisfied: ['payout_ready'],
          ),
        ],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'Does not describe payment risk scores.',
        accessibilityNotes: 'Avoid countdown timers that pressure; respect reduced motion.',
      ),
      'reassurance_priority' => new OperationalPolicyDefinition(
        id: 'reassurance_priority',
        category: MelOperationalPolicyCategory::Communication,
        sourceSystems: ['mel_state', 'commerce_order'],
        interpretationRuleSummary: 'Customer shell reassurance emphasis during checkout or support signals.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: [MelSurfaceId::Customer->value],
            anyOfStateSatisfied: ['support_attention', 'refund_pending'],
          ),
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: [MelSurfaceId::Customer->value],
            requireCheckoutSensitive: TRUE,
          ),
        ],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'Customer-specific; never shared across accounts.',
        accessibilityNotes: 'Reassurance blocks precede secondary marketing links.',
      ),

      // --- Lifecycle safety policy ------------------------------------------
      'stale_draft_protection' => new OperationalPolicyDefinition(
        id: 'stale_draft_protection',
        category: MelOperationalPolicyCategory::LifecycleSafety,
        sourceSystems: ['drupal_node', 'mel_state'],
        interpretationRuleSummary: 'Vendor/staff interpretation when an event draft lifecycle signal is active.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: $s(MelSurfaceId::Vendor, MelSurfaceId::Staff),
            anyOfStateSatisfied: ['draft'],
          ),
        ],
        suppressionImplications: ['suppress_boost_prompts'],
        ctaImplications: ['Publish and sales CTAs follow publishable interpretation and permissions.'],
        escalationImplications: [],
        communicationImplications: ['reduced_urgency_mode'],
        privacyBoundaries: 'Draft titles and IDs stay within access-controlled views.',
        accessibilityNotes: 'Describe draft state explicitly in headings.',
      ),
      'abandoned_checkout_safety' => new OperationalPolicyDefinition(
        id: 'abandoned_checkout_safety',
        category: MelOperationalPolicyCategory::LifecycleSafety,
        sourceSystems: ['commerce_cart', 'commerce_checkout', 'mel_state'],
        interpretationRuleSummary: 'Checkout route with cart readiness not satisfied — recovery-oriented UX hints only.',
        activationRules: [
          new MelPolicyActivationRule(
            requireCheckoutSensitive: TRUE,
            anyOfMergedTruthy: ['route.commerce_checkout'],
            anyOfStateUnsatisfied: ['cart_ready'],
          ),
        ],
        suppressionImplications: ['suppress_cross_sell_guidance', 'suppress_marketing_guidance'],
        ctaImplications: ['Commerce retry controls remain primary; MEL does not inject payment steps.'],
        escalationImplications: [],
        communicationImplications: ['reassurance_priority', 'reduced_urgency_mode'],
        privacyBoundaries: 'No cart line item detail in policy payload.',
        accessibilityNotes: 'Recovery hints use polite live regions when used.',
      ),
      'inactive_vendor_safeguard' => new OperationalPolicyDefinition(
        id: 'inactive_vendor_safeguard',
        category: MelOperationalPolicyCategory::LifecycleSafety,
        sourceSystems: ['onboarding', 'mel_state'],
        interpretationRuleSummary: 'Vendor onboarding not complete — resume-oriented interpretation.',
        activationRules: [
          new MelPolicyActivationRule(
            requireAuthentication: TRUE,
            surfacesAny: [MelSurfaceId::Vendor->value],
            anyOfStateUnsatisfied: ['onboarding_complete'],
          ),
        ],
        suppressionImplications: ['suppress_boost_prompts', 'suppress_recommendations'],
        ctaImplications: ['Elevate onboarding resume from existing workflow contracts.'],
        escalationImplications: [],
        communicationImplications: ['trust_sensitive_tone'],
        privacyBoundaries: 'Vendor-scoped onboarding flags only.',
        accessibilityNotes: 'Checklists enumerate remaining setup steps accessibly.',
      ),
      'cancelled_event_suppression' => new OperationalPolicyDefinition(
        id: 'cancelled_event_suppression',
        category: MelOperationalPolicyCategory::LifecycleSafety,
        sourceSystems: ['drupal_node', 'mel_state'],
        interpretationRuleSummary: 'Cancelled lifecycle — suppress growth prompts; Commerce handles refunds.',
        activationRules: [
          new MelPolicyActivationRule(anyOfStateSatisfied: ['cancelled']),
        ],
        suppressionImplications: ['suppress_boost_prompts', 'suppress_recommendations', 'suppress_cross_sell_guidance'],
        ctaImplications: ['Purchase CTAs interpreted hidden per lifecycle; access still enforced in Drupal.'],
        escalationImplications: [],
        communicationImplications: ['reassurance_priority', 'reduced_urgency_mode'],
        privacyBoundaries: 'Public cancelled listings show minimal factual status only.',
        accessibilityNotes: 'Cancelled status must be readable without colour alone.',
      ),
    ];
  }

  /**
   * Automation boundary contracts (reference; activation via MelAutomationBoundaryHelper).
   *
   * @return array<string, \Drupal\myeventlane_surface\OperationalPolicyDefinition>
   */
  public function automationReference(): array {
    return [
      'allow_engagement_guidance' => new OperationalPolicyDefinition(
        id: 'allow_engagement_guidance',
        category: MelOperationalPolicyCategory::Automation,
        sourceSystems: ['mel_state', 'mel_workflow', 'mel_intelligence'],
        interpretationRuleSummary: 'TRUE when engagement prompts are appropriate; FALSE during moderation, escalation, or commerce checkout routes.',
        activationRules: [],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: ['Escalation continuity overrides engagement when FALSE.'],
        communicationImplications: [],
        privacyBoundaries: 'Automation hints never replace permissions or Commerce gating.',
        accessibilityNotes: 'When FALSE, reduce competing prompts; preserve primary workflow landmarks.',
      ),
      'allow_recovery_guidance' => new OperationalPolicyDefinition(
        id: 'allow_recovery_guidance',
        category: MelOperationalPolicyCategory::Automation,
        sourceSystems: ['commerce_checkout', 'mel_state'],
        interpretationRuleSummary: 'FALSE while payment processing state is active.',
        activationRules: [],
        suppressionImplications: [],
        ctaImplications: ['Commerce recovery controls remain primary.'],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'No payment instrument metadata in hints.',
        accessibilityNotes: 'Recovery messaging stays polite unless Commerce surfaces blocking errors.',
      ),
      'allow_resume_prompts' => new OperationalPolicyDefinition(
        id: 'allow_resume_prompts',
        category: MelOperationalPolicyCategory::Automation,
        sourceSystems: ['mel_experience', 'mel_state'],
        interpretationRuleSummary: 'FALSE during escalation review to avoid competing continuity.',
        activationRules: [],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: ['Escalation review suppresses resume nudges.'],
        communicationImplications: [],
        privacyBoundaries: 'Resume hints are account-scoped only.',
        accessibilityNotes: 'Single primary resume landmark per view.',
      ),
      'restrict_escalation_prompts' => new OperationalPolicyDefinition(
        id: 'restrict_escalation_prompts',
        category: MelOperationalPolicyCategory::Automation,
        sourceSystems: ['mel_surface'],
        interpretationRuleSummary: 'TRUE on public/auth shells — escalation prompts must stay minimal.',
        activationRules: [],
        suppressionImplications: [],
        ctaImplications: [],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'Never expose internal escalation metadata on public surfaces.',
        accessibilityNotes: 'Public escalation entry points use clear, short labels.',
      ),
      'restrict_checkout_interruptions' => new OperationalPolicyDefinition(
        id: 'restrict_checkout_interruptions',
        category: MelOperationalPolicyCategory::Automation,
        sourceSystems: ['commerce_checkout', 'mel_workflow'],
        interpretationRuleSummary: 'TRUE on checkout-sensitive routes or payment processing.',
        activationRules: [],
        suppressionImplications: [],
        ctaImplications: ['Stripe and Commerce panes own interruption model.'],
        escalationImplications: [],
        communicationImplications: [],
        privacyBoundaries: 'No Stripe Connect internals in MEL hints.',
        accessibilityNotes: 'Avoid modal stacking during checkout-sensitive flows.',
      ),
    ];
  }

}

