<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Kernel;

use Drupal\Core\Test\Attribute\RunTestsInSeparateProcesses;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_rsvp\Service\RsvpSubmissionManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Kernel tests for RsvpSubmissionManager (idempotent submissions, dedupe).
 *
 * @group myeventlane_rsvp
 */
#[RunTestsInSeparateProcesses]
final class RsvpSubmissionManagerTest extends KernelTestBase {

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
  }

  /**
   * Guest (same email) resubmits: updates existing, no duplicate.
   */
  public function testGuestSameEmailUpdatesExisting(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    /** @var \Drupal\myeventlane_rsvp\Service\RsvpSubmissionManager $manager */
    $manager = $this->container->get('myeventlane_rsvp.submission_manager');
    $storage = $this->container->get('entity_type.manager')->getStorage('rsvp_submission');

    $data = [
      'name' => 'Guest One',
      'email' => 'guest@example.test',
      'phone' => '',
      'guests' => 2,
      'donation' => 0.0,
    ];

    $s1 = $manager->submitOrUpdate($event, $data, NULL);
    $this->assertNotNull($s1->id());
    $this->assertSame('Guest One', $s1->get('attendee_name')->value);

    // Same email, different name – should update, not create.
    $data['name'] = 'Guest One Updated';
    $data['guests'] = 3;
    $s2 = $manager->submitOrUpdate($event, $data, NULL);

    $this->assertSame($s1->id(), $s2->id());
    $this->assertSame('Guest One Updated', $s2->get('attendee_name')->value);
    $this->assertSame(3, (int) $s2->get('guests')->value);

    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event->id())
      ->condition('email', 'guest@example.test')
      ->condition('status', 'confirmed')
      ->count()
      ->execute();
    $this->assertSame(1, (int) $count);
  }

  /**
   * Authenticated user resubmits: updates existing, no duplicate.
   */
  public function testAuthenticatedUserUpdatesExisting(): void {
    $user = User::create([
      'name' => 'testuser',
      'mail' => 'user@example.test',
      'status' => 1,
    ]);
    $user->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    $manager = $this->container->get('myeventlane_rsvp.submission_manager');
    $storage = $this->container->get('entity_type.manager')->getStorage('rsvp_submission');

    $this->container->get('current_user')->setAccount($user);

    $data = [
      'name' => 'Test User',
      'email' => 'user@example.test',
      'phone' => '',
      'guests' => 1,
      'donation' => 0.0,
    ];

    $s1 = $manager->submitOrUpdate($event, $data, NULL);
    $this->assertSame($user->id(), (int) $s1->get('user_id')->target_id);

    $data['name'] = 'Test User Updated';
    $s2 = $manager->submitOrUpdate($event, $data, NULL);

    $this->assertSame($s1->id(), $s2->id());

    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event->id())
      ->condition('user_id', $user->id())
      ->condition('status', 'confirmed')
      ->count()
      ->execute();
    $this->assertSame(1, (int) $count);
  }

  /**
   * Different guests for same event: each gets own submission.
   */
  public function testDifferentEmailsCreateSeparateSubmissions(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    $manager = $this->container->get('myeventlane_rsvp.submission_manager');
    $storage = $this->container->get('entity_type.manager')->getStorage('rsvp_submission');

    $s1 = $manager->submitOrUpdate($event, [
      'name' => 'A',
      'email' => 'a@example.test',
      'phone' => '',
      'guests' => 1,
      'donation' => 0.0,
    ], NULL);

    $s2 = $manager->submitOrUpdate($event, [
      'name' => 'B',
      'email' => 'b@example.test',
      'phone' => '',
      'guests' => 1,
      'donation' => 0.0,
    ], NULL);

    $this->assertNotSame($s1->id(), $s2->id());

    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event->id())
      ->condition('status', 'confirmed')
      ->count()
      ->execute();
    $this->assertSame(2, (int) $count);
  }

}
