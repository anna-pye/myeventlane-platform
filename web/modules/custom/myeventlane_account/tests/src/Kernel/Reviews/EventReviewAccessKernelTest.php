<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Kernel\Reviews;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Kernel tests for event review route access.
 *
 * @group myeventlane_account
 */
final class EventReviewAccessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
    'myeventlane_core',
    'myeventlane_account',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('event_review');
    $this->installConfig(['user', 'myeventlane_account']);

    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }
  }

  /**
   * Feature flag disabled: route access returns 403 (fail-closed).
   */
  public function testFeatureFlagDisabledDeniesAccess(): void {
    $this->config('myeventlane_account.reviews')->set('enabled', FALSE)->save();

    $user = User::create([
      'name' => 'reviewer',
      'mail' => 'reviewer@example.com',
    ]);
    $user->addRole('authenticated');
    $user->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    $accessCheck = $this->container->get('myeventlane_account.event_review_access');
    $result = $accessCheck->checkAccess($user, $event);

    $this->assertTrue($result->isForbidden(), 'Access must be forbidden when reviews.enabled is false.');
  }

  /**
   * Feature flag enabled: access depends on permission.
   */
  public function testFeatureFlagEnabledAllowsAccessWithPermission(): void {
    $this->config('myeventlane_account.reviews')->set('enabled', TRUE)->save();

    $role = Role::load('authenticated');
    if ($role) {
      $role->grantPermission('create event reviews')->save();
    }

    $user = User::create([
      'name' => 'reviewer',
      'mail' => 'reviewer@example.com',
    ]);
    $user->addRole('authenticated');
    $user->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    $accessCheck = $this->container->get('myeventlane_account.event_review_access');
    $result = $accessCheck->checkAccess($user, $event);

    $this->assertTrue($result->isAllowed(), 'Access must be allowed when enabled and user has permission.');
  }

  /**
   * Non-event node: access forbidden (fail-closed).
   */
  public function testNonEventNodeDeniesAccess(): void {
    $this->config('myeventlane_account.reviews')->set('enabled', TRUE)->save();

    if (!NodeType::load('article')) {
      NodeType::create([
        'type' => 'article',
        'name' => 'Article',
      ])->save();
    }

    $user = User::create([
      'name' => 'reviewer',
      'mail' => 'reviewer@example.com',
    ]);
    $user->addRole('authenticated');
    $user->save();

    $article = Node::create([
      'type' => 'article',
      'title' => 'Test Article',
      'status' => 1,
    ]);
    $article->save();

    $accessCheck = $this->container->get('myeventlane_account.event_review_access');
    $result = $accessCheck->checkAccess($user, $article);

    $this->assertTrue($result->isForbidden(), 'Access must be forbidden for non-event nodes.');
  }

}
