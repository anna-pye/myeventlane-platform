<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\StripeService;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;

/**
 * Manages the payout batch approval workflow.
 *
 * Lifecycle: draft → pending → approved → executed.
 * Cancellation returns ledger rows to unpaid.
 */
final class PayoutBatchWorkflowService {

  private const LEDGER_TABLE = 'myeventlane_payout_ledger';
  private const BATCH_TABLE = 'myeventlane_payout_batch';
  private const ITEM_TABLE = 'myeventlane_payout_batch_item';
  private const CACHE_TAGS = ['myeventlane_payout_ledger', 'myeventlane_payout_batch'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StripeService $stripeService,
  ) {}

  /**
   * Creates a draft batch from selected unpaid ledger rows.
   *
   * Groups rows by store_id, creates batch + items, locks ledger rows
   * by setting their status to 'pending'.
   *
   * @return int
   *   The new batch ID.
   *
   * @throws \InvalidArgumentException
   *   If no valid unpaid ledger rows are found.
   * @throws \RuntimeException
   *   On unexpected database errors.
   */
  public function createBatchFromLedger(array $ledgerIds): int {
    $ids = array_filter(array_map('intval', $ledgerIds), fn(int $v) => $v > 0);
    if (empty($ids)) {
      throw new \InvalidArgumentException('No valid ledger IDs provided.');
    }

    $transaction = $this->database->startTransaction();

    try {
      $rows = $this->database->select(self::LEDGER_TABLE, 'l')
        ->fields('l')
        ->condition('l.id', $ids, 'IN')
        ->condition('l.status', 'unpaid')
        ->forUpdate()
        ->execute()
        ->fetchAll();

      if (empty($rows)) {
        throw new \InvalidArgumentException('No unpaid ledger rows found for the given IDs.');
      }

      $validRows = [];
      foreach ($rows as $row) {
        if ((float) $row->net <= 0) {
          $this->logger->warning('createBatch: Ledger @lid has non-positive net @net. Skipping.', [
            '@lid' => $row->id,
            '@net' => $row->net,
          ]);
          continue;
        }
        $validRows[] = $row;
      }

      if (empty($validRows)) {
        throw new \InvalidArgumentException('No eligible unpaid ledger rows (all have non-positive net).');
      }

      $groups = $this->groupByStore($validRows);
      $now = $this->time->getRequestTime();
      $uid = (int) $this->currentUser->id();
      $batchUuid = 'mel-batch-' . bin2hex(random_bytes(16));

      $batchId = (int) $this->database->insert(self::BATCH_TABLE)
        ->fields([
          'batch_uuid' => $batchUuid,
          'status' => 'draft',
          'created' => $now,
          'created_uid' => $uid,
        ])
        ->execute();

      foreach ($groups as $storeId => $group) {
        $ledgerIdList = array_column($group['rows'], 'id');
        $this->database->insert(self::ITEM_TABLE)
          ->fields([
            'batch_id' => $batchId,
            'store_id' => $storeId,
            'ledger_ids' => json_encode(array_map('intval', $ledgerIdList)),
            'total_net' => $group['total_net'],
            'currency' => 'AUD',
            'status' => 'pending',
          ])
          ->execute();
      }

      $allLedgerIds = array_column($validRows, 'id');
      $this->database->update(self::LEDGER_TABLE)
        ->fields(['status' => 'pending'])
        ->condition('id', $allLedgerIds, 'IN')
        ->execute();

      $this->logger->info(
        'Payout batch created. Batch @bid (UUID @uuid). Vendors: @vcnt. Rows: @rcnt. User @uid.',
        [
          '@bid' => $batchId,
          '@uuid' => $batchUuid,
          '@vcnt' => count($groups),
          '@rcnt' => count($validRows),
          '@uid' => $uid,
        ]
      );

      Cache::invalidateTags(self::CACHE_TAGS);
      return $batchId;
    }
    catch (\InvalidArgumentException $e) {
      $transaction->rollBack();
      throw $e;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('createBatch failed: @msg', ['@msg' => $e->getMessage()]);
      throw new \RuntimeException('Failed to create payout batch.', 0, $e);
    }
  }

