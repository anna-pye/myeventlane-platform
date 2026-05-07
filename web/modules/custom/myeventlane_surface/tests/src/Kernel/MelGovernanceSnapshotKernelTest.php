<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Symfony\Component\Routing\Route;

/**
 * Deterministic governance snapshots (resolver + suppression only).
 *
 * Update fixtures with:
 * MEL_UPDATE_GOVERNANCE_SNAPSHOTS=1 ddev exec env SIMPLETEST_DB=sqlite://localhost/:memory: \
 *   vendor/bin/phpunit -c web/phpunit-governance.xml \
 *   web/modules/custom/myeventlane_surface/tests/src/Kernel/MelGovernanceSnapshotKernelTest.php
 *
 * @group myeventlane_surface
 * @group mel_governance_snapshots
 */
final class MelGovernanceSnapshotKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testCheckoutAnonymousPublicSnapshot(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/checkout/review', 'commerce_checkout.review', new Route('/checkout/review'));

    /** @var \Drupal\myeventlane_surface\MelStateResolver $state_resolver */
    $state_resolver = $this->container->get('myeventlane_surface.state_resolver');
    $workflow_context = $state_resolver->buildWorkflowContext();
    $merged = $state_resolver->mergeDomainSignals($workflow_context);
    $evaluations = [];
    foreach ($this->container->get('myeventlane_surface.state_registry')->all() as $sid => $definition) {
      $evaluations[$sid] = $state_resolver->evaluate($definition, $workflow_context, $merged)->value;
    }
    /** @var \Drupal\myeventlane_surface\MelPolicyResolver $policy_resolver */
    $policy_resolver = $this->container->get('myeventlane_surface.policy_resolver');
    $active = $policy_resolver->resolveActivePolicyIds($workflow_context, $merged, $evaluations);
    /** @var \Drupal\myeventlane_surface\MelSuppressionPolicyHelper $suppression_helper */
    $suppression_helper = $this->container->get('myeventlane_surface.policy_suppression_helper');
    $suppression = $suppression_helper->suppressionMap($active);
    $intel_ids = $suppression_helper->intelligenceDefinitionIdsToSuppress($suppression, $workflow_context->surface);

    $stub_interpretation = [
      'surface_policy_profile' => $workflow_context->checkoutSensitive ? 'checkout_minimal_reassuring' : 'public_surface_minimal_trust',
      'active_policy_ids' => $active,
      'suppression' => $suppression,
      'intelligence_governance' => [
        'suppress_definition_ids' => $intel_ids,
        'orchestration_cap_reduction' => 0,
      ],
      'experience_governance' => [
        'elevate_competing_cta_suppression' => !empty($suppression['suppress_cross_sell_guidance'])
          || !empty($suppression['suppress_marketing_guidance']),
      ],
      'privacy' => [
        'vendor_isolation' => TRUE,
        'no_moderation_internals' => TRUE,
        'no_cross_account_signals' => TRUE,
        'staff_registry_visible' => FALSE,
      ],
    ];

    /** @var \Drupal\myeventlane_surface\MelGovernancePayloadInspector $inspector */
    $inspector = $this->container->get('myeventlane_surface.governance_payload_inspector');
    $normalized = $inspector->normalizeOperationalPolicyInterpretation($stub_interpretation);

    $fixture = dirname(__DIR__, 2) . '/fixtures/governance_snapshots/checkout_anonymous_public.json';
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    if (getenv('MEL_UPDATE_GOVERNANCE_SNAPSHOTS')) {
      if (!is_dir(dirname($fixture))) {
        mkdir(dirname($fixture), 0775, TRUE);
      }
      file_put_contents($fixture, $encoded);
      $this->addToAssertionCount(1);
    }
    else {
      $this->assertFileExists($fixture);
      $this->assertSame(trim(file_get_contents($fixture)), trim($encoded));
    }

    $this->popRequest();
  }

}
