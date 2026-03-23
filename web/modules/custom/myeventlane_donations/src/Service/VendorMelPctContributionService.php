<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_commerce\Service\StripeConnectPaymentService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Accrues optional vendor percentage pledges toward MyEventLane (paid events).
 *
 * Architecture (Phase 2):
 * - Accrual-only liability: no checkout line items, no Stripe Connect deduction.
 * - Contribution base = net ticket revenue for the event on the order, using the
 *   same ticket line rules as StripeConnectPaymentService (excludes attendee
 *   donations and boost), minus ticket refunds attributed the same way as
 *   VendorEventOverview / TicketSalesService refund attribution.
 * - One row per (order_id, event_id), idempotent upsert on payment and refund sync.
 *
 * Phase 3: Rows linked to an invoice (billing_status billed/paid) are frozen:
 * accrual amounts are not overwritten (post-invoice refunds need manual or future
 * adjustment workflow).
 *
 * @see \Drupal\myeventlane_commerce\Service\StripeConnectPaymentService::calculateTicketRevenueCentsForEvent()
 * @see \Drupal\myeventlane_vendor\Service\TicketSalesService::getRefundAttributionCents()
 */
final class VendorMelPctContributionService {

  use StringTranslationTrait;

  public const STATUS_ACCRUED = 'accrued';

  public const STATUS_VOID = 'void';

  /**
   * Billing lifecycle on the contribution row (Phase 3).
   *
   * Must match values used in VendorContributionInvoiceService; avoid circular
   * imports by keeping string literals here.
   */
  private const BILLING_UNBILLED = 'unbilled';

  private const BILLING_BILLED = 'billed';

