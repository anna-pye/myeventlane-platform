<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles payout ledger write actions (mark paid, reverse, bulk).
 */
final class PayoutActionController extends ControllerBase {

  private const TABLE = 'myeventlane_payout_ledger';

  private const CACHE_TAGS = ['myeventlane_payout_ledger'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_admin_dashboard'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Marks a single ledger entry as paid.
   */
  public function markPaid(int $ledger_id, Request $request): RedirectResponse {
    $this->validateCsrf($request);
    $row = $this->loadRow($ledger_id);

    $days = $this->extractDays($request);

    if ($row->status === 'paid') {
      $this->messenger()->addWarning($this->t('Payout #@id is already marked as paid.', ['@id' => $ledger_id]));
      return $this->redirectToPayouts($days);
    }

    $transferId = $this->sanitizeTransferId($request->request->get('transfer_id'));

    $fields = [
      'status' => 'paid',
      'paid_at' => $this->time->getRequestTime(),
    ];
    if ($transferId !== NULL) {
      $fields['transfer_id'] = $transferId;
      $fields['reversed_transfer_id'] = NULL;
    }

    $this->database->update(self::TABLE)
      ->fields($fields)
      ->condition('id', $ledger_id)
      ->execute();

    $this->logger->info('Payout marked paid. Ledger ID @lid, Order @oid, Store @sid, User @uid.', [
      '@lid' => $ledger_id,
      '@oid' => $row->order_id,
      '@sid' => $row->store_id,
      '@uid' => $this->currentUser()->id(),
    ]);

    Cache::invalidateTags(self::CACHE_TAGS);

    $this->messenger()->addStatus($this->t('Payout #@id marked as paid.', ['@id' => $ledger_id]));
    return $this->redirectToPayouts($days);
  }

  /**
   * Reverses a paid ledger entry back to unpaid.
   */
  public function markUnpaid(int $ledger_id, Request $request): RedirectResponse {
    $this->validateCsrf($request);
    $row = $this->loadRow($ledger_id);

    $days = $this->extractDays($request);

    if ($row->status === 'unpaid') {
      $this->messenger()->addWarning($this->t('Payout #@id is already unpaid.', ['@id' => $ledger_id]));
      return $this->redirectToPayouts($days);
    }

    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'unpaid',
        'paid_at' => NULL,
        'transfer_id' => NULL,
      ])
      ->condition('id', $ledger_id)
      ->execute();

    $this->logger->warning('Payout reversed to unpaid. Ledger ID @lid, Order @oid, Store @sid, User @uid.', [
      '@lid' => $ledger_id,
      '@oid' => $row->order_id,
      '@sid' => $row->store_id,
      '@uid' => $this->currentUser()->id(),
    ]);

    Cache::invalidateTags(self::CACHE_TAGS);

    $this->messenger()->addStatus($this->t('Payout #@id reversed to unpaid.', ['@id' => $ledger_id]));
    return $this->redirectToPayouts($days);
  }

  /**
   * Bulk-marks selected ledger entries as paid.
   */
  public function bulkMarkPaid(Request $request): RedirectResponse {
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

    $rows = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id', 'order_id', 'store_id', 'status'])
      ->condition('l.id', $ids, 'IN')
      ->condition('l.status', 'unpaid')
      ->execute()
      ->fetchAll();

    if (empty($rows)) {
      $this->messenger()->addWarning($this->t('No unpaid payouts found in selection.'));
      return $this->redirectToPayouts($days);
    }

    $now = $this->time->getRequestTime();
    $count = 0;
    foreach ($rows as $row) {
      $this->database->update(self::TABLE)
        ->fields([
          'status' => 'paid',
          'paid_at' => $now,
        ])
        ->condition('id', $row->id)
        ->execute();
      $count++;
    }

    $this->logger->info('Bulk payout action: @count payouts marked paid by User @uid. IDs: @ids.', [
      '@count' => $count,
      '@uid' => $this->currentUser()->id(),
      '@ids' => implode(', ', array_column($rows, 'id')),
    ]);

    Cache::invalidateTags(self::CACHE_TAGS);

    $this->messenger()->addStatus($this->t('@count payouts marked as paid.', ['@count' => $count]));
    return $this->redirectToPayouts($days);
  }

  /**
   * Loads a ledger row or throws 404.
   */
  private function loadRow(int $ledger_id): object {
    $row = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('l.id', $ledger_id)
      ->execute()
      ->fetchObject();

    if (!$row) {
      throw new NotFoundHttpException("Payout ledger entry #{$ledger_id} not found.");
    }

    return $row;
  }

  /**
   * Validates the CSRF token from the request.
   */
  private function validateCsrf(Request $request): void {
    $token = $request->request->get('token', '');
    if (!$this->csrfToken->validate((string) $token, 'payout_action')) {
      throw new AccessDeniedHttpException('Invalid or missing CSRF token.');
    }
  }

  /**
   * Sanitizes the optional transfer_id field.
   */
  private function sanitizeTransferId(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $value = trim(strip_tags((string) $value));
    if ($value === '') {
      return NULL;
    }
    return mb_substr($value, 0, 128);
  }

  /**
   * Extracts and sanitizes the days query param from the request.
   */
  private function extractDays(Request $request): int {
    $days = (int) $request->request->get('days', 30);
    return in_array($days, [7, 30, 90], TRUE) ? $days : 30;
  }

  /**
   * Returns a redirect response to the payouts page.
   */
  private function redirectToPayouts(int $days): RedirectResponse {
    $url = Url::fromRoute('myeventlane_admin_dashboard.payouts', [], [
      'query' => ['days' => $days],
    ])->toString();
    return new RedirectResponse($url);
  }

}
