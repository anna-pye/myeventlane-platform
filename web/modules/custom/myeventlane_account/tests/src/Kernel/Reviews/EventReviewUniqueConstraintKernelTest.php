<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Kernel\Reviews;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Kernel tests for event review unique constraint (uid + event_id).
 *
 * @group myeventlane_account
 */
final class EventReviewUniqueConstraintKernelTest extends KernelTestBase {

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
   * DB-level unique index prevents duplicate uid+event_id.
   */
  public function testUniqueConstraintPreventsDuplicateReview(): void {
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

    $storage = $this->container->get('entity_type.manager')->getStorage('event_review');

    $review1 = $storage->create([
      'uid' => $user->id(),
      'event_id' => $event->id(),
      'rating' => 5,
    ]);
    $review1->save();
    $this->assertGreaterThan(0, $review1->id(), 'First review saved.');

    $review2 = $storage->create([
      'uid' => $user->id(),
      'event_id' => $event->id(),
      'rating' => 4,
    ]);

    // Either validation (UniqueUserEventConstraint) or DB unique index blocks.
    $this->expectException(\Exception::class);
    $review2->save();
  }

}