  private const BILLING_PAID = 'paid';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly StripeConnectPaymentService $stripeConnectPaymentService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_donations');
  }

  /**
   * Records or updates contribution rows when a ticket order is paid.
   *
   * Idempotent: merge on (order_id, event_id).
   */
  public function upsertFromPaidOrder(OrderInterface $order): void {
    if (!$this->database->schema()->tableExists('mel_vendor_pct_contribution')) {
      return;
    }

    if ($order->bundle() !== 'default') {
      return;
    }

    $eventIds = $this->collectEventIdsFromOrder($order);
    foreach ($eventIds as $eventId) {
      $event = $this->entityTypeManager->getStorage('node')->load($eventId);
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        continue;
      }

      if (!$this->eventQualifiesForPercentPledge($event)) {
        continue;
      }

      $pct = $this->getPledgePercent($event);
      if ($pct === NULL || $pct <= 0) {
        continue;
      }

      $vendorId = $this->extractVendorIdFromEvent($event);
      if ($vendorId === NULL || $vendorId <= 0) {
        $this->logger()->warning('MEL pct contribution: event @nid has no vendor reference; skipping.', ['@nid' => (string) $eventId]);
        continue;
      }

      $currency = $this->orderCurrencyCode($order);
      $grossCents = $this->stripeConnectPaymentService->calculateTicketRevenueCentsForEvent($order, $eventId);
      $refundedTicketCents = $this->getAttributedTicketRefundCentsForOrderEvent((int) $order->id(), $eventId, strtolower($currency), $grossCents);
      $netCents = max(0, $grossCents - $refundedTicketCents);
      $amountCents = $this->computeContributionCents($netCents, $pct);

      $status = $netCents <= 0 && $amountCents <= 0 ? self::STATUS_VOID : self::STATUS_ACCRUED;

      $now = time();
      $this->persistRow(
        (int) $order->id(),
        $eventId,
        $vendorId,
        $pct,
        $grossCents,
        $netCents,
        $amountCents,
        strtolower($currency),
        $status,
        $now
      );
    }
  }

  /**
   * Inserts or updates one ledger row (idempotent on order_id + event_id).
   */
  private function persistRow(
    int $orderId,
    int $eventId,
    int $vendorId,
    float $pct,
    int $grossBaseCents,
    int $netTicketCents,
    int $amountCents,
    string $currencyLower,
    string $status,
    int $timestamp,
  ): void {
    $exists = (int) $this->database->select('mel_vendor_pct_contribution', 'm')
      ->fields('m', ['id'])
      ->condition('order_id', $orderId)
      ->condition('event_id', $eventId)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($exists > 0 && $this->database->schema()->fieldExists('mel_vendor_pct_contribution', 'billing_status')) {
      $billing = $this->database->select('mel_vendor_pct_contribution', 'm')
        ->fields('m', ['billing_status'])
        ->condition('order_id', $orderId)
        ->condition('event_id', $eventId)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $billing = is_string($billing) ? $billing : self::BILLING_UNBILLED;
      if (in_array($billing, [self::BILLING_BILLED, self::BILLING_PAID], TRUE)) {
        $this->logger()->notice('MEL pct contribution: skip accrual update for invoiced row (order @o, event @e, billing @b).', [
          '@o' => (string) $orderId,
          '@e' => (string) $eventId,
          '@b' => $billing,
        ]);
        return;
      }
    }

    $common = [
      'vendor_id' => $vendorId,
      'pct_rate' => $pct,
      'gross_base_cents' => $grossBaseCents,
      'net_ticket_cents' => $netTicketCents,
      'amount_cents' => $amountCents,
      'currency_code' => $currencyLower,
      'status' => $status,
      'changed' => $timestamp,
    ];

    if ($exists > 0) {
      $this->database->update('mel_vendor_pct_contribution')
        ->fields($common)
        ->condition('order_id', $orderId)
        ->condition('event_id', $eventId)
        ->execute();
      return;
    }

    $insert = $common + [
      'order_id' => $orderId,
      'event_id' => $eventId,
      'created' => $timestamp,
    ];
    if ($this->database->schema()->fieldExists('mel_vendor_pct_contribution', 'invoice_id')) {
      $insert['invoice_id'] = NULL;
      $insert['billing_status'] = self::BILLING_UNBILLED;
    }

    try {
      $this->database->insert('mel_vendor_pct_contribution')
        ->fields($insert)
        ->execute();
    }
    catch (IntegrityConstraintViolationException $e) {
      $this->database->update('mel_vendor_pct_contribution')
        ->fields($common)
        ->condition('order_id', $orderId)
        ->condition('event_id', $eventId)
        ->execute();
    }
  }

  /**
   * Re-syncs contribution for an order/event after a refund completes.
   */
  public function recalculateForOrderAndEvent(int $orderId, int $eventId): void {
    if (!$this->database->schema()->tableExists('mel_vendor_pct_contribution')) {
      return;
    }

    if ($orderId <= 0 || $eventId <= 0) {
      return;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    if (!$order instanceof OrderInterface) {
      return;
    }

    $this->upsertFromPaidOrder($order);
  }

  /**
   * Summary for vendor event overview: accrued total and pledge rate.
   *
   * @return array{
   *   pledged_percent: string,
   *   accrued_formatted: string,
   *   status_label: string,
   *   detail: string,
   * }
   */
  public function getEventVendorSummary(NodeInterface $event): array {
    $defaults = [
      'pledged_percent' => '',
      'accrued_formatted' => '$0.00',
      'status_label' => '',
      'detail' => '',
    ];

    if (!$this->database->schema()->tableExists('mel_vendor_pct_contribution')) {
      return $defaults;
    }

    if (!$this->eventQualifiesForPercentPledge($event)) {
      return $defaults;
    }

    $pct = $this->getPledgePercent($event);
    if ($pct === NULL) {
      return $defaults;
    }

    $eventId = (int) $event->id();
    $sum = (int) $this->database->query(
      'SELECT COALESCE(SUM(amount_cents), 0) FROM {mel_vendor_pct_contribution} WHERE event_id = :eid AND status = :st',
      [':eid' => $eventId, ':st' => self::STATUS_ACCRUED]
    )->fetchField();

    $currency = 'AUD';
    $code = $this->database->select('mel_vendor_pct_contribution', 'm')
      ->fields('m', ['currency_code'])
      ->condition('event_id', $eventId)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (is_string($code) && $code !== '') {
      $currency = strtoupper($code);
    }

    $formatted = $currency === 'AUD'
      ? '$' . number_format($sum / 100, 2)
      : number_format($sum / 100, 2) . ' ' . $currency;

    return [
      'pledged_percent' => number_format($pct, 2, '.', ''),
      'accrued_formatted' => $formatted,
      'status_label' => (string) t('Accrued'),
      'detail' => (string) t('Based on eligible paid ticket sales for this event, after ticket refunds where applicable. This is optional and separate from attendee donations.'),
    ];
  }

  /**
   * Admin report rows: published events with percentage pledge and accrued totals.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAdminPledgeReportRows(): array {
    if (!$this->database->schema()->tableExists('mel_vendor_pct_contribution')) {
      return [];
    }

    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_mel_sup_mode', 'percent')
      ->sort('title', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $rows = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $nid = (int) $node->id();
      $pctDisplay = !$node->get('field_mel_sup_pct')->isEmpty()
        ? (string) $node->get('field_mel_sup_pct')->value
        : '';

      $accrued = (int) $this->database->query(
        'SELECT COALESCE(SUM(amount_cents), 0) FROM {mel_vendor_pct_contribution} WHERE event_id = :eid AND status = :st',
        [':eid' => $nid, ':st' => self::STATUS_ACCRUED]
      )->fetchField();

      $orderCount = (int) $this->database->query(
        'SELECT COUNT(DISTINCT order_id) FROM {mel_vendor_pct_contribution} WHERE event_id = :eid',
        [':eid' => $nid]
      )->fetchField();

      $rows[] = [
        'event_id' => $nid,
        'title' => $node->label(),
        'pledge_rate' => $pctDisplay !== '' ? $pctDisplay . '%' : '—',
        'accrued' => '$' . number_format($accrued / 100, 2),
        'orders' => $orderCount,
      ];
    }

    return $rows;
  }

  /**
   * @return float|null
   *   Percent as stored (e.g. 5 for 5%).
   */
  private function getPledgePercent(NodeInterface $event): ?float {
    if ($event->get('field_mel_sup_pct')->isEmpty()) {
      return NULL;
    }
    $raw = (string) $event->get('field_mel_sup_pct')->value;
    if (!is_numeric($raw)) {
      return NULL;
    }
    $v = (float) $raw;
    return $v > 0 ? $v : NULL;
  }

  private function eventQualifiesForPercentPledge(NodeInterface $event): bool {
    if (!$event->hasField('field_mel_sup_mode') || !$event->hasField('field_event_type')) {
      return FALSE;
    }
    if ((string) ($event->get('field_mel_sup_mode')->value ?? '') !== 'percent') {
      return FALSE;
    }
    $eventType = (string) ($event->get('field_event_type')->value ?? '');
    return in_array($eventType, ['paid', 'both'], TRUE);
  }

  /**
   * @return array<int>
   */
  private function collectEventIdsFromOrder(OrderInterface $order): array {
    $ids = [];
    foreach ($order->getItems() as $item) {
      if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
        continue;
      }
      $eid = (int) $item->get('field_target_event')->target_id;
      if ($eid > 0) {
        $ids[$eid] = $eid;
      }
    }
    return array_values($ids);
  }

  private function orderCurrencyCode(OrderInterface $order): string {
    $p = $order->getTotalPrice();
    if ($p) {
      return strtoupper($p->getCurrencyCode());
    }
    return 'AUD';
  }

  private function computeContributionCents(int $netTicketCents, float $pledgePercent): int {
    if ($netTicketCents <= 0 || $pledgePercent <= 0) {
      return 0;
    }
    return (int) round(($pledgePercent / 100.0) * $netTicketCents);
  }

  /**
   * Ticket refund cents attributed to tickets for one order+event.
   *
   * Mirrors the ticket split logic in
   * TicketSalesService::getRefundAttributionCents() for a single order.
   */
  private function getAttributedTicketRefundCentsForOrderEvent(int $orderId, int $eventId, string $currencyLower, int $ticketSubtotalCents): int {
    if (!$this->database->schema()->tableExists('myeventlane_refund_log')) {
      return 0;
    }

    $query = $this->database->select('myeventlane_refund_log', 'r');
    $query->fields('r', ['id', 'order_id', 'refund_scope', 'amount_cents', 'donation_refunded']);
    $query->condition('r.event_id', $eventId);
    $query->condition('r.order_id', $orderId);
    $query->condition('r.status', 'completed');
    $query->condition('r.amount_cents', 0, '>');
    $query->where('LOWER(r.currency) = :currency', [':currency' => $currencyLower]);
    $query->orderBy('r.id', 'ASC');

    $alreadyAttributed = 0;

    foreach ($query->execute() as $row) {
      $scope = (string) $row->refund_scope;
      $amountCents = (int) $row->amount_cents;
      if ($amountCents <= 0) {
        continue;
      }

      if ($scope === 'donation_only') {
        continue;
      }

      $ticketAttributed = $amountCents;
      if ($scope === 'tickets_and_donation' || (int) $row->donation_refunded === 1) {
        $remainingCap = max(0, $ticketSubtotalCents - $alreadyAttributed);
        $ticketAttributed = min($amountCents, $remainingCap);
      }

      $alreadyAttributed += $ticketAttributed;
    }

    return max(0, $alreadyAttributed);
  }

  private function extractVendorIdFromEvent(NodeInterface $event): ?int {
    foreach (['field_event_vendor', 'field_vendor', 'field_vendor_account'] as $field) {
      if ($event->hasField($field) && !$event->get($field)->isEmpty()) {
        $id = (int) $event->get($field)->target_id;
        if ($id > 0) {
          return $id;
        }
      }
    }
    return NULL;
  }

}
