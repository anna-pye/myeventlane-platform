<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_core\MelStateEvaluation;
use Drupal\myeventlane_surface\MelStateManager;
use Drupal\myeventlane_surface\MelStateRegistry;
use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_surface\MelTrustStateHelper;
use Symfony\Component\Routing\Route;

/**
 * MELStateSystem conservative defaults and trust visibility rules.
 *
 * @group myeventlane_surface
 */
final class MelStateGovernanceKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testPublicSurfaceHidesPublishAndBoostWhenNotSatisfied(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/', '<front>', new Route('/'));

    $interpretation = $this->container->get('myeventlane_surface.state_manager')->buildPageInterpretation();
    $this->assertTrue($interpretation['cta_governance']['hide_publish_cta']);
    $this->assertTrue($interpretation['cta_governance']['hide_boost_cta']);
    $this->assertIsArray($interpretation['grouped']);

    $this->popRequest();
  }

  public function testTrustHelperNeverExposesNonPublicSafeTrustOnPublicSurface(): void {
    $definitions = $this->container->get('myeventlane_surface.state_registry')->all();
    $evaluations = [];
    foreach ($definitions as $id => $definition) {
      $evaluations[$id] = MelStateEvaluation::Satisfied;
    }

    $visible = (new MelTrustStateHelper())->visibleTrustStateIds(MelSurfaceId::Public, $evaluations, $definitions);
    foreach ($visible as $trust_id) {
      $def = $definitions[$trust_id] ?? NULL;
      $this->assertNotNull($def);
      $this->assertTrue($def->publicSafe, 'Public shells must only surface public-safe trust contracts.');
    }
  }

  public function testErrorFallbackInterpretationHidesRiskyCtas(): void {
    $registry = $this->createMock(MelStateRegistry::class);
    $registry->method('all')->willThrowException(new \RuntimeException('forced resolver failure'));

    $resolver = $this->container->get('myeventlane_surface.state_resolver');
    $broken_manager = new MelStateManager(
      $registry,
      $resolver,
      $this->container->get('myeventlane_surface.state_readiness_helper'),
      $this->container->get('myeventlane_surface.state_lifecycle_helper'),
      $this->container->get('myeventlane_surface.state_trust_helper'),
      $this->container->get('myeventlane_surface.state_eligibility_helper'),
      $this->container->get('myeventlane_surface.state_accessibility_helper'),
      $this->container->get('logger.channel.myeventlane_surface'),
      $this->container->get('string_translation'),
    );

    $fallback = $broken_manager->buildPageInterpretation();
    $this->assertTrue($fallback['cta_governance']['hide_publish_cta']);
    $this->assertTrue($fallback['cta_governance']['hide_boost_cta']);
    $this->assertContains('user', $fallback['#cache']['contexts']);
  }

}
