<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\myeventlane_auth\Service\IdentityIntentResolver;
use Drupal\myeventlane_auth\Service\PostLoginRoutePolicy;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests customer-first post-login route policy.
 *
 * @group myeventlane_auth
 */
final class PostLoginRoutePolicyTest extends UnitTestCase {

  private PostLoginRoutePolicy $policy;

  protected function setUp(): void {
    parent::setUp();
    $this->policy = new PostLoginRoutePolicy();
  }

  public function testCustomerAndVendorDefaults(): void {
    $this->assertSame('myeventlane_account.dashboard', $this->policy->defaultRoute(FALSE, FALSE));
    $this->assertSame('myeventlane_event_studio.create', $this->policy->defaultRoute(TRUE, FALSE));
    $this->assertSame('myeventlane_vendor.console.dashboard', $this->policy->defaultRoute(TRUE, TRUE));
  }

  public function testCreateEventUsesCanonicalGateway(): void {
    $this->assertSame(
      'myeventlane_vendor.create_event_gateway',
      $this->policy->routeForIntent(IdentityIntentResolver::INTENT_CREATE_EVENT),
    );
    $this->assertNull($this->policy->routeForIntent(IdentityIntentResolver::INTENT_BROWSE));
  }

  public function testSafeDestinationPreservationAndAuthExclusions(): void {
    $this->assertSame(
      '/events/42?ticket=1',
      $this->policy->safeExplicitDestination(
        Request::create('/mel/post-login', 'GET', ['destination' => '/events/42?ticket=1']),
        17,
      ),
    );
    $this->assertNull($this->policy->safeExplicitDestination(
      Request::create('/mel/post-login', 'GET', ['destination' => '/user/17']),
      17,
    ));
    $this->assertNull($this->policy->safeExplicitDestination(
      Request::create('/mel/post-login', 'GET', ['destination' => '//evil.example']),
      17,
    ));
    $this->assertNull($this->policy->safeExplicitDestination(
      Request::create('/mel/post-login', 'GET', ['destination' => '/admin/people']),
      17,
    ));
  }

}
