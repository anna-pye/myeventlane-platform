<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker to process event cancellation refunds (all orders for an event).
 *
 * @QueueWorker(
 *   id = "event_cancel_refund_worker",
 *   title = @Translation("Event Cancel Refund Worker"),
 *   cron = {"time" = 60}
 * )
 */
final class EventCancelRefundWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum orders to process per queue run.
   */
  private const BATCH_SIZE = 50;

  /**
   * Constructs EventCancelRefundWorker.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspector $orderInspector
   *   The order inspector.
   * @param \Drupal\myeventlane_refunds\Service\RefundProcessor $refundProcessor
   *   The refund processor.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RefundOrderInspector $orderInspector,
    private readonly RefundProcessor $refundProcessor,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('myeventlane_refunds.order_inspector'),
      $container->get('myeventlane_refunds.processor'),
      $container->get('logger.factory')->get('myeventlane_refunds'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $eventId = $data['event_id'] ?? NULL;
    $vendorUid = $data['vendor_uid'] ?? NULL;

    if (!$eventId || !$vendorUid) {
      $this->logger->error('EventCancelRefundWorker: Missing event_id or vendor_uid in queue item.');
      return;
    }

    // Load event.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event instanceof NodeInterface) {
      $this->logger->error('EventCancelRefundWorker: Event @event_id not found.', ['@event_id' => $eventId]);
      return;
    }

    // Load vendor.
    $userStorage = $this->entityTypeManager->getStorage('user');
    $vendor = $userStorage->load($vendorUid);
    if (!$vendor) {
      $this->logger->error('EventCancelRefundWorker: Vendor @uid not found.', ['@uid' => $vendorUid]);
      return;
    }

    // Get orders for this event (limit to batch size).
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->range(0, self::BATCH_SIZE)
      ->execute();

    if (empty($orderItemIds)) {
      $this->logger->info('EventCancelRefundWorker: No orders found for event @event_id.', ['@event_id' => $eventId]);
      return;
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $processedOrders = [];
    $refundedCount = 0;

    foreach ($orderItems as $item) {
      try {
        $order = $item->getOrder();
        if (!$order) {
          continue;
        }

        $orderId = $order->id();
        if (isset($processedOrders[$orderId])) {
          continue;
        }
        $processedOrders[$orderId] = TRUE;

        $orderState = $order->getState()->getId();
        if (!in_array($orderState, ['completed', 'fulfilled', 'placed'], TRUE)) {
          continue;
        }

        // Request full refund for tickets only (donations excluded by default).
        $refundPayload = [
          'refund_type' => 'full',
          'refund_scope' => 'tickets_only',
          'include_donation' => FALSE,
          'reason' => 'Event cancelled',
        ];

        try {
          $this->refundProcessor->requestRefund($order, $event, $vendor, $refundPayload);
          $refundedCount++;
        }
        catch (\Exception $e) {
          $this->logger->error('EventCancelRefundWorker: Failed to refund order @order_id: @message', [
            '@order_id' => $orderId,
            '@message' => $e->getMessage(),
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('EventCancelRefundWorker: Error processing order item @item_id: @message', [
          '@item_id' => $item->id(),
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
    }

    $this->logger->info('EventCancelRefundWorker: Processed @count refund(s) for event @event_id.', [
      '@count' => $refundedCount,
      '@event_id' => $eventId,
    ]);

    // If we processed a full batch, re-queue to continue processing.
    if (count($processedOrders) >= self::BATCH_SIZE) {
      $queue = \Drupal::service('queue')->get('event_cancel_refund_worker');
      $queue->createItem($data);
    }
  }

}