  /**
   * Submits a draft batch for approval (draft → pending).
   *
   * @throws \RuntimeException
   */
  public function submitBatch(int $batchId): void {
    $batch = $this->loadBatch($batchId);
    if ($batch->status !== 'draft') {
      throw new \RuntimeException("Cannot submit batch #{$batchId}: status is '{$batch->status}', expected 'draft'.");
    }

    $this->database->update(self::BATCH_TABLE)
      ->fields(['status' => 'pending'])
      ->condition('id', $batchId)
      ->execute();

    $this->logger->info('Payout batch @bid submitted for approval by user @uid.', [
      '@bid' => $batchId,
      '@uid' => $this->currentUser->id(),
    ]);

    Cache::invalidateTags(self::CACHE_TAGS);
  }

  /**
   * Approves a pending batch (pending → approved).
   *
   * Also transitions ledger rows from 'pending' to 'approved'.
   *
   * @throws \RuntimeException
   */
  public function approveBatch(int $batchId): void {
    $batch = $this->loadBatch($batchId);
    if ($batch->status !== 'pending') {
      throw new \RuntimeException("Cannot approve batch #{$batchId}: status is '{$batch->status}', expected 'pending'.");
    }

    $now = $this->time->getRequestTime();
    $uid = (int) $this->currentUser->id();

    $this->database->update(self::BATCH_TABLE)
      ->fields([
        'status' => 'approved',
        'approved' => $now,
        'approved_uid' => $uid,
      ])
      ->condition('id', $batchId)
      ->execute();

    $allLedgerIds = $this->collectLedgerIdsFromBatch($batchId);
    if (!empty($allLedgerIds)) {
      $this->database->update(self::LEDGER_TABLE)
        ->fields(['status' => 'approved'])
        ->condition('id', $allLedgerIds, 'IN')
        ->execute();
    }

    $this->logger->info('Payout batch @bid approved by user @uid. Ledger rows: @cnt.', [
      '@bid' => $batchId,
      '@uid' => $uid,
      '@cnt' => count($allLedgerIds),
    ]);

    Cache::invalidateTags(self::CACHE_TAGS);
  }

  /**
   * Cancels a batch (draft/pending/approved → cancelled).
   *
   * Reverts ledger rows to 'unpaid'.
   *
   * @throws \RuntimeException
   */
  public function cancelBatch(int $batchId): void {
    $batch = $this->loadBatch($batchId);
    $allowed = ['draft', 'pending', 'approved'];
    if (!in_array($batch->status, $allowed, TRUE)) {
      throw new \RuntimeException("Cannot cancel batch #{$batchId}: status is '{$batch->status}'.");
    }

    $allLedgerIds = $this->collectLedgerIdsFromBatch($batchId);

    $this->database->update(self::BATCH_TABLE)
      ->fields(['status' => 'cancelled'])
      ->condition('id', $batchId)
      ->execute();

    if (!empty($allLedgerIds)) {
      $this->database->update(self::LEDGER_TABLE)
        ->fields(['status' => 'unpaid'])
        ->condition('id', $allLedgerIds, 'IN')
        ->execute();
    }

    $this->logger->info('Payout batch @bid cancelled by user @uid. @cnt ledger rows reverted to unpaid.', [
      '@bid' => $batchId,
      '@uid' => $this->currentUser->id(),
      '@cnt' => count($allLedgerIds),
    ]);

    Cache::invalidateTags(self::CACHE_TAGS);
  }

