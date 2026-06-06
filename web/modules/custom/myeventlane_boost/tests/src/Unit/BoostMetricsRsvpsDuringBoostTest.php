<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\myeventlane_boost\Service\BoostMetricsService;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RSVP-during-boost entitlement window attribution.
 *
 * @group myeventlane_boost
 */
final class BoostMetricsRsvpsDuringBoostTest extends UnitTestCase {

  /**
   * Ensures multiple entitlement windows aggregate and gaps are excluded.
   */
  public function testRsvpsDuringBoostAggregatesMultipleWindowsAndExcludesGaps(): void {
    $event = $this->createRsvpEvent(42);
    $windows = [
      [1, 1_000, 2_000],
      [2, 3_000, 4_000],
    ];

    $submissions = [
      $this->createRsvpSubmission(1, 1_500, 'confirmed', 0.0),
      $this->createRsvpSubmission(2, 2_500, 'confirmed', 0.0),
      $this->createRsvpSubmission(3, 3_500, 'confirmed', 0.0),
      $this->createRsvpSubmission(4, 5_000, 'confirmed', 0.0),
    ];

    $service = $this->createService(
      eventId: 42,
      windows: $windows,
      submissions: $submissions,
      hasProduct: FALSE,
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame(2, $metrics['rsvps_during_boost']);
    $this->assertSame(0.0, $metrics['donation_revenue_during_boost']);
    $this->assertSame(0.0, $metrics['average_donation_during_boost']);
    $this->assertSame('$0.00', $metrics['donation_revenue_during_boost_formatted']);
    $this->assertSame('$0.00', $metrics['average_donation_during_boost_formatted']);
    $this->assertSame(0, $metrics['rsvp_donation_count_during_boost']);
  }

  /**
   * Ensures cancelled RSVPs inside a window are excluded at query time.
   */
  public function testRsvpsDuringBoostExcludesCancelledSubmissions(): void {
    $event = $this->createRsvpEvent(55);
    $windows = [[1, 1_000, 5_000]];

    $submissions = [
      $this->createRsvpSubmission(1, 1_500, 'confirmed', 10.0),
      $this->createRsvpSubmission(2, 1_600, 'cancelled', 50.0),
    ];

    $service = $this->createService(
      eventId: 55,
      windows: $windows,
      submissions: $submissions,
      hasProduct: FALSE,
      excludedSubmissionIds: [2],
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame(1, $metrics['rsvps_during_boost']);
    $this->assertSame(10.0, $metrics['donation_revenue_during_boost']);
  }

  /**
   * Ensures donation totals and averages use submission donation values.
   */
  public function testRsvpsDuringBoostSumsDonationsAndCalculatesAverage(): void {
    $event = $this->createRsvpEvent(77);
    $windows = [[1, 1_000, 5_000]];

    $submissions = [
      $this->createRsvpSubmission(1, 1_500, 'confirmed', 10.0),
      $this->createRsvpSubmission(2, 2_000, 'confirmed', 20.0),
      $this->createRsvpSubmission(3, 2_500, 'confirmed', 0.0),
    ];

    $service = $this->createService(
      eventId: 77,
      windows: $windows,
      submissions: $submissions,
      hasProduct: FALSE,
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame(3, $metrics['rsvps_during_boost']);
    $this->assertSame(30.0, $metrics['donation_revenue_during_boost']);
    $this->assertSame(15.0, $metrics['average_donation_during_boost']);
    $this->assertSame('$30.00', $metrics['donation_revenue_during_boost_formatted']);
    $this->assertSame('$15.00', $metrics['average_donation_during_boost_formatted']);
    $this->assertSame(2, $metrics['rsvp_donation_count_during_boost']);
  }

  /**
   * Ensures events with no entitlement windows return zero values, not NULL.
   */
  public function testRsvpsDuringBoostReturnsZerosWhenNoWindows(): void {
    $event = $this->createRsvpEvent(88);

    $service = $this->createService(
      eventId: 88,
      windows: [],
      submissions: [],
      hasProduct: FALSE,
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame(0, $metrics['rsvps_during_boost']);
    $this->assertSame(0.0, $metrics['donation_revenue_during_boost']);
    $this->assertSame(0.0, $metrics['average_donation_during_boost']);
    $this->assertSame('$0.00', $metrics['donation_revenue_during_boost_formatted']);
    $this->assertSame('$0.00', $metrics['average_donation_during_boost_formatted']);
    $this->assertSame(0, $metrics['rsvp_donation_count_during_boost']);
    $this->assertSame('$0.00', $metrics['sales_during_boost']);
    $this->assertSame(0.0, $metrics['revenue_during_boost']);
  }

  /**
   * Ensures hybrid events expose paid and RSVP metrics together.
   */
  public function testHybridEventReturnsPaidAndRsvpMetrics(): void {
    $event = $this->createHybridEvent(101);
    $windows = [[1, 1_000, 5_000]];

    $submissions = [
      $this->createRsvpSubmission(1, 1_500, 'confirmed', 25.0),
    ];

    $orders = [
      1 => $this->createCompletedOrder(1, 1_600),
    ];
    $orderItems = [
      $this->createTicketOrderItem(101, 1, 2, '50.00'),
    ];

    $service = $this->createService(
      eventId: 101,
      windows: $windows,
      submissions: $submissions,
      hasProduct: TRUE,
      orders: $orders,
      ticketOrderItems: $orderItems,
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame('$50.00', $metrics['sales_during_boost']);
    $this->assertSame(50.0, $metrics['revenue_during_boost']);
    $this->assertSame(1, $metrics['orders_during_boost']);
    $this->assertSame(2, $metrics['tickets_during_boost']);
    $this->assertSame(1, $metrics['rsvps_during_boost']);
    $this->assertSame(25.0, $metrics['donation_revenue_during_boost']);
    $this->assertSame(25.0, $metrics['average_donation_during_boost']);
  }

  /**
   * Ensures paid RSVP donation order items override submission donation fields.
   */
  public function testRsvpsDuringBoostPrefersCompletedDonationOrderItems(): void {
    $event = $this->createRsvpEvent(120);
    $windows = [[1, 1_000, 5_000]];

    $submissions = [
      $this->createRsvpSubmission(10, 1_500, 'confirmed', 5.0),
    ];

    $orders = [
      5 => $this->createCompletedOrder(5, 1_700),
    ];
    $donationOrderItems = [
      $this->createRsvpDonationOrderItem(120, 10, 5, '15.00'),
    ];

    $service = $this->createService(
      eventId: 120,
      windows: $windows,
      submissions: $submissions,
      hasProduct: FALSE,
      orders: $orders,
      donationOrderItems: $donationOrderItems,
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame(1, $metrics['rsvps_during_boost']);
    $this->assertSame(15.0, $metrics['donation_revenue_during_boost']);
    $this->assertSame(15.0, $metrics['average_donation_during_boost']);
    $this->assertSame(1, $metrics['rsvp_donation_count_during_boost']);
  }

  /**
   * Builds a BoostMetricsService with RSVP and optional paid fixtures.
   *
   * @param array<int, array{0: int, 1: int, 2: int}> $windows
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface[] $submissions
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $ticketOrderItems
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $donationOrderItems
   */
  private function createService(
    int $eventId,
    array $windows,
    array $submissions,
    bool $hasProduct,
    array $orders = [],
    array $ticketOrderItems = [],
    array $donationOrderItems = [],
    array $excludedSubmissionIds = [],
  ): BoostMetricsService {
    $entitlementManager = new BoostEntitlementManager(
      $this->createEntitlementEntityTypeManager($eventId, $windows),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $entityTypeManager = $this->createEntityTypeManager(
      $eventId,
      $submissions,
      $orders,
      $ticketOrderItems,
      $donationOrderItems,
      $excludedSubmissionIds,
    );

    return new BoostMetricsService(
      $this->createMock(Connection::class),
      $entityTypeManager,
      $this->createBoostManagerStub(),
      $entitlementManager,
      $this->createMock(TimeInterface::class),
      $this->createMock(TranslationInterface::class),
    );
  }

  /**
   * @param array<int, array{0: int, 1: int, 2: int}> $windows
   */
  private function createEntitlementEntityTypeManager(int $eventId, array $windows): EntityTypeManagerInterface {
    $entitlements = [];
    foreach ($windows as [$id, $starts, $ends]) {
      $startsField = (object) ['value' => $starts];
      $endsField = (object) ['value' => $ends];

      $entitlement = $this->createMock(BoostEntitlementInterface::class);
      $entitlement->method('get')->willReturnCallback(static function (string $field) use ($startsField, $endsField) {
        return match ($field) {
          'starts' => $startsField,
          'ends' => $endsField,
          default => NULL,
        };
      });
      $entitlements[$id] = $entitlement;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($entitlements));

    $entitlementStorage = $this->createMock(EntityStorageInterface::class);
    $entitlementStorage->method('getQuery')->willReturn($query);
    $entitlementStorage->method('loadMultiple')->willReturn($entitlements);

    return $this->wrapEntityTypeManager($entitlementStorage, NULL, NULL, NULL);
  }

  /**
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface[] $submissions
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $ticketOrderItems
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $donationOrderItems
   */
  private function createEntityTypeManager(
    int $eventId,
    array $submissions,
    array $orders,
    array $ticketOrderItems,
    array $donationOrderItems,
    array $excludedSubmissionIds,
  ): EntityTypeManagerInterface {
    $submissionMap = [];
    foreach ($submissions as $submission) {
      $submissionMap[(int) $submission->id()] = $submission;
    }

    $includedIds = array_values(array_filter(
      array_keys($submissionMap),
      static fn (int $id): bool => !in_array($id, $excludedSubmissionIds, TRUE),
    ));

    $rsvpQuery = $this->createMock(QueryInterface::class);
    $rsvpQuery->method('accessCheck')->willReturnSelf();
    $rsvpQuery->method('condition')->willReturnSelf();
    $rsvpQuery->method('execute')->willReturn($includedIds);

    $rsvpStorage = $this->createMock(EntityStorageInterface::class);
    $rsvpStorage->method('getQuery')->willReturn($rsvpQuery);
    $rsvpStorage->method('loadMultiple')->willReturnCallback(static function (array $ids) use ($submissionMap) {
      $loaded = [];
      foreach ($ids as $id) {
        if (isset($submissionMap[(int) $id])) {
          $loaded[(int) $id] = $submissionMap[(int) $id];
        }
      }
      return $loaded;
    });

    $allTargetEventItems = array_merge($ticketOrderItems, $donationOrderItems);

    $orderItemStorage = $this->createMock(EntityStorageInterface::class);
    $orderItemStorage->method('loadByProperties')
      ->willReturnCallback(static function (array $properties) use ($allTargetEventItems, $eventId) {
        if (($properties['field_target_event'] ?? NULL) === $eventId) {
          return $allTargetEventItems;
        }
        return [];
      });

    $orderItemQuery = $this->createMock(QueryInterface::class);
    $orderItemQuery->method('accessCheck')->willReturnSelf();
    $queryConditions = [];
    $orderItemQuery->method('condition')->willReturnCallback(function ($field, $value = NULL) use (&$queryConditions, $orderItemQuery) {
      if ($field === 'type') {
        $queryConditions['type'] = $value;
      }
      return $orderItemQuery;
    });
    $orderItemQuery->method('execute')->willReturnCallback(function () use (&$queryConditions, $donationOrderItems) {
      if (($queryConditions['type'] ?? NULL) === 'rsvp_donation') {
        $ids = [];
        foreach ($donationOrderItems as $item) {
          $ids[] = (int) $item->id();
        }
        return $ids;
      }
      return [];
    });
    $orderItemStorage->method('getQuery')->willReturn($orderItemQuery);
    $orderItemStorage->method('loadMultiple')->willReturnCallback(static function (array $ids) use ($donationOrderItems) {
      $loaded = [];
      foreach ($donationOrderItems as $item) {
        if (in_array((int) $item->id(), $ids, TRUE)) {
          $loaded[(int) $item->id()] = $item;
        }
      }
      return $loaded;
    });

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->willReturnCallback(static function ($orderId) use ($orders) {
      return $orders[(int) $orderId] ?? NULL;
    });

    return $this->wrapEntityTypeManager(NULL, $rsvpStorage, $orderItemStorage, $orderStorage);
  }

  private function wrapEntityTypeManager(
    ?EntityStorageInterface $entitlementStorage,
    ?EntityStorageInterface $rsvpStorage,
    ?EntityStorageInterface $orderItemStorage,
    ?EntityStorageInterface $orderStorage,
  ): EntityTypeManagerInterface {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('rsvp_submission')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entityType) use (
        $entitlementStorage,
        $rsvpStorage,
        $orderItemStorage,
        $orderStorage,
      ) {
        return match ($entityType) {
          'myeventlane_boost_entitlement' => $entitlementStorage,
          'rsvp_submission' => $rsvpStorage,
          'commerce_order_item' => $orderItemStorage,
          'commerce_order' => $orderStorage,
          default => NULL,
        };
      });

    return $entityTypeManager;
  }

  private function createRsvpEvent(int $eventId): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn((string) $eventId);
    $event->method('hasField')->with('field_product_target')->willReturn(TRUE);
    $event->method('get')->with('field_product_target')->willReturn($this->createEmptyField());
    return $event;
  }

  private function createHybridEvent(int $eventId): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn((string) $eventId);
    $event->method('hasField')->with('field_product_target')->willReturn(TRUE);
    $event->method('get')->with('field_product_target')->willReturn($this->createPopulatedField());
    return $event;
  }

  private function createRsvpSubmission(
    int $submissionId,
    int $created,
    string $status,
    float $donation,
  ): RsvpSubmissionInterface {
    $createdField = (object) ['value' => $created];
    $donationField = (object) ['value' => $donation];

    $submission = $this->createMock(RsvpSubmissionInterface::class);
    $submission->method('id')->willReturn((string) $submissionId);
    $submission->method('get')->willReturnCallback(static function (string $field) use ($createdField, $donationField) {
      return match ($field) {
        'created' => $createdField,
        'donation' => $donationField,
        default => NULL,
      };
    });

    return $submission;
  }

  private function createCompletedOrder(int $orderId, int $salesTimestamp): OrderInterface {
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn((string) $orderId);
    $order->method('getState')->willReturn(new class {

      public function getId(): string {
        return 'completed';
      }

    });
    $order->method('getCompletedTime')->willReturn($salesTimestamp);
    $order->method('getCreatedTime')->willReturn($salesTimestamp);
    return $order;
  }

  private function createTicketOrderItem(
    int $eventId,
    int $orderId,
    int $quantity,
    string $totalAmount,
  ): OrderItemInterface {
    $targetEventField = new class($eventId) {
      public function __construct(private int $targetId) {}
      public function __get(string $name) {
        return $name === 'target_id' ? $this->targetId : NULL;
      }
    };

    $orderIdField = new class($orderId) {
      public function __construct(private int $targetId) {}
      public function isEmpty(): bool {
        return FALSE;
      }
      public function __get(string $name) {
        return $name === 'target_id' ? $this->targetId : NULL;
      }
    };

    $orderItem = $this->createMock(OrderItemInterface::class);
    $orderItem->method('id')->willReturn((string) $orderId);
    $orderItem->method('bundle')->willReturn('default');
    $orderItem->method('hasField')->willReturnCallback(static function (string $field) {
      return in_array($field, ['field_target_event', 'order_id'], TRUE);
    });
    $orderItem->method('get')->willReturnCallback(function (string $field) use ($targetEventField, $orderIdField) {
      return match ($field) {
        'field_target_event' => $targetEventField,
        'order_id' => $orderIdField,
        default => $this->createEmptyField(),
      };
    });
    $orderItem->method('getQuantity')->willReturn((string) $quantity);
    $orderItem->method('getTotalPrice')->willReturn(new Price($totalAmount, 'AUD'));

    return $orderItem;
  }

  private function createRsvpDonationOrderItem(
    int $eventId,
    int $submissionId,
    int $orderId,
    string $totalAmount,
    int $itemId = 5001,
  ): OrderItemInterface {
    $targetEventField = new class($eventId) {
      public function __construct(private int $targetId) {}
      public function isEmpty(): bool {
        return FALSE;
      }
      public function __get(string $name) {
        return $name === 'target_id' ? $this->targetId : NULL;
      }
    };

    $submissionField = new class($submissionId) {
      public function __construct(private int $targetId) {}
      public function isEmpty(): bool {
        return FALSE;
      }
      public function __get(string $name) {
        return $name === 'target_id' ? $this->targetId : NULL;
      }
    };

    $orderIdField = new class($orderId) {
      public function __construct(private int $targetId) {}
      public function isEmpty(): bool {
        return FALSE;
      }
      public function __get(string $name) {
        return $name === 'target_id' ? $this->targetId : NULL;
      }
    };

    $orderItem = $this->createMock(OrderItemInterface::class);
    $orderItem->method('id')->willReturn((string) $itemId);
    $orderItem->method('bundle')->willReturn('rsvp_donation');
    $orderItem->method('hasField')->willReturnCallback(static function (string $field) {
      return in_array($field, ['field_target_event', 'field_rsvp_submission', 'order_id'], TRUE);
    });
    $orderItem->method('get')->willReturnCallback(function (string $field) use ($targetEventField, $submissionField, $orderIdField) {
      return match ($field) {
        'field_target_event' => $targetEventField,
        'field_rsvp_submission' => $submissionField,
        'order_id' => $orderIdField,
        default => $this->createEmptyField(),
      };
    });
    $orderItem->method('getTotalPrice')->willReturn(new Price($totalAmount, 'AUD'));

    return $orderItem;
  }

  private function createPopulatedField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    return $field;
  }

  private function createEmptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

  private function createBoostManagerStub(): BoostManager {
    return new BoostManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(TimeInterface::class),
      new BoostEntitlementManager(
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(TimeInterface::class),
        $this->createMock(LoggerInterface::class),
      ),
      $this->createMock(LoggerInterface::class),
    );
  }

}
