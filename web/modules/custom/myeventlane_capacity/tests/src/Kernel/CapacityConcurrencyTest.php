<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_capacity\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for atomic capacity reservation concurrency.
 */
#[Group('myeventlane_capacity')]
#[RunTestsInSeparateProcesses]
final class CapacityConcurrencyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'myeventlane_capacity',
  ];

  /**
   * The capacity service.
   *
   * @var \Drupal\myeventlane_capacity\Service\EventCapacityService
   */
  private $capacityService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('myeventlane_capacity', [
      'myeventlane_capacity_lock',
      'myeventlane_capacity_reservation',
    ]);

    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_event_capacity_total')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event_capacity_total',
        'entity_type' => 'node',
        'type' => 'integer',
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_event_capacity_total',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Capacity total',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'field_event_type')) {
      FieldStorageConfig::create([
        'field_name' => 'field_event_type',
        'entity_type' => 'node',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_event_type',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Event type',
      ])->save();
    }

    $this->capacityService = $this->container->get('myeventlane_capacity.service');
  }

  /**
   * Two distinct reservation keys cannot both hold the last available place.
   */
  public function testSecondReservationBlockedWhenLastPlaceHeld(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Concurrency Event',
      'status' => 1,
      'field_event_capacity_total' => 1,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    $this->capacityService->assertCanBook($event, 1, 'test:concurrent:first');

    try {
      $this->capacityService->assertCanBook($event, 1, 'test:concurrent:second');
      $this->fail('Expected CapacityExceededException was not thrown.');
    }
    catch (CapacityExceededException $e) {
      $this->assertStringContainsString('sold out', strtolower($e->getMessage()));
    }
  }

  /**
   * The same reservation key can upsert quantity without double-counting.
   */
  public function testSameReservationKeyUpsertsQuantity(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Upsert Event',
      'status' => 1,
      'field_event_capacity_total' => 3,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    $key = 'test:upsert:event:' . $event->id();
    $this->capacityService->assertCanBook($event, 2, $key);
    $this->capacityService->assertCanBook($event, 3, $key);

    try {
      $this->capacityService->assertCanBook($event, 1, 'test:upsert:other');
      $this->fail('Expected CapacityExceededException was not thrown.');
    }
    catch (CapacityExceededException $e) {
      $this->assertInstanceOf(CapacityExceededException::class, $e);
    }
  }

  /**
   * Releasing a reservation frees capacity for a subsequent hold.
   */
  public function testReleaseReservationFreesCapacity(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Release Event',
      'status' => 1,
      'field_event_capacity_total' => 1,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    $firstKey = 'test:release:first';
    $secondKey = 'test:release:second';

    $this->capacityService->assertCanBook($event, 1, $firstKey);
    $this->capacityService->releaseReservation($firstKey);
    $this->capacityService->assertCanBook($event, 1, $secondKey);
  }

  /**
   * Active reservation metadata is available to customer-facing timers.
   */
  public function testActiveReservationMetadataUsesAuthoritativeTtl(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Timed Hold Event',
      'status' => 1,
      'field_event_capacity_total' => 4,
      'field_event_type' => 'paid',
    ]);
    $event->save();

    $key = 'test:timer:event:' . $event->id();
    $this->capacityService->assertCanBook($event, 2, $key);
    $reservation = $this->capacityService->getActiveReservation($key);

    $this->assertIsArray($reservation);
    $this->assertSame((int) $event->id(), $reservation['event_id']);
    $this->assertSame(2, $reservation['quantity']);
    $this->assertSame(
      $this->capacityService->getReservationTtl(),
      $reservation['expires'] - $reservation['created'],
    );

    $this->capacityService->releaseReservation($key);
    $this->assertNull($this->capacityService->getActiveReservation($key));
  }

}
