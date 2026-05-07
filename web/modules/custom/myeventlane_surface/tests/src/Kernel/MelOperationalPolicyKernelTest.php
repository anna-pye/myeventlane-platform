<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelStateEvaluation;
use Drupal\myeventlane_surface\MelSurfaceId;
use Drupal\myeventlane_surface\MelWorkflowContext;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\Routing\Route;

/**
 * MELOperationalPolicySystem registry-driven interpretation (no duplicated rules).
 *
 * @group myeventlane_surface
 */
final class MelOperationalPolicyKernelTest extends MelSurfaceGovernanceKernelTestBase {

  use UserCreationTrait;

  public function testCheckoutSensitiveSurfaceActivatesSuppressRecommendations(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/checkout/review', 'commerce_checkout.review', new Route('/checkout/review'));

    $workflow_context = $this->container->get('myeventlane_surface.workflow_resolver')->buildContext();
    $this->assertTrue($workflow_context->checkoutSensitive);
    $this->assertSame(MelSurfaceId::Public, $workflow_context->surface);

    /** @var \Drupal\myeventlane_surface\MelStateResolver $state_resolver */
    $state_resolver = $this->container->get('myeventlane_surface.state_resolver');
    $merged = $state_resolver->mergeDomainSignals($workflow_context);
    $evaluations = [];
    foreach ($this->container->get('myeventlane_surface.state_registry')->all() as $sid => $definition) {
      $evaluations[$sid] = $state_resolver->evaluate($definition, $workflow_context, $merged)->value;
    }

    /** @var \Drupal\myeventlane_surface\MelPolicyResolver $policy_resolver */
    $policy_resolver = $this->container->get('myeventlane_surface.policy_resolver');
    $active = $policy_resolver->resolveActivePolicyIds($workflow_context, $merged, $evaluations);
    $this->assertContains('suppress_recommendations', $active);

    /** @var \Drupal\myeventlane_surface\MelSuppressionPolicyHelper $helper */
    $helper = $this->container->get('myeventlane_surface.policy_suppression_helper');
    $map = $helper->suppressionMap($active);
    $this->assertTrue($map['suppress_recommendations']);
    $hidden = $helper->intelligenceDefinitionIdsToSuppress($map, MelSurfaceId::Public);
    $this->assertContains('recommended_events', $hidden);

    /** @var \Drupal\myeventlane_surface\MelEscalationPolicyHelper $escalation */
    $escalation = $this->container->get('myeventlane_surface.policy_escalation_helper');
    $this->assertArrayHasKey(
      'continuity_overrides_engagement',
      $escalation->interpret($active, $merged),
    );

    $this->popRequest();
  }

  public function testModerationHoldActivatesModerationAttentionAndSuppressionMap(): void {
    /** @var \Drupal\myeventlane_surface\MelPolicyResolver $resolver */
    $resolver = $this->container->get('myeventlane_surface.policy_resolver');
    $user = $this->createUser();

    $evaluations = ['moderation_hold' => MelStateEvaluation::Satisfied->value];
    $context = new MelWorkflowContext(
      MelSurfaceId::Vendor,
      'myeventlane_vendor.console.dashboard',
      '/vendor/dashboard',
      $user,
      FALSE,
      ['surface.vendor' => TRUE],
    );

    $active = $resolver->resolveActivePolicyIds($context, [], $evaluations);
    $this->assertContains('moderation_attention_required', $active);

    /** @var \Drupal\myeventlane_surface\MelSuppressionPolicyHelper $helper */
    $helper = $this->container->get('myeventlane_surface.policy_suppression_helper');
    $map = $helper->suppressionMap($active);
    $this->assertTrue($map['suppress_recommendations']);
    $this->assertTrue($map['suppress_boost_prompts']);

    $hidden = $helper->intelligenceDefinitionIdsToSuppress($map, MelSurfaceId::Vendor);
    $this->assertContains('boost_event_prompt', $hidden);
  }

  public function testEscalationReviewSuppressesEngagementHints(): void {
    /** @var \Drupal\myeventlane_surface\MelPolicyResolver $resolver */
    $resolver = $this->container->get('myeventlane_surface.policy_resolver');
    $user = $this->createUser();

    $evaluations = ['escalation_review' => MelStateEvaluation::Satisfied->value];
    $context = new MelWorkflowContext(
      MelSurfaceId::Customer,
      'myeventlane_account.dashboard',
      '/my-account',
      $user,
      FALSE,
      ['surface.customer' => TRUE],
    );
    $active = $resolver->resolveActivePolicyIds($context, [], $evaluations);
    $this->assertContains('escalation_review_required', $active);

    /** @var \Drupal\myeventlane_surface\MelEscalationPolicyHelper $escalation */
    $escalation = $this->container->get('myeventlane_surface.policy_escalation_helper');
    $hints = $escalation->interpret($active, []);
    $this->assertTrue($hints['continuity_overrides_engagement']);
  }

}
