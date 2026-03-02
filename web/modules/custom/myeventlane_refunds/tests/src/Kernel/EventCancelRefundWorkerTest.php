<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_refunds\Plugin\QueueWorker\EventCancelRefundWorker;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests cursor progression for EventCancelRefundWorker batching.
 *
 * @group myeventlane_refunds
 */
final class EventCancelRefundWorkerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
  ];

  /**
   * Verifies cursor moves forward and batches do not requeue same items.
   */
  public function testCursorProgressionAcrossTwoBatches(): void {
    $cursor = 0;
    $allOrderItemIds = range(1, 65);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(function ($field, $value = NULL, $operator = '=') use (&$cursor, $query) {
      if ($field === 'order_item_id' && $operator === '>') {
        $cursor = (int) $value;
      }
      return $query;
    });
    $query->method('sort')->with('order_item_id', 'ASC')->willReturnSelf();
    $query->method('range')->with(0, 50)->willReturnSelf();
    $query->method('execute')->willReturnCallback(function () use (&$cursor, $allOrderItemIds): array {
      return array_values(array_filter(
        $allOrderItemIds,
        static fn (int $id): bool => $id > $cursor && $id <= ($cursor + 50),
      ));
    });

    $orderItemStorage = $this->createMock(EntityStorageInterface::class);
    $orderItemStorage->method('getQuery')->willReturn($query);

    $orderItemStorage->method('loadMultiple')->willReturnCallback(function (array $ids): array {
      $items = [];
      foreach ($ids as $id) {
        $state = new class {
          public function getId(): string {
            return 'completed';
          }
        };

        $order = $this->createMock(OrderInterface::class);
        $order->method('id')->willReturn($id);
        $order->method('getState')->willReturn($state);

        $items[$id] = new class($id, $order) {
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
      }
      return $items;
    });

    $event = $this->createMock(NodeInterface::class);
    $vendor = $this->createMock(AccountInterface::class);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(555)->willReturn($event);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('load')->with(777)->willReturn($vendor);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['node', $nodeStorage],
      ['user', $userStorage],
      ['commerce_order_item', $orderItemStorage],
    ]);

    $processedOrderIds = [];
    $refundProcessor = $this->createMock(RefundProcessor::class);
    $refundProcessor->method('requestRefund')->willReturnCallback(function (OrderInterface $order) use (&$processedOrderIds): int {
      $processedOrderIds[] = (int) $order->id();
      return (int) $order->id();
    });

    $queuedItems = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(function (array $item) use (&$queuedItems): void {
      $queuedItems[] = $item;
    });

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
      'event_id' => 555,
      'vendor_uid' => 777,
      'last_processed_order_item_id' => 0,
    ]);

    $this->assertCount(1, $queuedItems);
    $this->assertSame(50, (int) $queuedItems[0]['last_processed_order_item_id']);

    $worker->processItem($queuedItems[0]);

    $this->assertCount(1, $queuedItems);
    $this->assertCount(65, $processedOrderIds);
    $this->assertSame(65, count(array_unique($processedOrderIds)));
  }

}

