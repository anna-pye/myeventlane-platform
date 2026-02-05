<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Event orders controller for vendor console.
 *
 * Lists orders for THIS EVENT only, scoped to the vendor.
 * Access: Vendor owns the event (via assertEventOwnership), Admin allowed.
 */
final class VendorEventOrdersController extends VendorConsoleBaseController {

  /**
   * Order states to include in the list.
   *
   * Completed, partially_refunded, refunded: paid/finalised.
   * Placed, fulfilled: paid orders in workflows that use these as terminal.
   * Fulfillment: order_fulfillment intermediate (placed, not fulfilled).
   */
  private const INCLUDED_STATES = [
    'completed',
    'partially_refunded',
    'refunded',
    'placed',
    'fulfilled',
    'fulfillment',
  ];

  /**
   * Constructs the controller.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domain_detector
   *   The domain detector.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly VendorEventTabsService $eventTabsService,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays the orders list for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function orders(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $tabs = $this->eventTabsService->getTabs($event, 'orders');
    $data = $this->getOrdersForEvent($event);

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Orders',
      'tabs' => $tabs,
      'body' => [
        '#theme' => 'myeventlane_vendor_event_orders',
        '#event' => $event,
        '#orders' => $data['rows'],
        '#totals' => $data['totals'],
      ],
    ]);
  }

  /**
   * Gets orders for an event.
   *
   * Filters: field_target_event = event, order.store_id = event's store,
   * order.state IN included states. Sort: order.placed DESC.
   *
   * Primary: find order items with field_target_event=event, then their
   * orders (store + state filtered). Fallback: if none, find orders for
   * store+state then filter to those with an event-scoped item.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array{rows: array, totals: array}
   *   'rows': order row data (order_id, order_number, placed_date, etc.).
   *   'totals': footer sums (ticket_count, net_sales, fees, total).
   */
  private function getOrdersForEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $storeId = $this->getEventStoreId($event);
    if ($storeId === NULL) {
      $defaultStore = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      if ($defaultStore) {
        $storeId = (int) $defaultStore->id();
      }
    }
    $allowedStoreIds = $storeId !== NULL ? [$storeId] : [];

    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

    // Primary: order items with field_target_event = event.
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    $orderIds = [];
    $foundViaEventLink = FALSE;
    if (!empty($orderItemIds)) {
      $items = $orderItemStorage->loadMultiple($orderItemIds);
      foreach ($items as $item) {
        if ($item instanceof OrderItemInterface && $item->getOrderId()) {
          $orderIds[(int) $item->getOrderId()] = TRUE;
        }
      }
      $orderIds = array_keys($orderIds);
      $foundViaEventLink = !empty($orderIds);
    }

    // Intermediate: if primary is empty, find order items by variation's
    // field_event or field_event_ref = event (in case field_target_event
    // was never set on items).
    if (empty($orderIds)) {
      $orderIds = $this->findOrderIdsByVariationEvent($orderItemStorage, $eventId);
      $foundViaEventLink = !empty($orderIds);
    }

    // Fallback: if still empty and we have a store, find orders for
    // store+state and keep only those with an item for this event. If the
    // event's store returns none, also try the default store.
    if (empty($orderIds) && $storeId !== NULL) {
      $orderIds = $this->findOrderIdsByStoreAndState($orderStorage, $storeId);
      $orderIds = $this->filterOrderIdsHavingEventItems($orderStorage, $orderIds, $eventId);
      if (empty($orderIds)) {
        $defaultStore = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
        if ($defaultStore && (int) $defaultStore->id() !== $storeId) {
          $orderIds = $this->findOrderIdsByStoreAndState($orderStorage, (int) $defaultStore->id());
          $orderIds = $this->filterOrderIdsHavingEventItems($orderStorage, $orderIds, $eventId);
          if (!empty($orderIds)) {
            $allowedStoreIds = [$storeId, (int) $defaultStore->id()];
          }
        }
      }
    }

