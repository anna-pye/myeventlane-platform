<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\myeventlane_refunds\Plugin\QueueWorker\EventCancelRefundWorker;
use Drupal\myeventlane_refunds\Service\RefundOrderInspectorInterface;
use Drupal\myeventlane_refunds\Service\RefundProcessorInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests cursor progression behavior for EventCancelRefundWorker.
 */
#[Group('myeventlane_refunds')]
final class EventCancelRefundWorkerTest extends UnitTestCase {

  /**
   * Ensures a mixed-value full batch uses the ticket-only refund operation.
   */
  public function testRequeuesMixedOrderWithTicketOnlyRefund(): void {
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $orderItemStorage = $this->createMock(EntityStorageInterface::class);

    $query = $this->createMock(QueryInterface::class);
    $orderItemIds = range(1, 50);

    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->with('order_item_id', 'ASC')->willReturnSelf();
    $query->method('range')->with(0, 50)->willReturnSelf();
    $query->method('execute')->willReturn($orderItemIds);
    $orderItemStorage->method('getQuery')->willReturn($query);

    $event = $this->createMock(NodeInterface::class);
    $nodeStorage->method('load')->with(99)->willReturn($event);
    $vendor = $this->createMock(AccountInterface::class);
    $userStorage->method('load')->with(123)->willReturn($vendor);

    $orderState = new class {

      /**
       * Returns the completed state ID.
       */
      public function getId(): string {
        return 'completed';
      }

    };
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(777);
    $order->method('getState')->willReturn($orderState);

    $orderItems = [];
    foreach ($orderItemIds as $orderItemId) {
      $orderItem = new class($orderItemId, $order) {

        public function __construct(
          private readonly int $id,
          private readonly OrderInterface $order,
        ) {}

        /**
         * Returns the parent order.
         */
        public function getOrder(): OrderInterface {
          return $this->order;
        }

        /**
         * Returns the order item ID.
         */
        public function id(): int {
          return $this->id;
        }

      };
      $orderItems[$orderItemId] = $orderItem;
    }
    $orderItemStorage->method('loadMultiple')->with($orderItemIds)->willReturn($orderItems);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['node', $nodeStorage],
      ['user', $userStorage],
      ['commerce_order_item', $orderItemStorage],
    ]);

    $orderInspector = $this->createMock(RefundOrderInspectorInterface::class);
    $orderInspector->method('calculateTicketSubtotalCents')->willReturn(1000);
    $orderInspector->method('calculateRefundableAmountCents')->willReturn(1500);

    $refundProcessor = $this->createMock(RefundProcessorInterface::class);
    $refundProcessor->expects($this->once())
      ->method('requestEventCancellationRefund')
      ->with(
        $this->isInstanceOf(OrderInterface::class),
        $this->isInstanceOf(NodeInterface::class),
        $this->isInstanceOf(AccountInterface::class),
      );

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())
      ->method('createItem')
      ->with([
        'event_id' => 99,
        'vendor_uid' => 123,
        'last_processed_order_item_id' => 50,
      ]);

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->with('event_cancel_refund_worker')->willReturn($queue);

    $worker = new EventCancelRefundWorker(
      [],
      'event_cancel_refund_worker',
      [],
      $entityTypeManager,
      $orderInspector,
      $refundProcessor,
      $this->createMock(LoggerInterface::class),
      $queueFactory,
    );

    $worker->processItem([
      'event_id' => 99,
      'vendor_uid' => 123,
      'last_processed_order_item_id' => 0,
    ]);
  }

  /**
   * Ensures donation-only orders do not create ticket refund requests.
   */
  public function testSkipsDonationOnlyOrder(): void {
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $orderItemStorage = $this->createMock(EntityStorageInterface::class);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->with('order_item_id', 'ASC')->willReturnSelf();
    $query->method('range')->with(0, 50)->willReturnSelf();
    $query->method('execute')->willReturn([1]);
    $orderItemStorage->method('getQuery')->willReturn($query);

    $event = $this->createMock(NodeInterface::class);
    $nodeStorage->method('load')->with(99)->willReturn($event);
    $vendor = $this->createMock(AccountInterface::class);
    $userStorage->method('load')->with(123)->willReturn($vendor);

    $orderState = new class {

      /**
       * Returns the completed state ID.
       */
      public function getId(): string {
        return 'completed';
      }

    };
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(495);
    $order->method('getState')->willReturn($orderState);
    $orderItem = new class($order) {

      /**
       * Constructs a test order item.
       */
      public function __construct(private readonly OrderInterface $order) {}

      /**
       * Returns the parent order.
       */
      public function getOrder(): OrderInterface {
        return $this->order;
      }

      /**
       * Returns the order item ID.
       */
      public function id(): int {
        return 1;
      }

    };
    $orderItemStorage->method('loadMultiple')->with([1])->willReturn([1 => $orderItem]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['node', $nodeStorage],
      ['user', $userStorage],
      ['commerce_order_item', $orderItemStorage],
    ]);

    $orderInspector = $this->createMock(RefundOrderInspectorInterface::class);
    $orderInspector->expects($this->once())
      ->method('calculateTicketSubtotalCents')
      ->with($order, 99)
      ->willReturn(0);
    $orderInspector->expects($this->never())->method('calculateRefundableAmountCents');

    $refundProcessor = $this->createMock(RefundProcessorInterface::class);
    $refundProcessor->expects($this->never())->method('requestEventCancellationRefund');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with(
        $this->stringContains('donations are excluded'),
        ['@order_id' => 495],
      );

    $worker = new EventCancelRefundWorker(
      [],
      'event_cancel_refund_worker',
      [],
      $entityTypeManager,
      $orderInspector,
      $refundProcessor,
      $logger,
      $this->createMock(QueueFactory::class),
    );

    $worker->processItem([
      'event_id' => 99,
      'vendor_uid' => 123,
      'last_processed_order_item_id' => 0,
    ]);
  }

}
