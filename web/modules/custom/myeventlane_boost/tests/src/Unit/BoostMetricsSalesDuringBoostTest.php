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
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for sales-during-boost entitlement window attribution.
 *
 * @group myeventlane_boost
 */
final class BoostMetricsSalesDuringBoostTest extends UnitTestCase {

  /**
   * Ensures multiple entitlement windows aggregate and gaps are excluded.
   */
  public function testSalesDuringBoostAggregatesMultipleWindowsAndExcludesGaps(): void {
    $event = $this->createPaidEvent(42);

    $entitlementManager = new BoostEntitlementManager(
      $this->createEntitlementEntityTypeManager(42, [
        [1, 1_000, 2_000],
        [2, 3_000, 4_000],
      ]),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $orders = [
      1 => $this->createCompletedOrder(1, 1_500),
      2 => $this->createCompletedOrder(2, 2_500),
      3 => $this->createCompletedOrder(3, 3_500),
      4 => $this->createCompletedOrder(4, 5_000),
    ];

    $orderItems = [
      $this->createTicketOrderItem(42, 1, 2, '50.00'),
      $this->createTicketOrderItem(42, 2, 1, '25.00'),
      $this->createTicketOrderItem(42, 3, 3, '75.00'),
      $this->createTicketOrderItem(42, 4, 4, '100.00'),
    ];

    $orderItemStorage = $this->createMock(EntityStorageInterface::class);
    $orderItemStorage->method('loadByProperties')
      ->with(['field_target_event' => 42])
      ->willReturn($orderItems);
    $orderItemQuery = $this->createMock(QueryInterface::class);
    $orderItemQuery->method('accessCheck')->willReturnSelf();
    $orderItemQuery->method('condition')->willReturnSelf();
    $orderItemQuery->method('execute')->willReturn([]);
    $orderItemStorage->method('getQuery')->willReturn($orderItemQuery);

    $orderStorage = $this->createMock(EntityStorageInterface::class);
    $orderStorage->method('load')->willReturnCallback(static function ($orderId) use ($orders) {
      return $orders[(int) $orderId] ?? NULL;
    });

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entityType) use ($orderItemStorage, $orderStorage) {
        return match ($entityType) {
          'commerce_order_item' => $orderItemStorage,
          'commerce_order' => $orderStorage,
          default => NULL,
        };
      });

    $service = new BoostMetricsService(
      $this->createMock(Connection::class),
      $entityTypeManager,
      $this->createBoostManagerStub(),
      $entitlementManager,
      $this->createMock(TimeInterface::class),
      $this->createMock(TranslationInterface::class),
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame('$125.00', $metrics['sales_during_boost']);
    $this->assertSame(125.0, $metrics['revenue_during_boost']);
    $this->assertSame(2, $metrics['orders_during_boost']);
    $this->assertSame(5, $metrics['tickets_during_boost']);
    $this->assertSame(2, $metrics['sales_during_period']['count']);
    $this->assertSame('$125.00', $metrics['sales_during_period']['revenue']);
  }

  /**
   * Ensures RSVP/free events without a linked product return zero paid metrics.
   */
  public function testSalesDuringBoostReturnsZerosForNonPaidEvents(): void {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn('99');
    $event->method('hasField')->with('field_product_target')->willReturn(TRUE);
    $event->method('get')->with('field_product_target')->willReturn($this->createEmptyField());

    $service = new BoostMetricsService(
      $this->createMock(Connection::class),
      $this->createOrderItemOnlyEntityTypeManager(),
      $this->createBoostManagerStub(),
      new BoostEntitlementManager(
        $this->createEntitlementEntityTypeManager(99, []),
        $this->createMock(TimeInterface::class),
        $this->createMock(LoggerInterface::class),
      ),
      $this->createMock(TimeInterface::class),
      $this->createMock(TranslationInterface::class),
    );

    $metrics = $service->getEventBoostMetrics($event);

    $this->assertSame('$0.00', $metrics['sales_during_boost']);
    $this->assertSame(0.0, $metrics['revenue_during_boost']);
    $this->assertSame(0, $metrics['orders_during_boost']);
    $this->assertSame(0, $metrics['tickets_during_boost']);
    $this->assertSame(['count' => 0, 'revenue' => '$0.00'], $metrics['sales_during_period']);
    $this->assertSame(0, $metrics['rsvps_during_boost']);
    $this->assertSame(0.0, $metrics['donation_revenue_during_boost']);
  }