  /**
   * Executes an approved batch: creates one Stripe transfer per vendor.
   *
   * Each vendor item is processed independently. Stripe errors for one vendor
   * do not block others.
   *
   * @return array{vendors_paid: int, vendors_skipped: int, rows_paid: int, notes: string}
   *
   * @throws \RuntimeException
   */
  public function executeBatch(int $batchId): array {
    $batch = $this->loadBatch($batchId);
    if ($batch->status !== 'approved') {
      throw new \RuntimeException("Cannot execute batch #{$batchId}: status is '{$batch->status}', expected 'approved'.");
    }

    $items = $this->database->select(self::ITEM_TABLE, 'i')
      ->fields('i')
      ->condition('i.batch_id', $batchId)
      ->condition('i.status', 'pending')
      ->execute()
      ->fetchAll();

    if (empty($items)) {
      throw new \RuntimeException("Batch #{$batchId} has no pending items to execute.");
    }

    $uid = (int) $this->currentUser->id();
    $now = $this->time->getRequestTime();
    $client = $this->stripeService->getPlatformClient();

    $vendorsPaid = 0;
    $vendorsSkipped = 0;
    $rowsPaid = 0;
    $notes = [];

    $this->logger->info('Executing payout batch @bid (UUID @uuid). Items: @cnt. User @uid.', [
      '@bid' => $batchId,
      '@uuid' => $batch->batch_uuid,
      '@cnt' => count($items),
      '@uid' => $uid,
    ]);

    foreach ($items as $item) {
      $storeId = (int) $item->store_id;
      $ledgerIds = json_decode($item->ledger_ids, TRUE) ?? [];

      $connectedAccountId = $this->resolveConnectedAccount($storeId);

      if ($connectedAccountId === NULL) {
        $this->database->update(self::ITEM_TABLE)
          ->fields([
            'status' => 'skipped',
            'error_message' => 'No Stripe Connect account for store #' . $storeId,
          ])
          ->condition('id', $item->id)
          ->execute();

        $vendorsSkipped++;
        $notes[] = "Store #{$storeId}: skipped (no Stripe account).";

        $this->logger->error('Batch @bid: No Stripe account for store @sid. Item @iid skipped.', [
          '@bid' => $batchId,
          '@sid' => $storeId,
          '@iid' => $item->id,
        ]);
        continue;
      }

      $totalCents = (int) round((float) $item->total_net * 100);
      $idempotencyKey = $batch->batch_uuid . '-store-' . $storeId;

      try {
        $transfer = $client->transfers->create([
          'amount' => $totalCents,
          'currency' => strtolower($item->currency),
          'destination' => $connectedAccountId,
          'metadata' => [
            'batch_uuid' => $batch->batch_uuid,
            'batch_id' => (string) $batchId,
            'ledger_ids' => $item->ledger_ids,
            'store_id' => (string) $storeId,
          ],
        ], [
          'idempotency_key' => $idempotencyKey,
        ]);

        $this->database->update(self::ITEM_TABLE)
          ->fields([
            'stripe_transfer_id' => $transfer->id,
            'status' => 'executed',
          ])
          ->condition('id', $item->id)
          ->execute();

        if (!empty($ledgerIds)) {
          $this->database->update(self::LEDGER_TABLE)
            ->fields([
              'status' => 'paid',
              'transfer_id' => $transfer->id,
              'paid_at' => $now,
            ])
            ->condition('id', $ledgerIds, 'IN')
            ->execute();
        }

        $vendorsPaid++;
        $rowsPaid += count($ledgerIds);

        $this->logger->info(
          'Batch @bid: Transfer @tid created for store @sid. Amount @amt cents. Rows: @ids.',
          [
            '@bid' => $batchId,
            '@tid' => $transfer->id,
            '@sid' => $storeId,
            '@amt' => $totalCents,
            '@ids' => implode(', ', $ledgerIds),
          ]
        );
      }
      catch (ApiErrorException $e) {
        $errorMsg = $e->getMessage();

        $this->database->update(self::ITEM_TABLE)
          ->fields([
            'status' => 'failed',
            'error_message' => mb_substr($errorMsg, 0, 1000),
          ])
          ->condition('id', $item->id)
          ->execute();

        $vendorsSkipped++;
        $notes[] = "Store #{$storeId}: Stripe error — " . mb_substr($errorMsg, 0, 200);

        $this->logger->error(
          'Batch @bid: Stripe transfer failed for store @sid: @msg. Ledger rows NOT updated.',
          [
            '@bid' => $batchId,
            '@sid' => $storeId,
            '@msg' => $errorMsg,
          ]
        );
      }
    }

    $pendingCount = (int) $this->database->select(self::ITEM_TABLE, 'i')
      ->condition('i.batch_id', $batchId)
      ->condition('i.status', 'pending')
      ->countQuery()
      ->execute()
      ->fetchField();

    $batchStatus = ($pendingCount === 0) ? 'executed' : 'approved';
    $notesText = !empty($notes) ? implode("\n", $notes) : NULL;

    $updateFields = [
      'status' => $batchStatus,
      'executed' => $now,
      'executed_uid' => $uid,
    ];
    if ($notesText !== NULL) {
      $updateFields['notes'] = $notesText;
    }

    $this->database->update(self::BATCH_TABLE)
      ->fields($updateFields)
      ->condition('id', $batchId)
      ->execute();

    Cache::invalidateTags(self::CACHE_TAGS);

    $this->logger->info(
      'Batch @bid execution complete. Status: @status. Vendors paid: @paid. Skipped: @skipped. Rows: @rows. User @uid.',
      [
        '@bid' => $batchId,
        '@status' => $batchStatus,
        '@paid' => $vendorsPaid,
        '@skipped' => $vendorsSkipped,
        '@rows' => $rowsPaid,
        '@uid' => $uid,
      ]
    );

    return [
      'vendors_paid' => $vendorsPaid,
      'vendors_skipped' => $vendorsSkipped,
      'rows_paid' => $rowsPaid,
      'notes' => $notesText ?? '',
    ];
  }

