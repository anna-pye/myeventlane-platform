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
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests cursor progression behavior for EventCancelRefundWorker.
 *
 * @group myeventlane_refunds
 */
final class EventCancelRefundWorkerTest extends UnitTestCase {

  /**
   * Ensures a full batch requeues with a forward-only cursor.
   */
  public function testRequeuesWithForwardCursor(): void {
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
        public function getOrder(): OrderInterface {
          return $this->order;
        }
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

    $refundProcessor = $this->createMock(RefundProcessor::class);
    $refundProcessor->expects($this->once())
      ->method('requestRefund')
      ->with(
        $this->isInstanceOf(OrderInterface::class),
        $this->isInstanceOf(NodeInterface::class),
        $this->isInstanceOf(AccountInterface::class),
        $this->arrayHasKey('refund_scope')
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
      $this->createMock(RefundOrderInspector::class),
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

}

