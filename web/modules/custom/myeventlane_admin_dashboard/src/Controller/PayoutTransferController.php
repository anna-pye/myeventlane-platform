<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\Cache;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates Stripe transfers for unpaid payout ledger entries.
 */
final class PayoutTransferController extends ControllerBase {

  private const TABLE = 'myeventlane_payout_ledger';

  private const CACHE_TAGS = ['myeventlane_payout_ledger'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $payoutLogger,
    protected CsrfTokenGenerator $csrfToken,
    protected StripeService $stripeService,
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
    );
  }

  /**
   * Creates a Stripe transfer for a single unpaid ledger entry.
   */
  public function createTransfer(int $ledger_id, Request $request): RedirectResponse {
    $this->validateCsrf($request);
    $days = $this->extractDays($request);

    $transaction = $this->database->startTransaction();

    try {
      $row = $this->loadRowForUpdate($ledger_id);

      if ($row->status !== 'unpaid') {
        $this->messenger()->addWarning(
          $this->t('Payout #@id is already @status.', ['@id' => $ledger_id, '@status' => $row->status])
        );
        return $this->redirectToPayouts($days);
      }

      if (!empty($row->transfer_id)) {
        $this->messenger()->addWarning(
          $this->t('Payout #@id already has transfer @tid.', ['@id' => $ledger_id, '@tid' => $row->transfer_id])
        );
        return $this->redirectToPayouts($days);
      }

      $netAmount = (float) $row->net;
      if ($netAmount <= 0) {
        $this->payoutLogger->warning('Payout #@id has non-positive net amount (@net). Transfer aborted.', [
          '@id' => $ledger_id,
          '@net' => $netAmount,
        ]);
        $this->messenger()->addError(
          $this->t('Payout #@id has a non-positive net amount ($@net). Cannot create transfer.', [
            '@id' => $ledger_id,
            '@net' => number_format($netAmount, 2),
          ])
        );
        return $this->redirectToPayouts($days);
      }

      $connectedAccountId = $this->resolveConnectedAccount((int) $row->store_id);
      if ($connectedAccountId === NULL) {
        $this->payoutLogger->error('No Stripe Connect account for store @sid (ledger @lid). Transfer aborted.', [
          '@sid' => $row->store_id,
          '@lid' => $ledger_id,
        ]);
        $this->messenger()->addError(
          $this->t('Store #@sid has no Stripe Connect account. Cannot create transfer for payout #@id.', [
            '@sid' => $row->store_id,
            '@id' => $ledger_id,
          ])
        );
        return $this->redirectToPayouts($days);
      }

      $amountCents = (int) round($netAmount * 100);
      // Include request time so a retry after a transient failure (e.g.
      // insufficient funds) gets a fresh idempotency key.  Same-second
      // duplicate submissions still share a key and are de-duplicated.
      // The SELECT … FOR UPDATE above is the authoritative double-pay guard.
      $idempotencyKey = 'mel-transfer-' . $ledger_id . '-' . $this->time->getRequestTime();

      $client = $this->stripeService->getPlatformClient();
      $transfer = $client->transfers->create([
        'amount' => $amountCents,
        'currency' => 'aud',
        'destination' => $connectedAccountId,
        'metadata' => [
          'order_id' => (string) $row->order_id,
          'ledger_id' => (string) $ledger_id,
        ],
      ], [
        'idempotency_key' => $idempotencyKey,
      ]);

      $this->database->update(self::TABLE)
        ->fields([
          'status' => 'paid',
          'transfer_id' => $transfer->id,
          'reversed_transfer_id' => NULL,
          'paid_at' => $this->time->getRequestTime(),
        ])
        ->condition('id', $ledger_id)
        ->execute();

      // Transaction commits when $transaction goes out of scope.
      Cache::invalidateTags(self::CACHE_TAGS);

      $this->payoutLogger->info(
        'Manual Stripe transfer created. Ledger @lid, Order @oid, Store @sid, Transfer @tid, Amount @amt cents, User @uid.',
        [
          '@lid' => $ledger_id,
          '@oid' => $row->order_id,
          '@sid' => $row->store_id,
          '@tid' => $transfer->id,
          '@amt' => $amountCents,
          '@uid' => $this->currentUser()->id(),
        ]
      );

      $this->messenger()->addStatus(
        $this->t('Transfer @tid created for payout #@id ($@net).', [
          '@tid' => $transfer->id,
          '@id' => $ledger_id,
          '@net' => number_format($netAmount, 2),
        ])
      );

    }
    catch (ApiErrorException $e) {
      $transaction->rollBack();

      $this->payoutLogger->error(
        'Stripe transfer failed for ledger @lid, order @oid: @msg',
        [
          '@lid' => $ledger_id,
          '@oid' => $row->order_id ?? 'unknown',
          '@msg' => $e->getMessage(),
        ]
      );

      $this->messenger()->addError(
        $this->t('Stripe transfer failed for payout #@id. See logs for details.', ['@id' => $ledger_id])
      );
    }
    catch (\Exception $e) {
      $transaction->rollBack();

      $this->payoutLogger->error(
        'Unexpected error creating transfer for ledger @lid: @msg',
        ['@lid' => $ledger_id, '@msg' => $e->getMessage()]
      );

      $this->messenger()->addError(
        $this->t('An unexpected error occurred for payout #@id. See logs.', ['@id' => $ledger_id])
      );
    }

    return $this->redirectToPayouts($days);
  }

  /**
   * Loads a ledger row with a SELECT … FOR UPDATE to prevent races.
   */
  private function loadRowForUpdate(int $ledger_id): object {
    $query = $this->database->select(self::TABLE, 'l');
    $query->fields('l')->condition('l.id', $ledger_id);
    $query->forUpdate();
    $row = $query->execute()->fetchObject();

    if (!$row) {
      throw new NotFoundHttpException("Payout ledger entry #{$ledger_id} not found.");
    }

    return $row;
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

  /**
   * Validates the payout action CSRF token.
   */
  private function validateCsrf(Request $request): void {
    $token = $request->request->get('token', '');
    if (!$this->csrfToken->validate((string) $token, 'payout_action')) {
      throw new AccessDeniedHttpException('Invalid or missing CSRF token.');
    }
  }

  /**
   * Extracts the supported payout reporting window.
   */
  private function extractDays(Request $request): int {
    $days = (int) $request->request->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

  /**
   * Redirects to the payout ledger for the selected reporting window.
   */
  private function redirectToPayouts(int $days): RedirectResponse {
    $url = Url::fromRoute('myeventlane_admin_dashboard.payouts', [], [
      'query' => ['days' => $days],
    ])->toString();
    return new RedirectResponse($url);
  }

}
