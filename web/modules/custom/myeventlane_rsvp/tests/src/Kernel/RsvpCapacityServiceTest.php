<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Kernel;

use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\myeventlane_rsvp\Service\RsvpCapacityService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for RsvpCapacityService.
 *
 * @group myeventlane_rsvp
 */
final class RsvpCapacityServiceTest extends KernelTestBase {

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
   * Count matches confirmed RSVPs for the event.
   */
  public function testCountConfirmedRsvps(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    /** @var \Drupal\myeventlane_rsvp\Service\RsvpCapacityService $capacity */
    $capacity = $this->container->get('myeventlane_rsvp.capacity');

    $this->assertSame(0, $capacity->countConfirmedRsvps((int) $event->id()));

    $storage = $this->container->get('entity_type.manager')->getStorage('rsvp_submission');

    $r1 = $storage->create([
      'event_id' => ['target_id' => $event->id()],
      'attendee_name' => 'One',
      'email' => 'one@example.test',
      'status' => 'confirmed',
    ]);
    $r1->save();
    $this->assertSame(1, $capacity->countConfirmedRsvps((int) $event->id()));

    $r2 = $storage->create([
      'event_id' => ['target_id' => $event->id()],
      'attendee_name' => 'Two',
      'email' => 'two@example.test',
      'status' => 'confirmed',
    ]);
    $r2->save();
    $this->assertSame(2, $capacity->countConfirmedRsvps((int) $event->id()));

    $r3 = $storage->create([
      'event_id' => ['target_id' => $event->id()],
      'attendee_name' => 'Waitlist',
      'email' => 'wait@example.test',
      'status' => 'waitlist',
    ]);
    $r3->save();
    $this->assertSame(2, $capacity->countConfirmedRsvps((int) $event->id()));

    $r1->set('status', 'cancelled');
    $r1->save();
    $this->assertSame(1, $capacity->countConfirmedRsvps((int) $event->id()));

    $this->assertTrue($capacity->isAtCapacity((int) $event->id(), 1));
    $this->assertFalse($capacity->isAtCapacity((int) $event->id(), 2));
  }

}