  /**
   * Builds an entity type manager stub for entitlement window queries.
   *
   * @param array<int, array{0: int, 1: int, 2: int}> $windows
   *   Entitlement rows as [id, starts, ends].
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

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('myeventlane_boost_entitlement')
      ->willReturn($entitlementStorage);

    return $entityTypeManager;
  }

  /**
   * Builds a paid event node stub.
   */
  private function createPaidEvent(int $eventId): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn((string) $eventId);
    $event->method('hasField')->with('field_product_target')->willReturn(TRUE);
    $event->method('get')->with('field_product_target')->willReturn($this->createPopulatedField());
    return $event;
  }

  /**
   * Builds a completed order stub at a specific sales timestamp.
   */
  private function createCompletedOrder(int $orderId, int $salesTimestamp): OrderInterface {
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn((string) $orderId);
    $order->method('getState')->willReturn(new class {

      /**
       * Returns completed workflow state.
       */
      public function getId(): string {
        return 'completed';
      }

    });
    $order->method('getCompletedTime')->willReturn($salesTimestamp);
    $order->method('getCreatedTime')->willReturn($salesTimestamp);
    return $order;
  }

  /**
   * Builds a ticket order item linked to a parent order.
   */
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

  /**
   * Creates a populated entity reference field item list.
   */
  private function createPopulatedField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    return $field;
  }

  /**
   * Creates an empty entity reference field item list.
   */
  private function createEmptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

  /**
   * Creates an entity type manager stub with no boost order items.
   */
  private function createOrderItemOnlyEntityTypeManager(): EntityTypeManagerInterface {
    $orderItemQuery = $this->createMock(QueryInterface::class);
    $orderItemQuery->method('accessCheck')->willReturnSelf();
    $orderItemQuery->method('condition')->willReturnSelf();
    $orderItemQuery->method('execute')->willReturn([]);

    $orderItemStorage = $this->createMock(EntityStorageInterface::class);
    $orderItemStorage->method('getQuery')->willReturn($orderItemQuery);

    $entitlementQuery = $this->createMock(QueryInterface::class);
    $entitlementQuery->method('accessCheck')->willReturnSelf();
    $entitlementQuery->method('condition')->willReturnSelf();
    $entitlementQuery->method('sort')->willReturnSelf();
    $entitlementQuery->method('execute')->willReturn([]);

    $entitlementStorage = $this->createMock(EntityStorageInterface::class);
    $entitlementStorage->method('getQuery')->willReturn($entitlementQuery);
    $entitlementStorage->method('loadMultiple')->willReturn([]);

    $rsvpQuery = $this->createMock(QueryInterface::class);
    $rsvpQuery->method('accessCheck')->willReturnSelf();
    $rsvpQuery->method('condition')->willReturnSelf();
    $rsvpQuery->method('execute')->willReturn([]);

    $rsvpStorage = $this->createMock(EntityStorageInterface::class);
    $rsvpStorage->method('getQuery')->willReturn($rsvpQuery);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('rsvp_submission')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')
      ->willReturnCallback(static function (string $entityType) use ($orderItemStorage, $entitlementStorage, $rsvpStorage) {
        return match ($entityType) {
          'commerce_order_item' => $orderItemStorage,
          'myeventlane_boost_entitlement' => $entitlementStorage,
          'rsvp_submission' => $rsvpStorage,
          default => NULL,
        };
      });

    return $entityTypeManager;
  }

  /**
   * Creates a BoostManager instance for constructor injection.
   */
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
