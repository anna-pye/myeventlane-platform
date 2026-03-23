<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Vendor MEL % contribution invoices: accrual grouping, settlement via platform_donation.
 */
final class VendorContributionInvoiceService {

  use StringTranslationTrait;

  public const INVOICE_DRAFT = 'draft';

  public const INVOICE_ISSUED = 'issued';

  public const INVOICE_PAID = 'paid';

  public const INVOICE_PARTIAL = 'partial';

  public const INVOICE_VOID = 'void';

  public const BILLING_UNBILLED = 'unbilled';

  public const BILLING_BILLED = 'billed';

  public const BILLING_PAID = 'paid';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly PlatformDonationService $platformDonationService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_donations');
  }

  /**
   * Resolves vendor entity ID for a Drupal user (owner or team member).
   */
  public function resolveVendorIdForUser(int $uid): ?int {
    if ($uid <= 0) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();
    if (!empty($owner_ids)) {
      return (int) reset($owner_ids);
    }
    $member_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1)
      ->execute();
    if (!empty($member_ids)) {
      return (int) reset($member_ids);
    }
    return NULL;
  }

  /**
   * Dashboard summary for one vendor (primary display: first currency with activity).
   *
   * @return array<string, mixed>
   */
  public function getVendorDashboardSummary(int $vendorId): array {
    $defaults = [
      'accrued_unbilled_by_currency' => [],
      'invoiced_outstanding_by_currency' => [],
      'collected_by_currency' => [],
    ];
    if ($vendorId <= 0 || !$this->tablesExist()) {
      return $defaults;
    }

    $defaults['accrued_unbilled_by_currency'] = $this->sumUnbilledAccruedByCurrency($vendorId);
    $defaults['invoiced_outstanding_by_currency'] = $this->sumOutstandingInvoicesByCurrency($vendorId);
    $defaults['collected_by_currency'] = $this->sumCollectedByCurrency($vendorId);

    return $defaults;
  }

  /**
   * Admin KPIs: accrued vs collected, invoice counts.
   *
   * @return array<string, mixed>
   */
  public function getAdminBillingOverview(): array {
    $empty = [
      'total_accrued_unbilled_cents' => 0,
      'total_collected_cents' => 0,
      'total_accrued_cents_aud_display' => '—',
      'total_collected_cents_aud_display' => '—',
      'invoices_issued' => 0,
      'invoices_paid' => 0,
      'invoices_partial' => 0,
      'invoices_overdue' => 0,
    ];
    if (!$this->tablesExist()) {
      return $empty;
    }

    $accrued = (int) $this->database->query(
      'SELECT COALESCE(SUM(amount_cents), 0) FROM {mel_vendor_pct_contribution} WHERE status = :st AND (billing_status = :u OR billing_status IS NULL)',
      [':st' => VendorMelPctContributionService::STATUS_ACCRUED, ':u' => self::BILLING_UNBILLED]
    )->fetchField();

    $collected = (int) $this->database->query(
      'SELECT COALESCE(SUM(paid_amount_cents), 0) FROM {mel_vendor_invoice}'
    )->fetchField();

    $issued = (int) $this->database->query(
      'SELECT COUNT(*) FROM {mel_vendor_invoice} WHERE status = :s',
      [':s' => self::INVOICE_ISSUED]
    )->fetchField();

    $paid = (int) $this->database->query(
      'SELECT COUNT(*) FROM {mel_vendor_invoice} WHERE status = :s',
      [':s' => self::INVOICE_PAID]
    )->fetchField();

    $partial = (int) $this->database->query(
      'SELECT COUNT(*) FROM {mel_vendor_invoice} WHERE status = :s',
      [':s' => self::INVOICE_PARTIAL]
    )->fetchField();

    $now = time();
    $overdue = (int) $this->database->query(
      'SELECT COUNT(*) FROM {mel_vendor_invoice}
       WHERE status IN (:s1, :s2)
       AND (total_amount_cents - paid_amount_cents) > 0
       AND due_date IS NOT NULL AND due_date < :now',
      [
        ':s1' => self::INVOICE_ISSUED,
        ':s2' => self::INVOICE_PARTIAL,
        ':now' => $now,
      ]
    )->fetchField();

    return [
      'total_accrued_unbilled_cents' => $accrued,
      'total_collected_cents' => $collected,
      'total_accrued_cents_aud_display' => '$' . number_format($accrued / 100, 2),
      'total_collected_cents_aud_display' => '$' . number_format($collected / 100, 2),
      'invoices_issued' => $issued,
      'invoices_paid' => $paid,
      'invoices_partial' => $partial,
      'invoices_overdue' => $overdue,
    ];
  }

  /**
   * Lists invoices for a vendor (newest first).
   *
   * @return array<int, array<string, mixed>>
   */
  public function listInvoicesForVendor(int $vendorId, int $limit = 100): array {
    if ($vendorId <= 0 || !$this->tablesExist()) {
      return [];
    }
    $rows = $this->database->select('mel_vendor_invoice', 'i')
      ->fields('i')
      ->condition('vendor_id', $vendorId)
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(fn (array $r): array => $this->normalizeInvoiceRow($r), $rows);
  }

  /**
   * Loads one invoice by ID, or NULL.
   *
   * @return array<string, mixed>|null
   */
  public function loadInvoice(int $invoiceId): ?array {
    if ($invoiceId <= 0 || !$this->tablesExist()) {
      return NULL;
    }
    $row = $this->database->select('mel_vendor_invoice', 'i')
      ->fields('i')
      ->condition('id', $invoiceId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    return $this->normalizeInvoiceRow($row);
  }

  /**
   * Line items for an invoice (contribution rows).
   *
   * @return array<int, array<string, mixed>>
   */
  public function getInvoiceContributionLines(int $invoiceId): array {
    if ($invoiceId <= 0 || !$this->tablesExist()) {
      return [];
    }
    return $this->database->select('mel_vendor_pct_contribution', 'm')
      ->fields('m')
      ->condition('invoice_id', $invoiceId)
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Generates invoices from unbilled accrued rows in a time window.
   *
   * @return array<int, array<string, mixed>>
   *   One entry per invoice created.
   */
  public function generateInvoicesForWindow(?int $vendorFilter, int $sinceTs, int $untilTs): array {
    if (!$this->tablesExist()) {
      return [];
    }

    $q = $this->database->select('mel_vendor_pct_contribution', 'm')
      ->fields('m')
      ->condition('status', VendorMelPctContributionService::STATUS_ACCRUED)
      ->condition('amount_cents', 0, '>')
      ->where('(m.billing_status = :u OR m.billing_status IS NULL)', [':u' => self::BILLING_UNBILLED])
      ->condition('created', $sinceTs, '>=')
      ->condition('created', $untilTs, '<=');

    if ($vendorFilter !== NULL && $vendorFilter > 0) {
      $q->condition('vendor_id', $vendorFilter);
    }

    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
    if (!$rows) {
      return [];
    }

    $groups = [];
    foreach ($rows as $row) {
      $vid = (int) $row['vendor_id'];
      $cur = strtolower((string) $row['currency_code']);
      $key = $vid . ':' . $cur;
      $groups[$key]['vendor_id'] = $vid;
      $groups[$key]['currency'] = $cur;
      $groups[$key]['ids'][] = (int) $row['id'];
      $groups[$key]['sum'] = ($groups[$key]['sum'] ?? 0) + (int) $row['amount_cents'];
    }

    $created = [];
    $now = time();
    foreach ($groups as $group) {
      if (($group['sum'] ?? 0) <= 0 || empty($group['ids'])) {
        continue;
      }

      $transaction = $this->database->startTransaction();
      try {
        $invoiceId = (int) $this->database->insert('mel_vendor_invoice')
          ->fields([
            'vendor_id' => $group['vendor_id'],
            'total_amount_cents' => $group['sum'],
            'currency_code' => $group['currency'],
            'status' => self::INVOICE_ISSUED,
            'created' => $now,
            'changed' => $now,
            'due_date' => NULL,
            'paid_amount_cents' => 0,
            'period_start' => $sinceTs,
            'period_end' => $untilTs,
          ])
          ->execute();

        $this->database->update('mel_vendor_pct_contribution')
          ->fields([
            'invoice_id' => $invoiceId,
            'billing_status' => self::BILLING_BILLED,
            'changed' => $now,
          ])
          ->condition('id', $group['ids'], 'IN')
          ->execute();

        $created[] = [
          'invoice_id' => $invoiceId,
          'vendor_id' => $group['vendor_id'],
          'currency_code' => $group['currency'],
          'total_amount_cents' => $group['sum'],
          'contribution_row_ids' => $group['ids'],
        ];
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        $this->logger()->error('MEL vendor invoice generation failed: @message', ['@message' => $e->getMessage()]);
        throw $e;
      }
    }

    return $created;
  }

  /**
   * Remaining balance in minor units.
   */
  public function getRemainingCents(array $invoice): int {
    $total = (int) ($invoice['total_amount_cents'] ?? 0);
    $paid = (int) ($invoice['paid_amount_cents'] ?? 0);
    return max(0, $total - $paid);
  }

  /**
   * Starts Commerce checkout for the remaining invoice balance (platform_donation).
   */
  public function createCheckoutForInvoice(int $userId, int $invoiceId): ?Url {
    $invoice = $this->loadInvoice($invoiceId);
    if (!$invoice) {
      return NULL;
    }
    $vendorId = $this->resolveVendorIdForUser($userId);
    if ($vendorId === NULL || $vendorId !== (int) $invoice['vendor_id']) {
      return NULL;
    }
    $status = (string) ($invoice['status'] ?? '');
    if ($status === self::INVOICE_PAID || $status === self::INVOICE_VOID) {
      return NULL;
    }
    $remaining = $this->getRemainingCents($invoice);
    if ($remaining <= 0) {
      return NULL;
    }

    try {
      $order = $this->platformDonationService->createInvoiceSettlementOrder(
        $userId,
        $invoiceId,
        $remaining,
        strtoupper((string) ($invoice['currency_code'] ?? 'AUD')),
      );
    }
    catch (\Throwable $e) {
      $this->logger()->error('MEL invoice checkout: failed to create order for invoice @iid: @msg', [
        '@iid' => (string) $invoiceId,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }

    return Url::fromRoute('commerce_checkout.form', ['commerce_order' => $order->id()]);
  }

  /**
   * Applies a paid platform_donation order to an invoice (idempotent per order id).
   */
  public function applyPaymentFromOrder(OrderInterface $order): void {
    if (!$this->tablesExist() || $order->bundle() !== 'platform_donation') {
      return;
    }

    $invoiceId = $this->extractInvoiceIdFromOrder($order);
    if ($invoiceId === NULL || $invoiceId <= 0) {
      return;
    }

    $price = $order->getTotalPrice();
    if (!$price) {
      $this->logger()->warning('MEL invoice payment: order @oid has no total price.', ['@oid' => $order->id()]);
      return;
    }

    $paidCents = (int) round((float) $price->getNumber() * 100);
    if ($paidCents <= 0) {
      return;
    }

    $currency = strtolower($price->getCurrencyCode());
    $orderId = (int) $order->id();

    try {
      $this->database->insert('mel_vendor_invoice_payment')
        ->fields([
          'invoice_id' => $invoiceId,
          'commerce_order_id' => $orderId,
          'amount_cents' => $paidCents,
          'currency_code' => $currency,
          'created' => time(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      $this->logger()->notice('MEL invoice payment: duplicate order @oid ignored (idempotent).', ['@oid' => (string) $orderId]);
      return;
    }

    $invoice = $this->loadInvoice($invoiceId);
    if (!$invoice) {
      $this->logger()->error('MEL invoice payment: invoice @iid missing after log insert.', ['@iid' => (string) $invoiceId]);
      return;
    }

    if (strtolower((string) $invoice['currency_code']) !== $currency) {
      $this->logger()->error('MEL invoice payment: currency mismatch for invoice @iid order @oid.', [
        '@iid' => (string) $invoiceId,
        '@oid' => (string) $orderId,
      ]);
      return;
    }

    $uid = (int) $order->getCustomerId();
    $vendorId = $this->resolveVendorIdForUser($uid);
    if ($vendorId === NULL || $vendorId !== (int) $invoice['vendor_id']) {
      $this->logger()->error('MEL invoice payment: vendor mismatch for invoice @iid order @oid.', [
        '@iid' => (string) $invoiceId,
        '@oid' => (string) $orderId,
      ]);
      return;
    }

    $total = (int) $invoice['total_amount_cents'];
    $prevPaid = (int) $invoice['paid_amount_cents'];
    $newPaid = min($total, $prevPaid + $paidCents);
    $now = time();

    $newStatus = self::INVOICE_PARTIAL;
    if ($newPaid >= $total) {
      $newStatus = self::INVOICE_PAID;
      $newPaid = $total;
    }
    elseif ($newPaid <= 0) {
      $newStatus = (string) ($invoice['status'] ?? self::INVOICE_ISSUED);
    }

    $this->database->update('mel_vendor_invoice')
      ->fields([
        'paid_amount_cents' => $newPaid,
        'status' => $newStatus,
        'changed' => $now,
      ])
      ->condition('id', $invoiceId)
      ->execute();

    if ($newStatus === self::INVOICE_PAID) {
      $this->database->update('mel_vendor_pct_contribution')
        ->fields([
          'billing_status' => self::BILLING_PAID,
          'changed' => $now,
        ])
        ->condition('invoice_id', $invoiceId)
        ->execute();
    }

    $this->logger()->info('MEL invoice @iid settled @cents cents from order @oid (status @s).', [
      '@iid' => (string) $invoiceId,
      '@cents' => (string) $paidCents,
      '@oid' => (string) $orderId,
      '@s' => $newStatus,
    ]);
  }

  private function extractInvoiceIdFromOrder(OrderInterface $order): ?int {
    foreach ($order->getItems() as $item) {
      if (!$item->hasField('field_attendee_data') || $item->get('field_attendee_data')->isEmpty()) {
        continue;
      }
      $raw = (string) $item->get('field_attendee_data')->value;
      $data = json_decode($raw, TRUE);
      if (!is_array($data)) {
        continue;
      }
      if (!empty($data['mel_vendor_invoice_id'])) {
        return (int) $data['mel_vendor_invoice_id'];
      }
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function normalizeInvoiceRow(array $row): array {
    $row['id'] = (int) ($row['id'] ?? 0);
    $row['vendor_id'] = (int) ($row['vendor_id'] ?? 0);
    $row['total_amount_cents'] = (int) ($row['total_amount_cents'] ?? 0);
    $row['paid_amount_cents'] = (int) ($row['paid_amount_cents'] ?? 0);
    $row['remaining_cents'] = max(0, $row['total_amount_cents'] - $row['paid_amount_cents']);
    return $row;
  }

  private function tablesExist(): bool {
    $s = $this->database->schema();
    return $s->tableExists('mel_vendor_invoice')
      && $s->tableExists('mel_vendor_invoice_payment')
      && $s->tableExists('mel_vendor_pct_contribution');
  }

  /**
   * @return array<string, int>
   */
  private function sumUnbilledAccruedByCurrency(int $vendorId): array {
    $q = $this->database->query(
      'SELECT m.currency_code, COALESCE(SUM(m.amount_cents), 0) AS total FROM {mel_vendor_pct_contribution} m
       WHERE m.vendor_id = :v
       AND m.status = :st
       AND m.amount_cents > 0
       AND (m.billing_status = :u OR m.billing_status IS NULL)
       GROUP BY m.currency_code',
      [
        ':v' => $vendorId,
        ':st' => VendorMelPctContributionService::STATUS_ACCRUED,
        ':u' => self::BILLING_UNBILLED,
      ]
    );
    $out = [];
    foreach ($q as $row) {
      $out[strtoupper((string) $row->currency_code)] = (int) $row->total;
    }
    return $out;
  }

  /**
   * @return array<string, int>
   */
  private function sumOutstandingInvoicesByCurrency(int $vendorId): array {
    $result = $this->database->select('mel_vendor_invoice', 'i');
    $result->addExpression('SUM(i.total_amount_cents - i.paid_amount_cents)', 'outstanding');
    $result->fields('i', ['currency_code']);
    $result->condition('vendor_id', $vendorId);
    $result->condition('status', [self::INVOICE_ISSUED, self::INVOICE_PARTIAL], 'IN');
    $result->groupBy('i.currency_code');
    $out = [];
    foreach ($result->execute() as $row) {
      $out[strtoupper((string) $row->currency_code)] = (int) $row->outstanding;
    }
    return $out;
  }

  /**
   * @return array<string, int>
   */
  private function sumCollectedByCurrency(int $vendorId): array {
    $result = $this->database->select('mel_vendor_invoice', 'i');
    $result->addExpression('SUM(i.paid_amount_cents)', 'collected');
    $result->fields('i', ['currency_code']);
    $result->condition('vendor_id', $vendorId);
    $result->condition('paid_amount_cents', 0, '>');
    $result->groupBy('i.currency_code');
    $out = [];
    foreach ($result->execute() as $row) {
      $out[strtoupper((string) $row->currency_code)] = (int) $row->collected;
    }
    return $out;
  }

}
