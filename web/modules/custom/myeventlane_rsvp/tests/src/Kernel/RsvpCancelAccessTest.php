<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Kernel;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\myeventlane_rsvp\Access\RsvpCancelAccess;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Kernel tests for RSVP cancel access.
 *
 * @group myeventlane_rsvp
 */
final class RsvpCancelAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'myeventlane_rsvp',
  ];

  /**
   * The access checker.
   */
  private RsvpCancelAccess $accessChecker;

  /**
   * Test event node.
   */
  private Node $event;

  /**
   * RSVP owner (logged-in user).
   */
  private User $owner;

  /**
   * Other user (not owner).
   */
  private User $otherUser;

  /**
   * Admin user.
   */
  private User $adminUser;

  /**
   * Event owner/vendor user.
   */
  private User $vendorUser;

  /**
   * RSVP submission (owner = $owner).
   */
  private RsvpSubmission $rsvpOwned;

  /**
   * Guest RSVP (no user_id).
   */
  private RsvpSubmission $rsvpGuest;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('rsvp_submission');

    if (!NodeType::load('event')) {
      NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    }

    $this->accessChecker = RsvpCancelAccess::create($this->container);

    $this->owner = $this->createUser(['access content']);
    $this->otherUser = $this->createUser(['access content']);
    $this->adminUser = $this->createUser(['access content', 'administer rsvps']);
    $this->vendorUser = $this->createUser(['access content', 'manage own event rsvps']);

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
      'uid' => $this->vendorUser->id(),
    ]);
    $this->event->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('rsvp_submission');

    $this->rsvpOwned = $storage->create([
      'event_id' => ['target_id' => $this->event->id()],
      'attendee_name' => 'Owner User',
      'email' => 'owner@example.test',
      'user_id' => ['target_id' => $this->owner->id()],
      'status' => 'confirmed',
    ]);
    $this->rsvpOwned->save();

    $this->rsvpGuest = $storage->create([
      'event_id' => ['target_id' => $this->event->id()],
      'attendee_name' => 'Guest User',
      'email' => 'guest@example.test',
      'status' => 'confirmed',
    ]);
    // Guest: ensure no user_id (anonymous submission).
    $this->rsvpGuest->save();
  }

  /**
   * Builds a route match for the cancel route.
   */
  private function routeMatch(int $rsvp_id): RouteMatch {
    $route = new Route('/rsvp/{rsvp_id}/cancel', [], [
      'rsvp_id' => '\d+',
    ]);
    $params = ['rsvp_id' => $rsvp_id];
    return new RouteMatch('myeventlane_rsvp.cancel_confirm', $route, $params, $params);
  }

  /**
   * Owner can cancel own RSVP.
   */
  public function testOwnerCanCancelOwnRsvp(): void {
    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpOwned->id()),
      $this->owner->getAccount()
    );
    $this->assertInstanceOf(AccessResultAllowed::class, $result);
  }

  /**
   * User A cannot cancel user B's RSVP.
   */
  public function testUserCannotCancelOtherUserRsvp(): void {
    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpOwned->id()),
      $this->otherUser->getAccount()
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Admin can cancel any RSVP.
   */
  public function testAdminCanCancelAnyRsvp(): void {
    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpOwned->id()),
      $this->adminUser->getAccount()
    );
    $this->assertInstanceOf(AccessResultAllowed::class, $result);

    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpGuest->id()),
      $this->adminUser->getAccount()
    );
    $this->assertInstanceOf(AccessResultAllowed::class, $result);
  }

  /**
   * Event owner (vendor) can cancel attendee RSVPs.
   */
  public function testVendorCanCancelAttendeeRsvp(): void {
    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpOwned->id()),
      $this->vendorUser->getAccount()
    );
    $this->assertInstanceOf(AccessResultAllowed::class, $result);
  }

  /**
   * Anonymous user cannot cancel without valid token.
   */
  public function testAnonymousCannotCancelWithoutToken(): void {
    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpGuest->id()),
      new AnonymousUserSession()
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);
  }

  /**
   * Anonymous can cancel guest RSVP with valid token.
   */
  public function testAnonymousCanCancelGuestRsvpWithToken(): void {
    $token = $this->accessChecker->generateToken($this->rsvpGuest);
    $request = Request::create('/rsvp/' . $this->rsvpGuest->id() . '/cancel', 'GET', ['token' => $token]);
    $this->container->get('request_stack')->push($request);

    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpGuest->id()),
      new AnonymousUserSession()
    );
    $this->assertInstanceOf(AccessResultAllowed::class, $result);

    $this->container->get('request_stack')->pop();
  }

  /**
   * Invalid token is rejected.
   */
  public function testInvalidTokenRejected(): void {
    $request = Request::create('/rsvp/' . $this->rsvpGuest->id() . '/cancel', 'GET', ['token' => 'invalid-token']);
    $this->container->get('request_stack')->push($request);

    $result = $this->accessChecker->access(
      $this->routeMatch($this->rsvpGuest->id()),
      new AnonymousUserSession()
    );
    $this->assertInstanceOf(AccessResultForbidden::class, $result);

    $this->container->get('request_stack')->pop();
  }

}
