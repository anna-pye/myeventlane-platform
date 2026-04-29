<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_analytics\Service\OrderItemClassifier;
use Drupal\myeventlane_core\PlatformFeeDefaults;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Ticket sales data provider for vendor console.
 *
 * Queries real Commerce order data for accurate sales metrics.
 * Excludes Boost and donation order items (platform revenue only).
 */
final class TicketSalesService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OrderItemClassifier $orderItemClassifier,
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly VendorSubscriptionService $subscriptionService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
  ) {}

  /**
   * Returns the logger for vendor ticket sales.
   */
  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_vendor');
  }

  /**
   * @return array<string, mixed>
   */
  private function emptySalesSummary(): array {
    return [
      'gross' => '$0.00',
      'refunded' => '$0.00',
      'donation_refunded' => '$0.00',
      'net' => '$0.00',
      'fees' => '$0.00',
      'gross_raw' => 0.0,
      'refunded_raw' => 0.0,
      'donation_refunded_raw' => 0.0,
      'net_raw' => 0.0,
      'gross_cents' => 0,
      'refunded_ticket_cents' => 0,
      'donation_refunded_cents' => 0,
      'net_cents' => 0,
      'refund_rate_percent' => 0.0,
      'currency' => 'AUD',
      'tickets_sold' => 0,
      'tickets_available' => 0,
      'conversion' => 0.0,
      'orders_count' => 0,
    ];
  }

  /**
   * Returns a sales summary for an event.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   * Excludes: Boost and donation order items.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Sales summary with keys: gross, net, fees (formatted strings),
   *   gross_raw, net_raw (floats), currency, tickets_sold (int),
   *   tickets_available (int|string), conversion (float), orders_count (int).
   */
  public function getSalesSummary(NodeInterface $event): array {
    return $this->getSalesSummaryForEventId((int) $event->id());
  }

  /**
   * Same as getSalesSummary() without requiring a loaded node (vendor dashboard DTO path).
   */
  public function getSalesSummaryForEventId(int $eventId): array {
    if ($eventId <= 0) {
      return $this->emptySalesSummary();
    }
    $grossCents = 0;
    $ticketsSold = 0;
    $currency = 'AUD';
    $completedOrderCache = [];

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          if (!array_key_exists((int) $order_id, $completedOrderCache)) {
            $order = $orderStorage->load($order_id);
            $completedOrderCache[(int) $order_id] = (bool) ($order && $order->getState()->getId() === 'completed');
          }

          if ($completedOrderCache[(int) $order_id]) {
            $totalPrice = $item->getTotalPrice();
            if ($totalPrice) {
              $grossCents += $this->priceToCents($totalPrice);
              $currency = strtoupper($totalPrice->getCurrencyCode());
            }
            $ticketsSold += (int) $item->getQuantity();
          }
        }
        catch (\Throwable $e) {
          $this->logger()->error('Failed loading order while computing event sales summary for event @event_id: @message', [
            '@event_id' => $eventId,
            '@message' => $e->getMessage(),
          ]);
          continue;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger()->error('Failed loading order items while computing event sales summary for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
    }

    $refundAttribution = $this->getRefundAttributionCents($eventId, $currency);
    $refundedTicketCents = (int) ($refundAttribution['ticket_cents'] ?? 0);
    $refundedDonationCents = (int) ($refundAttribution['donation_cents'] ?? 0);
    $netCents = max(0, $grossCents - $refundedTicketCents);

    $ticketsAvailable = 0;
    $eventNode = $this->entityTypeManager->getStorage('node')->load($eventId);
    if ($eventNode instanceof NodeInterface && $eventNode->bundle() === 'event') {
      $ticketsAvailable = $this->getTicketsAvailable($eventNode);
    }
    $gross = $grossCents / 100;
    $refunded = $refundedTicketCents / 100;
    $donationRefunded = $refundedDonationCents / 100;
    $net = $netCents / 100;
    $refundRatePercent = $grossCents > 0
      ? round(($refundedTicketCents / $grossCents) * 100, 1)
      : 0.0;

    $conversion = 0;
    if (is_numeric($ticketsAvailable) && (int) $ticketsAvailable > 0) {
      $conversion = $ticketsSold / (int) $ticketsAvailable;
    }

    $ordersCount = 0;
    foreach ($completedOrderCache as $completed) {
      if ($completed) {
        $ordersCount++;
      }
    }

    return [
      'gross' => $this->formatMoney($grossCents, $currency),
      'refunded' => $this->formatMoney($refundedTicketCents, $currency),
      'donation_refunded' => $this->formatMoney($refundedDonationCents, $currency),
      'net' => $this->formatMoney($netCents, $currency),
      'fees' => '$0.00',
      'gross_raw' => $gross,
      'refunded_raw' => $refunded,
      'donation_refunded_raw' => $donationRefunded,
      'net_raw' => $net,
      'gross_cents' => $grossCents,
      'refunded_ticket_cents' => $refundedTicketCents,
      'donation_refunded_cents' => $refundedDonationCents,
      'net_cents' => $netCents,
      'refund_rate_percent' => $refundRatePercent,
      'currency' => $currency,
      'tickets_sold' => $ticketsSold,
      'tickets_available' => $ticketsAvailable,
      'conversion' => $conversion,
      'orders_count' => $ordersCount,
    ];
  }

  /**
   * Returns attributed ticket refunds for an event in minor units.
   */
  private function getRefundedTicketCents(int $eventId, string $currency): int {
    $attribution = $this->getRefundAttributionCents($eventId, $currency);
    return (int) ($attribution['ticket_cents'] ?? 0);
  }

  /**
   * Returns attributed ticket and donation refunds for an event in minor units.
   *
   * @return array{ticket_cents:int, donation_cents:int}
   *   Attributed refund totals.
   */
  private function getRefundAttributionCents(int $eventId, string $currency): array {
    if (!$this->database->schema()->tableExists('myeventlane_refund_log')) {
      return ['ticket_cents' => 0, 'donation_cents' => 0];
    }

    try {
      $currency = strtoupper($currency);
      $currencyLower = strtolower($currency);
      $ticketSubtotalsByOrder = $this->getTicketSubtotalsByOrderForEvent($eventId, $currency);
      $attributedTicketsByOrder = [];
      $ticketTotal = 0;
      $donationTotal = 0;

      $query = $this->database->select('myeventlane_refund_log', 'r');
      $query->join('commerce_order', 'o', 'o.order_id = r.order_id');
      $query->fields('r', ['id', 'order_id', 'refund_scope', 'amount_cents', 'donation_refunded']);
      $query->condition('r.event_id', $eventId);
      $query->condition('r.status', 'completed');
      $query->condition('r.amount_cents', 0, '>');
      $query->condition('o.state', 'completed');
      $query->where('LOWER(r.currency) = :currency', [':currency' => $currencyLower]);
      $query->orderBy('r.id', 'ASC');

      foreach ($query->execute() as $row) {
        $orderId = (int) $row->order_id;
        $scope = (string) $row->refund_scope;
        $amountCents = (int) $row->amount_cents;
        if ($amountCents <= 0) {
          continue;
        }

        if ($scope === 'donation_only') {
          $donationTotal += $amountCents;
          continue;
        }

        $ticketAttributed = $amountCents;
        if ($scope === 'tickets_and_donation' || (int) $row->donation_refunded === 1) {
          $ticketSubtotalCents = (int) ($ticketSubtotalsByOrder[$orderId] ?? 0);
          $alreadyAttributed = (int) ($attributedTicketsByOrder[$orderId] ?? 0);
          $remainingCap = max(0, $ticketSubtotalCents - $alreadyAttributed);
          $ticketAttributed = min($amountCents, $remainingCap);
          $attributedTicketsByOrder[$orderId] = $alreadyAttributed + $ticketAttributed;
          $donationTotal += max(0, $amountCents - $ticketAttributed);
        }

        $ticketTotal += $ticketAttributed;
      }

      return [
        'ticket_cents' => max(0, $ticketTotal),
        'donation_cents' => max(0, $donationTotal),
      ];
    }
    catch (\Throwable $e) {
      $this->logger()->error('Failed refund attribution for event @event_id: @message', [
        '@event_id' => $eventId,
        '@message' => $e->getMessage(),
      ]);
      return ['ticket_cents' => 0, 'donation_cents' => 0];
    }
  }

  /**
   * Returns per-order ticket subtotals for one event in one currency.
   *
   * @return array<int, int>
   *   Map: order_id => ticket_subtotal_cents.
   */
  private function getTicketSubtotalsByOrderForEvent(int $eventId, string $currency): array {
    $map = [];
    $query = $this->database->select('commerce_order_item', 'oi');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'lnk', 'lnk.entity_id = oi.order_item_id');
    $query->addField('oi', 'order_id', 'order_id');
    $query->addExpression('COALESCE(SUM(ROUND(oi.unit_price__number * oi.quantity * 100)), 0)', 'ticket_subtotal_cents');
    $query->condition('o.state', 'completed');
    $query->condition('lnk.field_target_event_target_id', $eventId);
    $query->condition('oi.unit_price__number', '0', '>');
    $query->condition('oi.unit_price__currency_code', $currency);
    $query->condition('oi.type', $this->orderItemClassifier->getExcludedTypes(), 'NOT IN');
    $query->groupBy('oi.order_id');

    foreach ($query->execute() as $row) {
      $map[(int) $row->order_id] = (int) $row->ticket_subtotal_cents;
    }

    return $map;
  }

  /**
   * Converts a Price object to minor units without float math.
   */
  private function priceToCents(Price $price): int {
    $number = $price->getNumber();
    $negative = str_starts_with($number, '-');
    $normalized = ltrim($number, '+-');
    [$major, $minor] = array_pad(explode('.', $normalized, 2), 2, '0');
    $minor = substr(str_pad($minor, 2, '0'), 0, 2);
    $cents = ((int) $major * 100) + (int) $minor;
    return $negative ? -$cents : $cents;
  }

  /**
   * Formats integer cents into currency display.
   */
  private function formatMoney(int $amountCents, string $currency): string {
    $currency = strtoupper($currency);
    $amount = $amountCents / 100;
    if ($currency === 'AUD') {
      return '$' . number_format($amount, 2);
    }
    return number_format($amount, 2) . ' ' . $currency;
  }

  /**
   * Returns ticket type breakdown for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of ticket types with label, price, sold, available, revenue.
   */
  public function getTicketBreakdown(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $breakdown = [];

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return [];
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product) {
      return [];
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $variationStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      $variationAggregates = [];

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        $purchasedEntity = $item->getPurchasedEntity();
        if (!$purchasedEntity) {
          continue;
        }

        $variationId = $purchasedEntity->id();

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $orderStorage->load($order_id);
          if (!$order || $order->getState()->getId() !== 'completed') {
            continue;
          }
        }
        catch (\Exception) {
          continue;
        }

        if (!isset($variationAggregates[$variationId])) {
          $variation = $variationStorage->load($variationId);
          if (!$variation) {
            continue;
          }

          if ($variation->bundle() === 'boost_duration') {
            continue;
          }

          $variationProduct = $variation->getProduct();
          if (!$variationProduct || $variationProduct->id() !== $product->id()) {
            continue;
          }

          $variationTitle = '';
          if ($variation->hasField('title') && !$variation->get('title')->isEmpty()) {
            $variationTitle = $variation->get('title')->value;
          }
          if (empty($variationTitle)) {
            $variationTitle = $variation->label();
          }

          $price = $variation->getPrice();
          $priceNumber = $price ? (float) $price->getNumber() : 0.0;

          $stock = 'Unlimited';
          if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
            $stock = (int) $variation->get('field_stock')->value;
          }

          $variationAggregates[$variationId] = [
            'variation_id' => $variationId,
            'title' => $variationTitle,
            'price' => $priceNumber,
            'stock' => $stock,
            'sold' => 0,
            'revenue' => 0.0,
          ];
        }

        $variationAggregates[$variationId]['sold'] += (int) $item->getQuantity();
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $variationAggregates[$variationId]['revenue'] += (float) $totalPrice->getNumber();
        }
      }

      foreach ($variationAggregates as $data) {
        $available = is_int($data['stock']) ? max(0, $data['stock'] - $data['sold']) : $data['stock'];

        $breakdown[] = [
          'label' => $data['title'],
          'price' => '$' . number_format($data['price'], 2),
          'sold' => $data['sold'],
          'available' => $available,
          'revenue' => '$' . number_format($data['revenue'], 2),
          'revenue_raw' => $data['revenue'],
        ];
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    return $breakdown;
  }

  /**
   * Returns daily sales series for charts (last 14 days).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of daily sales data with date, amount, tickets.
   */
  public function getDailySalesSeries(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $series = [];

    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $date = date('Y-m-d', strtotime("-{$i} days"));
      $days[$date] = ['amount' => 0.0, 'tickets' => 0];
    }

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventId,
      ]);

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $completedTime = $order->getCompletedTime() ?? $order->getChangedTime();
            $date = date('Y-m-d', (int) $completedTime);

            if (isset($days[$date])) {
              $totalPrice = $item->getTotalPrice();
              if ($totalPrice) {
                $days[$date]['amount'] += (float) $totalPrice->getNumber();
              }
              $days[$date]['tickets'] += (int) $item->getQuantity();
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    foreach ($days as $date => $data) {
      $series[] = [
        'date' => date('M j', strtotime($date)),
        'amount' => $data['amount'],
        'tickets' => $data['tickets'],
      ];
    }

    return $series;
  }

  /**
   * Gets total available tickets for an event.
   */
  private function getTicketsAvailable(NodeInterface $event): int|string {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return 0;
    }

    $product = $event->get('field_product_target')->entity;
    if (!$product) {
      return 0;
    }

    try {
      $variations = $product->getVariations();
      $total = 0;
      $hasUnlimited = FALSE;

      foreach ($variations as $variation) {
        if ($variation->bundle() === 'boost_duration') {
          continue;
        }

        if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
          $total += (int) $variation->get('field_stock')->value;
        }
        else {
          $hasUnlimited = TRUE;
        }
      }

      return $hasUnlimited ? 'Unlimited' : $total;
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets total revenue for a vendor (published events authored by this user).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Revenue summary with gross, net, fees, gross_raw, tickets.
   */
  public function getVendorRevenue(int $userId): array {
    if ($userId <= 0) {
      return $this->emptyVendorRevenueSummary();
    }

    return $this->buildVendorRevenueFromPublishedEventIds($this->getAuthorPublishedEventNids($userId));
  }

  /**
   * Revenue across published events the user manages (author or vendor team).
   *
   * Matches {@see RsvpStatsService::getManagedPublishedEventsRsvpCount()} scope.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Revenue summary with gross, net, fees, gross_raw, tickets.
   */
  public function getManagedVendorRevenue(int $userId): array {
    if ($userId <= 0) {
      return $this->emptyVendorRevenueSummary();
    }

    return $this->buildVendorRevenueFromPublishedEventIds($this->getManagedPublishedEventNids($userId));
  }

  /**
   * Published event node IDs the user manages (author or vendor team).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return list<int>
   *   Published event node IDs.
   */
  public function getManagedPublishedEventNidsForUser(int $userId): array {
    if ($userId <= 0) {
      return [];
    }
    return $this->getManagedPublishedEventNids($userId);
  }

  /**
   * Revenue summary for specific published events (same rules as managed vendor revenue).
   *
   * @param list<int> $eventIds
   *   Published event node IDs.
   *
   * @return array<string, float|int|string>
   *   Revenue summary with gross, net, fees, gross_raw, tickets.
   */
  public function getVendorRevenueSummaryForPublishedEventIds(array $eventIds): array {
    return $this->buildVendorRevenueFromPublishedEventIds($eventIds);
  }

  /**
   * @return array<string, float|int|string>
   */
  private function emptyVendorRevenueSummary(): array {
    return [
      'gross' => '$0.00',
      'net' => '$0.00',
      'fees' => '$0.00',
      'gross_raw' => 0.0,
      'tickets' => 0,
    ];
  }

  /**
   * Published event nids where the user is the node author.
   *
   * @return list<int>
   */
  private function getAuthorPublishedEventNids(int $userId): array {
    try {
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();
      if (empty($ids)) {
        return [];
      }
      return array_map('intval', array_values($ids));
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Published events authored by the user or tied to their vendor membership.
   *
   * @return list<int>
   */
  private function getManagedPublishedEventNids(int $uid): array {
    try {
      $vendorIds = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('status', 1);
      $or = $query->orConditionGroup();
      $or->condition('uid', $uid);
      if ($vendorIds !== []) {
        $or->condition('field_event_vendor', $vendorIds, 'IN');
      }
      $query->condition($or);
      $ids = $query->execute();
      if (empty($ids)) {
        return [];
      }
      return array_map('intval', array_values($ids));
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * @param list<int> $eventIds
   *
   * @return array<string, float|int|string>
   */
  private function buildVendorRevenueFromPublishedEventIds(array $eventIds): array {
    if ($eventIds === []) {
      return $this->emptyVendorRevenueSummary();
    }

    $totalGross = 0.0;
    $totalTickets = 0;

    try {
      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => $eventIds,
      ]);

      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $totalPrice = $item->getTotalPrice();
            if ($totalPrice) {
              $totalGross += (float) $totalPrice->getNumber();
            }
            $totalTickets += (int) $item->getQuantity();
          }
        }
        catch (\Exception) {
          continue;
        }
      }
    }
    catch (\Exception) {
      // Commerce may not be available.
    }

    $feePercent = PlatformFeeDefaults::normalizePercent(
      $this->configFactory->get('myeventlane_core.settings')->get('platform_fee_percent')
    );
    $feeRate = $feePercent / 100;
    $fees = $totalGross * $feeRate;
    $net = $totalGross - $fees;

    return [
      'gross' => '$' . number_format($totalGross, 2),
      'net' => '$' . number_format($net, 2),
      'fees' => '$' . number_format($fees, 2),
      'gross_raw' => $totalGross,
      'tickets' => $totalTickets,
    ];
  }

  /**
   * Gets total order count for a vendor (all published events).
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return int
   *   Total order count.
   */
  public function getVendorOrderCount(int $userId): int {
    if ($userId <= 0) {
      return 0;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();

      if (empty($eventIds)) {
        return 0;
      }

      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      $processedOrders = [];
      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $orderId = $order->id();
            if (!isset($processedOrders[$orderId])) {
              $processedOrders[$orderId] = TRUE;
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }

      return count($processedOrders);
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Gets revenue for a vendor within a time range.
   *
   * @param int $userId
   *   The vendor user ID.
   * @param int $startTimestamp
   *   Start timestamp (inclusive).
   * @param int|null $endTimestamp
   *   End timestamp (inclusive). NULL for no upper limit.
   *
   * @return float
   *   Total revenue amount.
   */
  public function getVendorRevenueInRange(int $userId, int $startTimestamp, ?int $endTimestamp = NULL): float {
    if ($userId <= 0) {
      return 0.0;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $eventIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $userId)
        ->condition('status', 1)
        ->execute();

      if (empty($eventIds)) {
        return 0.0;
      }

      $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
      $orderItems = $orderItemStorage->loadByProperties([
        'field_target_event' => array_values($eventIds),
      ]);

      $totalRevenue = 0.0;
      foreach ($orderItems as $item) {
        if (!$item instanceof OrderItemInterface) {
          continue;
        }

        if (!$this->orderItemClassifier->isVendorRevenueEligible($item)) {
          continue;
        }

        if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
          continue;
        }

        $order_id = $item->get('order_id')->target_id;
        if (!$order_id) {
          continue;
        }

        try {
          $order = $this->entityTypeManager
            ->getStorage('commerce_order')
            ->load($order_id);
          if ($order && $order->getState()->getId() === 'completed') {
            $orderTime = $order->getCompletedTime() ?? $order->getChangedTime();
            if ($orderTime >= $startTimestamp && ($endTimestamp === NULL || $orderTime <= $endTimestamp)) {
              $totalPrice = $item->getTotalPrice();
              if ($totalPrice) {
                $totalRevenue += (float) $totalPrice->getNumber();
              }
            }
          }
        }
        catch (\Exception) {
          continue;
        }
      }

      return $totalRevenue;
    }
    catch (\Exception) {
      return 0.0;
    }
  }

}
