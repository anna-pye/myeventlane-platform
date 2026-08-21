<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\StripeService;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Executes grouped Stripe transfers per vendor for selected unpaid ledger rows.
 *
 * One transfer per store_id. Each transfer aggregates the net amounts of all
 * selected unpaid rows belonging to that store. Failure for one vendor does
 * not block others.
 */
final class PayoutBatchController extends ControllerBase {

  private const TABLE = 'myeventlane_payout_ledger';

  private const CACHE_TAGS = ['myeventlane_payout_ledger'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $payoutLogger,
    protected CsrfTokenGenerator $csrfToken,
    protected StripeService $stripeService,
    protected ConfigFactoryInterface $migrationConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_admin_dashboard'),
      $container->get('csrf_token'),
      $container->get('myeventlane_core.stripe'),
      $container->get('config.factory'),
    );
  }

  /**
   * Processes a batch payout: one Stripe transfer per vendor (store).
   */
  public function executeBatch(Request $request): RedirectResponse {
    if ((bool) $this->migrationConfigFactory->get('myeventlane_core.settings')->get('direct_charge_enabled')) {
      throw new AccessDeniedHttpException('Legacy MEL transfers are disabled in direct-charge mode.');
    }
    $this->validateCsrf($request);
    $days = $this->extractDays($request);

    $ids = $request->request->all('ledger_ids');
    if (!is_array($ids) || empty($ids)) {
      $this->messenger()->addWarning($this->t('No payouts selected.'));
      return $this->redirectToPayouts($days);
    }

    $ids = array_filter(array_map('intval', $ids), fn(int $v) => $v > 0);
    if (empty($ids)) {
      $this->messenger()->addWarning($this->t('No valid payouts selected.'));
      return $this->redirectToPayouts($days);
    }

    $batchId = 'mel-batch-' . $this->time->getRequestTime() . '-' . $this->currentUser()->id();

    $this->payoutLogger->info(
      'Batch payout started by User @uid. Batch @bid. Requested IDs: @ids.',
      [
        '@uid' => $this->currentUser()->id(),
        '@bid' => $batchId,
        '@ids' => implode(', ', $ids),
      ]
    );

    $transaction = $this->database->startTransaction();

    try {
      $query = $this->database->select(self::TABLE, 'l');
      $query->fields('l')
        ->condition('l.id', $ids, 'IN')
        ->condition('l.status', 'unpaid');
      $query->forUpdate();
      $rows = $query->execute()->fetchAll();

      if (empty($rows)) {
        $this->messenger()->addWarning($this->t('No unpaid payouts found in selection.'));
        return $this->redirectToPayouts($days);
      }

      // Filter out rows with existing transfer_id or non-positive net.
      $validRows = [];
      foreach ($rows as $row) {
        /** @var object{id: int, transfer_id: string|null, net: int|float|string} $row */
        if (!empty($row->transfer_id)) {
          $this->payoutLogger->warning('Batch @bid: Ledger @lid already has transfer_id @tid. Skipping.', [
            '@bid' => $batchId,
            '@lid' => $row->id,
            '@tid' => $row->transfer_id,
          ]);
          continue;
        }
        if ((float) $row->net <= 0) {
          $this->payoutLogger->warning('Batch @bid: Ledger @lid has non-positive net @net. Skipping.', [
            '@bid' => $batchId,
            '@lid' => $row->id,
            '@net' => $row->net,
          ]);
          continue;
        }
        $validRows[] = $row;
      }

      if (empty($validRows)) {
        $this->messenger()->addWarning($this->t('No eligible unpaid payouts in selection.'));
        return $this->redirectToPayouts($days);
      }

      $groups = $this->groupByStore($validRows);

      $vendorsPaid = 0;
      $vendorsSkipped = 0;
      $rowsPaid = 0;
      $client = $this->stripeService->getPlatformClient();
      $now = $this->time->getRequestTime();

      foreach ($groups as $storeId => $group) {
        $connectedAccountId = $this->resolveConnectedAccount($storeId);
        if ($connectedAccountId === NULL) {
          $vendorsSkipped++;
          $skippedIds = implode(', ', array_column($group['rows'], 'id'));
          $this->payoutLogger->error(
            'Batch @bid: No Stripe Connect account for store @sid. Skipping @cnt rows (IDs: @ids).',
            [
              '@bid' => $batchId,
              '@sid' => $storeId,
              '@cnt' => count($group['rows']),
              '@ids' => $skippedIds,
            ]
          );
          $this->messenger()->addError(
            $this->t('Store #@sid has no Stripe account — @cnt payouts skipped.', [
              '@sid' => $storeId,
              '@cnt' => count($group['rows']),
            ])
          );
          continue;
        }

        $totalCents = (int) round($group['total_net'] * 100);
        $ledgerIdList = array_column($group['rows'], 'id');
        $idempotencyKey = $batchId . '-store-' . $storeId;

        try {
          $transfer = $client->transfers->create([
            'amount' => $totalCents,
            'currency' => 'aud',
            'destination' => $connectedAccountId,
            'metadata' => [
              'batch_id' => $batchId,
              'ledger_ids' => json_encode($ledgerIdList),
              'store_id' => (string) $storeId,
            ],
          ], [
            'idempotency_key' => $idempotencyKey,
          ]);

          $this->database->update(self::TABLE)
            ->fields([
              'status' => 'paid',
              'transfer_id' => $transfer->id,
              'paid_at' => $now,
            ])
            ->condition('id', $ledgerIdList, 'IN')
            ->execute();

          $vendorsPaid++;
          $rowsPaid += count($ledgerIdList);

          $this->payoutLogger->info(
            'Batch @bid: Transfer @tid created for store @sid. Amount @amt cents. Rows: @ids. User @uid.',
            [
              '@bid' => $batchId,
              '@tid' => $transfer->id,
              '@sid' => $storeId,
              '@amt' => $totalCents,
              '@ids' => implode(', ', $ledgerIdList),
              '@uid' => $this->currentUser()->id(),
            ]
          );
        }
        catch (ApiErrorException $e) {
          $vendorsSkipped++;

          $this->payoutLogger->error(
            'Batch @bid: Stripe transfer failed for store @sid: @msg. Rows NOT updated: @ids.',
            [
              '@bid' => $batchId,
              '@sid' => $storeId,
              '@msg' => $e->getMessage(),
              '@ids' => implode(', ', $ledgerIdList),
            ]
          );

          $this->messenger()->addError(
            $this->t('Stripe transfer failed for store #@sid. See logs.', ['@sid' => $storeId])
          );
        }
      }

    }
    catch (\Exception $e) {
      $transaction->rollBack();

      $this->payoutLogger->error(
        'Batch @bid: Unexpected error — full rollback: @msg',
        ['@bid' => $batchId, '@msg' => $e->getMessage()]
      );

      $this->messenger()->addError(
        $this->t('Batch payout failed unexpectedly. See logs.')
      );

      return $this->redirectToPayouts($days);
    }

    Cache::invalidateTags(self::CACHE_TAGS);

    $this->payoutLogger->info(
      'Batch @bid completed. Vendors paid: @paid. Vendors skipped: @skipped. Rows paid: @rows. User @uid.',
      [
        '@bid' => $batchId,
        '@paid' => $vendorsPaid,
        '@skipped' => $vendorsSkipped,
        '@rows' => $rowsPaid,
        '@uid' => $this->currentUser()->id(),
      ]
    );

    if ($vendorsPaid > 0) {
      $this->messenger()->addStatus(
        $this->t('Batch payout completed. @paid vendors paid (@rows rows). @skipped skipped.', [
          '@paid' => $vendorsPaid,
          '@rows' => $rowsPaid,
          '@skipped' => $vendorsSkipped,
        ])
      );
    }
    elseif ($vendorsSkipped > 0) {
      $this->messenger()->addWarning(
        $this->t('Batch payout: all @skipped vendors skipped. See logs.', ['@skipped' => $vendorsSkipped])
      );
    }

    return $this->redirectToPayouts($days);
  }

  /**
   * Groups ledger rows by store_id with summed net totals.
   *
   * @param object[] $rows
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
    $store = $this->entityTypeManager()
      ->getStorage('commerce_store')
      ->load($storeId);

    if (!$store instanceof StoreInterface) {
      return NULL;
    }

    if (!$store->hasField('field_stripe_account_id') || $store->get('field_stripe_account_id')->isEmpty()) {
      return NULL;
    }

    $accountId = trim((string) $store->get('field_stripe_account_id')->value);
    return $accountId !== '' ? $accountId : NULL;
  }

  private function validateCsrf(Request $request): void {
    $token = $request->request->get('token', '');
    if (!$this->csrfToken->validate((string) $token, 'payout_action')) {
      throw new AccessDeniedHttpException('Invalid or missing CSRF token.');
    }
  }

  private function extractDays(Request $request): int {
    $days = (int) $request->request->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

  private function redirectToPayouts(int $days): RedirectResponse {
    $url = Url::fromRoute('myeventlane_admin_dashboard.payouts', [], [
      'query' => ['days' => $days],
    ])->toString();
    return new RedirectResponse($url);
  }

}
