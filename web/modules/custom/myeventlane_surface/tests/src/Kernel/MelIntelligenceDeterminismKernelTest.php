<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelIntelligencePresentationLane;
use Drupal\user\Entity\User;
use Symfony\Component\Routing\Route;

/**
 * MELIntelligenceSystem deterministic prioritisation and privacy envelopes.
 *
 * @group myeventlane_surface
 */
final class MelIntelligenceDeterminismKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testPriorityResolverTierBeatsWeightThenId(): void {
    $ranker = $this->container->get('myeventlane_surface.priority_resolver');
    /** @var \Drupal\myeventlane_surface\MelIntelligenceRegistry $registry */
    $registry = $this->container->get('myeventlane_surface.intelligence_registry');
    $all = $registry->all();

    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    $subset = [$all['recommended_events'], $all['continue_saved_event']];
    $ranked = $ranker->rank($user, $subset);
    $this->assertSame('continue_saved_event', $ranked[0]->id);
    $this->assertSame('recommended_events', $ranked[1]->id);

    $trace = $ranker->explainOrder($ranked);
    $this->assertSame(1, $trace[0]['position']);
    $this->assertSame('continue_saved_event', $trace[0]['id']);
    $this->assertSame('sort_key: tier DESC, weight DESC, id ASC (lexical)', $trace[0]['rule']);
  }

  public function testIntelligencePayloadDeclaresNoHiddenScores(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/my-account', 'myeventlane_account.dashboard', new Route('/my-account'));

    $payload = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload();
    $this->assertTrue($payload['explainability']['no_hidden_scores']);

    $contexts = $payload['#cache']['contexts'] ?? [];
    $this->assertContains('route', $contexts);
    $this->assertContains('user', $contexts);

    $this->popRequest();
  }

  public function testCheckoutPolicyFeedsIntelligenceGovernanceSuppressIds(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/checkout/review', 'commerce_checkout.review', new Route('/checkout/review'));

    $manager = $this->container->get('myeventlane_surface.operational_policy_manager');
    $policy = $manager->buildPageInterpretation();
    $intel = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload(NULL, $policy);

    $this->assertTrue($intel['orchestration']['operational_policy_applied']);
    $suppress = $policy['intelligence_governance']['suppress_definition_ids'] ?? [];
    if ($suppress !== []) {
      foreach ($intel['items'] ?? [] as $item) {
        $id = $item['id'] ?? '';
        $this->assertNotContains($id, $suppress, 'Suppressed intelligence ids must not appear in rendered items.');
      }
    }

    $this->popRequest();
  }

  /**
   * Generic public browsing must not attach public-discovery orchestration UX.
   *
   * @see \Drupal\myeventlane_surface\MelIntelligenceManager::suppressPublicGovernanceNoiseOffCheckout()
   *
   * @dataProvider providePublicBrowsingRoutesWithoutCheckout
   */
  public function testPublicBrowsingSuppressesPublicDiscoveryIntelligenceItems(string $path, string $route_name): void {
    $this->loginAnonymous();
    \Drupal::configFactory()->getEditable('system.site')->set('page.front', '/home')->save();

    $this->pushRouteRequest($path, $route_name, new Route($path));

    $payload = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload();
    foreach ($payload['items'] ?? [] as $item) {
      $this->assertNotSame(
        MelIntelligencePresentationLane::PublicDiscovery->value,
        $item['presentation_lane'] ?? '',
        'Public-discovery intelligence must be suppressed on public browse routes.',
      );
    }

    $this->popRequest();
  }

  /**
   * @return iterable<string, array{0: string, 1: string}>
   */
  public static function providePublicBrowsingRoutesWithoutCheckout(): iterable {
    yield 'site_front_alias' => ['/home', 'view.test_front.page_1'];
    yield 'marketing_path' => ['/events/browse', 'view.mel_discover.public_page'];
  }

  /**
   * Checkout must never surface public-discovery lanes (marketing prompts).
   */
  public function testPublicCheckoutOmitsPublicDiscoveryLanes(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest(
      '/checkout/review',
      'commerce_checkout.review',
      new Route('/checkout/review'),
    );

    $payload = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload();
    foreach ($payload['items'] ?? [] as $item) {
      $this->assertNotSame(
        MelIntelligencePresentationLane::PublicDiscovery->value,
        $item['presentation_lane'] ?? '',
        'Marketing-style public discovery must not accompany checkout UX.',
      );
    }

    $this->popRequest();
  }

}
