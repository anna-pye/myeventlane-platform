<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelEscalationContinuityHelper;
use Drupal\myeventlane_surface\MelRetentionHelper;
use Drupal\myeventlane_surface\MelRetentionTier;
use Drupal\user\Entity\User;
use Symfony\Component\Routing\Route;

/**
 * MELExperienceSystem continuity + workflow CTA deferral signals.
 *
 * @group myeventlane_surface
 */
final class MelExperienceContinuityKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testEscalationContinuityUsesBooleanFlagsOnly(): void {
    $registry = $this->container->get('myeventlane_surface.experience_registry')->all();
    $helper = new MelEscalationContinuityHelper();
    $flags = $helper->buildSafeFlags(
      [$registry['moderation_interruption']],
      ['event.lifecycle.moderation_hold' => TRUE],
      'entity.node.canonical',
    );
    foreach ($flags as $value) {
      $this->assertIsBool($value);
    }
    $this->assertTrue($flags['moderation_hold_active']);
  }

  public function testRetentionTieringIsDeterministicMediumOverLow(): void {
    $registry = $this->container->get('myeventlane_surface.experience_registry')->all();
    $profile = (new MelRetentionHelper())->buildRetentionProfile([
      $registry['saved_event_return_discovery'],
      $registry['onboarding_resume'],
    ]);
    $this->assertSame(MelRetentionTier::Medium->value, $profile['tier']);
    $this->assertContains('saved_event_return_discovery', $profile['contributor_experience_ids']);
  }

  public function testResumeCandidatesRequireGateSignals(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/', '<front>', new Route('/'));
    $attachments = $this->container->get('myeventlane_surface.experience_manager')->buildPageAttachments(NULL);
    $this->assertSame([], $attachments['resume_candidates']);
    $this->popRequest();
  }

  public function testCommerceCheckoutPrimaryWorkflowBandDeferred(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole('authenticated');
    $user->save();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/checkout/order', 'commerce_checkout.order_information', new Route('/checkout/order'));

    $workflow = $this->container->get('myeventlane_surface.workflow_manager')->buildPageAttachments();
    $primary = $workflow['region_primary'] ?? [];
    $theme = is_array($primary) ? ($primary['#theme'] ?? '') : '';
    $this->assertTrue(
      $primary === [] || $theme !== 'mel_next_step_panel',
      'Commerce checkout must not surface the primary workflow next-step panel.',
    );

    $this->popRequest();
  }

  public function testPrimaryWorkflowRegionPreviewParity(): void {
    // Anonymous avoids Drupal 11 “authenticated role must not be assigned manually”
    // in lightweight Kernel setups; parity is behavioural, not UID-specific.
    $this->loginAnonymous();
    $this->pushRouteRequest('/', '<front>', new Route('/'));

    $workflow_manager = $this->container->get('myeventlane_surface.workflow_manager');
    $attachments = $workflow_manager->buildPageAttachments();
    $primary_nonempty = isset($attachments['region_primary'])
      && is_array($attachments['region_primary'])
      && $attachments['region_primary'] !== [];

    $this->assertSame(
      $primary_nonempty,
      $workflow_manager->willRenderPrimaryWorkflowRegion(),
      'Dashboard dedupe relies on parity between preview and attachments primary region.',
    );

    $this->popRequest();
  }

}
