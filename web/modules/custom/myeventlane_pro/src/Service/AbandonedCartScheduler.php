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
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
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
  private const MAX_SCAN_LIMIT = 1000;
  private const STATE_LAST_SCANNED_ORDER_ID = 'myeventlane_pro.abandoned_cart.last_scanned_order_id';

  private const STATUS_SCHEDULED = 'scheduled';
  private const STATUS_QUEUED = 'queued';

  /**
   * Ensures scheduler summary is only logged once per cron run.
   */
  private bool $summaryLogged = FALSE;

  /**
   * Constructs the scheduler.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ProActiveResolver $proActiveResolver,
    private readonly StateInterface $state,
    private readonly AbandonedCartTerminalStateResolver $terminalStateResolver,
  ) {}

  /**
   * Scans recent carts and creates tracking rows for reminders.
   */
  public function scanRecentCartsAndSchedule(int $limit = 250): int {
    $effectiveLimit = max(1, min($limit, self::MAX_SCAN_LIMIT));
    $lastScannedId = (int) $this->state->get(self::STATE_LAST_SCANNED_ORDER_ID, 0);
    $now = $this->time->getRequestTime();
    $oldest = $now - self::RECENT_WINDOW_SECONDS;
    $latest = $now - self::W1_DELAY;

    $query = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $lastScannedId, '>')
      ->condition('cart', 1)
      ->condition('changed', $oldest, '>=')
      ->condition('changed', $latest, '<=')
      ->sort('order_id', 'ASC')
      ->range(0, $effectiveLimit);
    $orderIds = array_values($query->execute());

    if ($orderIds === []) {
      return 0;
    }

    $orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple($orderIds);
    $scheduled = 0;

    foreach ($orderIds as $orderId) {
      $orderId = (int) $orderId;
      if ($orderId > 0) {
        $lastScannedId = max($lastScannedId, $orderId);
      }

      $order = $orders[$orderId] ?? NULL;
      if ($order instanceof OrderInterface) {
        $scheduled += $this->scheduleForOrder($order);
      }
    }

    $this->state->set(self::STATE_LAST_SCANNED_ORDER_ID, $lastScannedId);
    return $scheduled;
  }

  /**
   * Schedules reminder rows for an order.
   */
  public function scheduleForOrder(OrderInterface $order): int {
    if ($order->isNew() || !$this->orderHasPurchasableItems($order)) {
      return 0;
    }

    if (!$this->qualifiesForReminder($order)) {
      return 0;
    }

    $changed = (int) $order->getChangedTime();
    if ($changed <= 0) {
      $changed = $this->time->getRequestTime();
    }

    $orderId = (int) $order->id();
    $store = $order->getStore();
    if ($orderId <= 0 || !$store instanceof StoreInterface || !$store->id()) {
      return 0;
    }

    $storeId = (int) $store->id();
    $newRows = 0;
    $newRows += $this->insertTrackingRow($orderId, $storeId, self::STEP_W1, $changed + self::W1_DELAY);
    $newRows += $this->insertTrackingRow($orderId, $storeId, self::STEP_W2, $changed + self::W2_DELAY);
    return $newRows;
  }

  /**
   * Enqueues due reminders by tracking row id.
   */
  public function enqueueDueRows(int $limit = 250): int {
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(self::QUEUE_ID);
    if ($queue === NULL) {
      $this->logger->error('AdvancedQueue queue "@queue" not found for abandoned cart reminders.', [
        '@queue' => self::QUEUE_ID,
      ]);
      return 0;
    }

    $effectiveLimit = max(1, min($limit, self::MAX_SCAN_LIMIT));
    $now = $this->time->getRequestTime();
    $rowIds = $this->claimDueRowIds($effectiveLimit, $now);

    if ($rowIds === []) {
      return 0;
    }

    $enqueued = 0;
    foreach ($rowIds as $rowId) {
      $job = Job::create(self::JOB_TYPE_ID, ['tracking_id' => $rowId]);
      $queue->enqueueJob($job);
      $enqueued++;
    }

    return $enqueued;
  }

  /**
   * Logs one scheduler summary per cron run.
   */
  public function logRunSummary(int $scheduled, int $enqueued): void {
    if ($this->summaryLogged) {
      return;
    }

    $this->summaryLogged = TRUE;
    $this->logger->info(
      'Pro abandoned-cart scheduler summary: scheduled @scheduled rows, enqueued @enqueued jobs.',
      [
        '@scheduled' => $scheduled,
        '@enqueued' => $enqueued,
      ],
    );
  }

  /**
   * Determines whether the order qualifies for abandoned-cart reminders.
   */
  public function qualifiesForReminder(OrderInterface $order): bool {
    if ($order->isNew() || !$this->orderHasPurchasableItems($order)) {
      return FALSE;
    }

    if (!$this->isAbandonedState($order)) {
      return FALSE;
    }

    $store = $order->getStore();
    if (!$store instanceof StoreInterface) {
      return FALSE;
    }

    return $this->proActiveResolver->isStoreProActive($store);
  }

  /**
   * Claims due tracking row ids with concurrency-safe status transition.
   *
   * @return int[]
   *   Claimed tracking row ids.
   */
  private function claimDueRowIds(int $limit, int $now): array {
    $rowIds = [];
    $driver = $this->database->driver();
    $supportsSkipLocked = in_array($driver, ['pgsql', 'mysql'], TRUE);

    if ($supportsSkipLocked) {
      try {
        $transaction = $this->database->startTransaction();
        $query = sprintf(
          'SELECT id FROM {myeventlane_pro_abandoned_cart}
           WHERE status = :status AND scheduled <= :scheduled AND processed IS NULL
           ORDER BY id ASC LIMIT %d FOR UPDATE SKIP LOCKED',
          $limit,
        );
        $rowIds = array_map('intval', $this->database->query($query, [
          ':status' => self::STATUS_SCHEDULED,
          ':scheduled' => $now,
        ])->fetchCol());

        foreach ($rowIds as $rowId) {
          $this->database->update('myeventlane_pro_abandoned_cart')
            ->fields([
              'status' => self::STATUS_QUEUED,
              'message' => NULL,
            ])
            ->condition('id', $rowId)
            ->condition('status', self::STATUS_SCHEDULED)
            ->condition('processed', NULL, 'IS NULL')
            ->execute();
        }
        unset($transaction);
      }
      catch (\Throwable $exception) {
        $this->logger->warning('SKIP LOCKED claim failed, falling back to atomic row claims: @message', [
          '@message' => $exception->getMessage(),
        ]);
        $rowIds = [];
      }
    }

    if ($rowIds !== []) {
      return $rowIds;
    }

    $candidates = $this->database->select('myeventlane_pro_abandoned_cart', 't')
      ->fields('t', ['id'])
      ->condition('status', self::STATUS_SCHEDULED)
      ->condition('scheduled', $now, '<=')
      ->condition('processed', NULL, 'IS NULL')
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();

    foreach ($candidates as $candidate) {
      $rowId = (int) $candidate;
      if ($rowId <= 0) {
        continue;
      }

      $updated = $this->database->update('myeventlane_pro_abandoned_cart')
        ->fields([
          'status' => self::STATUS_QUEUED,
          'message' => NULL,
        ])
        ->condition('id', $rowId)
        ->condition('status', self::STATUS_SCHEDULED)
        ->condition('processed', NULL, 'IS NULL')
        ->execute();

      if ($updated === 1) {
        $rowIds[] = $rowId;
      }
    }

    return $rowIds;
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
          'status' => self::STATUS_SCHEDULED,
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
   * Determines whether the order is still an active cart.
   */
  private function isAbandonedState(OrderInterface $order): bool {
    if ($this->terminalStateResolver->isTerminalState($order)) {
      return FALSE;
    }

    $state = $order->getState()->getId();
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
      if ((float) $orderItem->getQuantity() > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