  /**
   * Loads a batch record by ID.
   *
   * @throws \InvalidArgumentException
   */
  public function loadBatch(int $batchId): object {
    $batch = $this->database->select(self::BATCH_TABLE, 'b')
      ->fields('b')
      ->condition('b.id', $batchId)
      ->execute()
      ->fetchObject();

    if (!$batch) {
      throw new \InvalidArgumentException("Payout batch #{$batchId} not found.");
    }

    return $batch;
  }

  /**
   * Loads batch items for a given batch.
   *
   * @return object[]
   */
  public function loadBatchItems(int $batchId): array {
    return $this->database->select(self::ITEM_TABLE, 'i')
      ->fields('i')
      ->condition('i.batch_id', $batchId)
      ->orderBy('i.store_id')
      ->execute()
      ->fetchAll();
  }

  /**
   * Lists batches, optionally filtered by status.
   *
   * @return object[]
   */
  public function listBatches(?string $statusFilter = NULL): array {
    $query = $this->database->select(self::BATCH_TABLE, 'b')
      ->fields('b')
      ->orderBy('b.created', 'DESC')
      ->range(0, 100);

    if ($statusFilter !== NULL && $statusFilter !== '') {
      $query->condition('b.status', $statusFilter);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Resolves display labels for a set of store IDs.
   *
   * @return array<int, string>
   */
  public function resolveStoreLabels(array $storeIds): array {
    $labels = [];
    if (empty($storeIds)) {
      return $labels;
    }

    $stores = $this->entityTypeManager
      ->getStorage('commerce_store')
      ->loadMultiple($storeIds);

    foreach ($stores as $store) {
      $labels[(int) $store->id()] = (string) $store->label();
    }

    return $labels;
  }

  /**
   * Collects all ledger IDs across a batch's items (decoded from JSON).
   *
   * @return int[]
   */
  private function collectLedgerIdsFromBatch(int $batchId): array {
    $items = $this->database->select(self::ITEM_TABLE, 'i')
      ->fields('i', ['ledger_ids'])
      ->condition('i.batch_id', $batchId)
      ->execute()
      ->fetchAll();

    $allIds = [];
    foreach ($items as $item) {
      $decoded = json_decode($item->ledger_ids, TRUE);
      if (is_array($decoded)) {
        $allIds = array_merge($allIds, array_map('intval', $decoded));
      }
    }

    return array_unique($allIds);
  }

  /**
   * Groups ledger rows by store_id with summed net totals.
   *
   * @return array<int, array{rows: list<object>, total_net: float}>
   */
  private function groupByStore(array $rows): array {
    $groups = [];
    foreach ($rows as $row) {
      $sid = (int) $row->store_id;
      if (!isset($groups[$sid])) {
        $groups[$sid] = ['rows' => [], 'total_net' => 0.0];
      }
      $groups[$sid]['rows'][] = $row;
      $groups[$sid]['total_net'] += (float) $row->net;
    }
    return $groups;
  }

  /**
   * Resolves the Stripe Connect account ID from a commerce_store.
   */
  private function resolveConnectedAccount(int $storeId): ?string {
    $store = $this->entityTypeManager
      ->getStorage('commerce_store')
      ->load($storeId);

    if (!$store) {
      return NULL;
    }

    if (!$store->hasField('field_stripe_account_id') || $store->get('field_stripe_account_id')->isEmpty()) {
      return NULL;
    }

    $accountId = trim((string) $store->get('field_stripe_account_id')->value);
    return $accountId !== '' ? $accountId : NULL;
  }

}