    if (empty($orderIds)) {
      return ['rows' => [], 'totals' => $this->computeOrderTotals([])];
    }

    $orders = $orderStorage->loadMultiple($orderIds);
    $filtered = [];
    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      // When orders were found via event-linked items (primary/variation path),
      // do not filter by store. Order items explicitly reference this event;
      // event ownership is already asserted. Store mismatch can occur when
      // checkout uses the default store instead of the vendor's store.
      if (!$foundViaEventLink
        && !empty($allowedStoreIds)
        && !in_array((int) $order->getStoreId(), $allowedStoreIds, TRUE)) {
        continue;
      }
      $state = $order->getState()->getId();
      if (!in_array($state, self::INCLUDED_STATES, TRUE)) {
        continue;
      }
      // Exclude orders that have only Boost (no ticket items for this event).
      $hasEventItem = FALSE;
      foreach ($order->getItems() as $it) {
        if ($this->orderItemIsForEvent($it, $eventId)) {
          $hasEventItem = TRUE;
          break;
        }
      }
      if (!$hasEventItem) {
        continue;
      }
      $filtered[] = $order;
    }

    // Sort by placed DESC. getPlacedTime() returns an int (Unix timestamp) or NULL.
    usort($filtered, static function (OrderInterface $a, OrderInterface $b): int {
      $ta = $a->getPlacedTime() ?? 0;
      $tb = $b->getPlacedTime() ?? 0;
      return $tb <=> $ta;
    });

    $rows = [];
    foreach ($filtered as $order) {
      $rows[] = $this->buildOrderRow($order, $eventId);
    }

    return ['rows' => $rows, 'totals' => $this->computeOrderTotals($rows)];
  }

  /**
   * Finds order IDs via order items whose purchased variation has event link.
   *
   * Used when field_target_event is empty on items. Queries variations with
   * field_event or field_event_ref = eventId, then order items with
   * purchased_entity IN those variations.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $orderItemStorage
   *   Order item storage.
   * @param int $eventId
   *   Event node ID.
   *
   * @return int[]
   *   Order IDs.
   */
  private function findOrderIdsByVariationEvent($orderItemStorage, int $eventId): array {
    $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $vids = [];
    $ids = $varStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_event', $eventId)
      ->execute();
    if ($ids) {
      $vids = array_merge($vids, array_map('intval', (array) $ids));
    }
    try {
      $ids2 = $varStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_event_ref', $eventId)
        ->execute();
      if ($ids2) {
        $vids = array_merge($vids, array_map('intval', (array) $ids2));
      }
    }
    catch (\Throwable $e) {
      // field_event_ref may not exist on all variation types; skip.
    }
    $vids = array_values(array_unique(array_filter($vids)));
    if (empty($vids)) {
      return [];
    }
    $itemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('purchased_entity', $vids, 'IN')
      ->execute();
    if (empty($itemIds)) {
      return [];
    }
    $items = $orderItemStorage->loadMultiple($itemIds);
    $orderIds = [];
    foreach ($items as $item) {
      if ($item instanceof OrderItemInterface && $item->getOrderId()) {
        $orderIds[(int) $item->getOrderId()] = TRUE;
      }
    }
    return array_keys($orderIds);
  }

  /**
   * Finds order IDs for a store with state in INCLUDED_STATES.
   *
   * @return int[]
   *   Order IDs.
   */
  private function findOrderIdsByStoreAndState($orderStorage, int $storeId): array {
    $ids = $orderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('store_id', $storeId)
      ->condition('state', self::INCLUDED_STATES, 'IN')
      ->execute();
    return array_map('intval', (array) $ids);
  }

  /**
   * Keeps order IDs that have an item for this event.
   *
   * An item counts if: field_target_event=eventId, or the purchased
   * variation's field_event=eventId, or the product's field_event=eventId.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $orderStorage
   *   Order storage.
   * @param int[] $orderIds
   *   Order IDs to check.
   * @param int $eventId
   *   Event node ID.
   *
   * @return int[]
   *   Filtered order IDs.
   */
  private function filterOrderIdsHavingEventItems($orderStorage, array $orderIds, int $eventId): array {
    $out = [];
    foreach ($orderStorage->loadMultiple($orderIds) as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      foreach ($order->getItems() as $item) {
        if ($this->orderItemIsForEvent($item, $eventId)) {
          $out[] = (int) $order->id();
          break;
        }
      }
    }
    return $out;
  }

  /**
   * Checks if an order item is for the given event.
   *
   * True when: field_target_event=eventId; or the purchased variation's
   * field_event or field_event_ref=eventId; or the product's
   * field_event=eventId.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   * @param int $eventId
   *   Event node ID.
   *
   * @return bool
   *   TRUE if the item is for the event, FALSE otherwise.
   */
  private function orderItemIsForEvent(OrderItemInterface $item, int $eventId): bool {
    // Exclude Boost: belongs to Admin, not vendor Orders.
    if ($item->bundle() === 'boost') {
      return FALSE;
    }
    if ($item->hasField('field_target_event')
      && !$item->get('field_target_event')->isEmpty()) {
      if ((int) $item->get('field_target_event')->target_id === $eventId) {
        return TRUE;
      }
    }
    $var = $item->getPurchasedEntity();
    if ($var) {
      if ($var->hasField('field_event') && !$var->get('field_event')->isEmpty()) {
        if ((int) $var->get('field_event')->target_id === $eventId) {
          return TRUE;
        }
      }
      if ($var->hasField('field_event_ref') && !$var->get('field_event_ref')->isEmpty()) {
        if ((int) $var->get('field_event_ref')->target_id === $eventId) {
          return TRUE;
        }
      }
      try {
        $product = $var->getProduct();
        if ($product && $product->hasField('field_event')
          && !$product->get('field_event')->isEmpty()) {
          if ((int) $product->get('field_event')->target_id === $eventId) {
            return TRUE;
          }
        }
      }
      catch (\Throwable $e) {
        // No product or field; skip.
      }
    }
    return FALSE;
  }

  /**
   * Gets the event's store ID.
   *
   * Uses field_event_store first. If empty, falls back to the vendor's
   * store: field_event_vendor -> field_vendor_store.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int|null
   *   The store ID, or NULL if not set.
   */
  private function getEventStoreId(NodeInterface $event): ?int {
    if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
      $target = $event->get('field_event_store')->target_id;
      if ($target) {
        return (int) $target;
      }
    }
    // Fallback: vendor's store when field_event_store is empty.
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $store = $vendor->get('field_vendor_store')->entity;
        if ($store) {
          return (int) $store->id();
        }
      }
    }
    return NULL;
  }

  /**
   * Builds a single order row for the list.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Row with order_id, order_number, placed_date, purchaser_name,
   *   purchaser_email, ticket_count, net_sales, fees, total, status, view_link.
   */
  private function buildOrderRow(OrderInterface $order, int $eventId): array {
    $customer = $order->getCustomer();
    $purchaserName = $customer ? $customer->getDisplayName() : $this->t('Guest');
    $purchaserEmail = $order->getEmail() ?: ($customer ? $customer->getEmail() : '');

    $placed = $order->getPlacedTime();
    $placedDate = $placed ? $this->dateFormatter->format($placed, 'short') : '';

    $ticketCount = 0;
    $netSalesNumber = 0.0;
    $currency = 'AUD';
    foreach ($order->getItems() as $item) {
      if (!$this->orderItemIsForEvent($item, $eventId)) {
        continue;
      }
      $ticketCount += (int) $item->getQuantity();
      $total = $item->getTotalPrice();
      if ($total) {
        $netSalesNumber += (float) $total->getNumber();
        $currency = $total->getCurrencyCode() ?: $currency;
      }
    }

    $feesNumber = 0.0;
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'fee') {
        $amount = $adjustment->getAmount();
        if ($amount) {
          $feesNumber += (float) $amount->getNumber();
        }
      }
    }

    $orderTotalPrice = $order->getTotalPrice();
    $orderTotalNumber = $orderTotalPrice ? (float) $orderTotalPrice->getNumber() : 0.0;
    if ($orderTotalPrice) {
      $currency = $orderTotalPrice->getCurrencyCode() ?: $currency;
    }

    // Apportion fees to this event. Total = net_sales − fees (what vendor keeps).
    // Do not use $order->getTotalPrice() — it includes Boost and other non-event items.
    $feesForEvent = 0.0;
    if ($orderTotalNumber > 0 && $feesNumber != 0) {
      $ratio = min(1.0, $netSalesNumber / $orderTotalNumber);
      $feesForEvent = $feesNumber * $ratio;
    }
    $totalNumber = $netSalesNumber - $feesForEvent;

    $orderNumber = $order->getOrderNumber() ?: '#' . $order->id();

    $viewLink = [
      'url' => Url::fromRoute('myeventlane_vendor.console.event_order_view', [
        'event' => $eventId,
        'order' => $order->id(),
      ])->toString(),
      'label' => $this->t('View'),
    ];

    return [
      'order_id' => $order->id(),
      'order_number' => $orderNumber,
      'placed_date' => $placedDate,
      'purchaser_name' => $purchaserName,
      'purchaser_email' => $purchaserEmail,
      'ticket_count' => $ticketCount,
      'net_sales' => $this->formatPrice($netSalesNumber, $currency),
      'fees' => $this->formatPrice($feesForEvent, $currency),
      'total' => $this->formatPrice($totalNumber, $currency),
      'status' => $order->getState()->getId(),
      'view_link' => $viewLink,
      'raw_ticket_count' => $ticketCount,
      'raw_net_sales' => $netSalesNumber,
      'raw_fees' => $feesForEvent,
      'raw_total' => $totalNumber,
      'raw_currency' => $currency,
    ];
  }

  /**
   * Computes footer totals from order rows.
   *
   * Sums ticket count, net sales, fees, and total (net_sales − fees, vendor
   * keep). Returns formatted strings for display.
   *
   * @param array[] $rows
   *   Rows from buildOrderRow (must include raw_* keys).
   *
   * @return array
   *   Keys: ticket_count, net_sales, fees, total (formatted).
   */
  private function computeOrderTotals(array $rows): array {
    $sumTickets = 0;
    $sumNet = 0.0;
    $sumFees = 0.0;
    $sumTotal = 0.0;
    $currency = 'AUD';
    foreach ($rows as $r) {
      $sumTickets += (int) ($r['raw_ticket_count'] ?? 0);
      $sumNet += (float) ($r['raw_net_sales'] ?? 0);
      $sumFees += (float) ($r['raw_fees'] ?? 0);
      $sumTotal += (float) ($r['raw_total'] ?? 0);
      if (!empty($r['raw_currency'])) {
        $currency = $r['raw_currency'];
      }
    }
    return [
      'ticket_count' => $sumTickets,
      'net_sales' => $this->formatPrice($sumNet, $currency),
      'fees' => $this->formatPrice($sumFees, $currency),
      'total' => $this->formatPrice($sumTotal, $currency),
    ];
  }

  /**
   * Formats a numeric amount for display.
   *
   * @param float $number
   *   The amount.
   * @param string $currency
   *   The currency code.
   *
   * @return string
   *   Formatted string, e.g. "$12.34".
   */
  private function formatPrice(float $number, string $currency): string {
    if (strtoupper($currency) === 'AUD') {
      return '$' . number_format($number, 2);
    }
    return number_format($number, 2) . ' ' . $currency;
  }

}
