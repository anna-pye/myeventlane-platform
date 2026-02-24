<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Schedules and enqueues Pro abandoned cart reminders.
 */
final class AbandonedCartScheduler {

  private const STEP_W1 = 'w1';
  private const STEP_W2 = 'w2';
  private const QUEUE_ID = 'pro_abandoned_cart';
  private const JOB_TYPE_ID = 'pro_abandoned_cart_job';
  private const RECENT_WINDOW_SECONDS = 1209600;
  private const W1_DELAY = 3600;
  private const W2_DELAY = 86400;

  /**
   * Constructs the scheduler.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ProEntitlementManager $entitlementManager,
  ) {}

  /**
   * Scans recent carts and creates tracking rows for reminders.
   */
  public function scanRecentCartsAndSchedule(int $limit = 250): int {
    $now = $this->time->getRequestTime();
    $oldest = $now - self::RECENT_WINDOW_SECONDS;
    $latest = $now - self::W1_DELAY;

    $query = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('cart', 1)
      ->condition('changed', $oldest, '>=')
      ->condition('changed', $latest, '<=')
      ->range(0, $limit);
    $orderIds = $query->execute();

    if (!$orderIds) {
      return 0;
    }

    $orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple($orderIds);
    $scheduled = 0;
    foreach ($orders as $order) {
      if ($order instanceof OrderInterface) {
        $scheduled += $this->scheduleForOrder($order);
      }
    }

    if ($scheduled > 0) {
      $this->logger->info('Pro abandoned-cart scheduler created @count reminder tracking rows.', [
        '@count' => $scheduled,
      ]);
    }

    return $scheduled;
  }

  /**
   * Schedules reminder rows for an order.
   *
   * @return int
   *   Number of new rows created.
   */
  public function scheduleForOrder(OrderInterface $order): int {
    if (!$this->qualifiesForReminder($order)) {
      return 0;
    }

    $changed = (int) $order->getChangedTime();
    if ($changed <= 0) {
      $changed = $this->time->getRequestTime();
    }

    $store = $order->getStore();
    if (!$store instanceof StoreInterface || !$store->id()) {
      return 0;
    }

    $newRows = 0;
    $newRows += $this->insertTrackingRow((int) $order->id(), (int) $store->id(), self::STEP_W1, $changed + self::W1_DELAY);
    $newRows += $this->insertTrackingRow((int) $order->id(), (int) $store->id(), self::STEP_W2, $changed + self::W2_DELAY);
    return $newRows;
  }

  /**
   * Enqueues due reminders by tracking row id.
   *
   * @return int
   *   Number of jobs enqueued.
   */
  public function enqueueDueRows(int $limit = 250): int {
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(self::QUEUE_ID);
    if (!$queue) {
      $this->logger->error('AdvancedQueue queue "@queue" not found for abandoned cart reminders.', [
        '@queue' => self::QUEUE_ID,
      ]);
      return 0;
    }

    $now = $this->time->getRequestTime();
    $rows = $this->database->select('myeventlane_pro_abandoned_cart', 't')
      ->fields('t', ['id'])
      ->condition('status', 'scheduled')
      ->condition('scheduled', $now, '<=')
      ->condition('processed', NULL, 'IS NULL')
      ->condition('message', NULL, 'IS NULL')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();

    if (!$rows) {
      return 0;
    }

    $enqueued = 0;
    foreach ($rows as $rowId) {
      $rowId = (int) $rowId;
      if ($rowId <= 0) {
        continue;
      }

      $updated = $this->database->update('myeventlane_pro_abandoned_cart')
        ->fields(['message' => 'queued'])
        ->condition('id', $rowId)
        ->condition('status', 'scheduled')
        ->condition('processed', NULL, 'IS NULL')
        ->condition('message', NULL, 'IS NULL')
        ->execute();
      if ($updated !== 1) {
        continue;
      }

      $job = Job::create(self::JOB_TYPE_ID, ['tracking_id' => $rowId]);
      $queue->enqueueJob($job);
      $enqueued++;
    }

    if ($enqueued > 0) {
      $this->logger->info('Pro abandoned-cart scheduler enqueued @count due reminder jobs.', [
        '@count' => $enqueued,
      ]);
    }

    return $enqueued;
  }

  /**
   * Determines whether the order qualifies for abandoned-cart reminders.
   */
  public function qualifiesForReminder(OrderInterface $order): bool {
    if (!$this->isAbandonedState($order)) {
      return FALSE;
    }

    if (!$this->orderHasPurchasableItems($order)) {
      return FALSE;
    }

    $store = $order->getStore();
    if (!$store instanceof StoreInterface) {
      return FALSE;
    }

    $owner = $this->resolveStoreOwner($store);
    if ($owner === NULL) {
      return FALSE;
    }

    return $this->entitlementManager->isPro($owner);
  }

  /**
   * Inserts a reminder row, duplicate-safe via unique(order_id, step).
   */
  private function insertTrackingRow(int $orderId, int $storeId, string $step, int $scheduled): int {
    try {
      $this->database->insert('myeventlane_pro_abandoned_cart')
        ->fields([
          'order_id' => $orderId,
          'store_id' => $storeId,
          'step' => $step,
          'scheduled' => $scheduled,
          'status' => 'scheduled',
          'message' => NULL,
        ])
        ->execute();
      return 1;
    }
    catch (IntegrityConstraintViolationException) {
      return 0;
    }
  }

  /**
   * Resolves the store owner account from store owner or vendor linkage.
   */
  private function resolveStoreOwner(StoreInterface $store): ?object {
    $owner = $store->getOwner();
    if ($owner && (int) $owner->id() > 0) {
      return $owner;
    }

    if ($store->hasField('field_vendor_reference') && !$store->get('field_vendor_reference')->isEmpty()) {
      $vendor = $store->get('field_vendor_reference')->entity;
      if ($vendor instanceof Vendor) {
        $vendorOwner = $vendor->getOwner();
        if ($vendorOwner && (int) $vendorOwner->id() > 0) {
          return $vendorOwner;
        }
      }
    }

    return NULL;
  }

  /**
   * Determines whether the order is still an active cart.
   */
  private function isAbandonedState(OrderInterface $order): bool {
    $state = $order->getState()->getId();
    if (in_array($state, ['completed', 'placed', 'fulfilled', 'canceled', 'cancelled'], TRUE)) {
      return FALSE;
    }

    if (in_array($state, ['draft', 'cart'], TRUE)) {
      return TRUE;
    }

    return (bool) $order->get('cart')->value;
  }

  /**
   * Returns TRUE if order has at least one non-zero quantity order item.
   */
  private function orderHasPurchasableItems(OrderInterface $order): bool {
    foreach ($order->getItems() as $orderItem) {
      if ((string) $orderItem->getQuantity() !== '0' && (float) $orderItem->getQuantity() > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

