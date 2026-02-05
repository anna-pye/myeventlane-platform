<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Event order detail controller for vendor console.
 *
 * Read-only Order Detail page. Humanitix-style layout.
 * Event-scoped, store-scoped. Purchaser vs attendee separation.
 *
 * Access: assertEventOwnership($event); order must belong to event's store
 * and contain at least one item with field_target_event = event. Admin allowed.
 */
final class VendorEventOrderViewController extends VendorConsoleBaseController {

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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection (for myeventlane_refund_log).
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly Connection $database,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly TicketLabelResolver $ticketLabelResolver,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Displays the order detail for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order (from route param).
   *
   * @return array
   *   Render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When ownership validation fails.
   */
  public function view(NodeInterface $event, OrderInterface $order): array {
    $this->assertEventOwnership($event);
    $this->assertOrderEventStore($event, $order);
    $this->assertOrderHasEventItems($event, $order);

    $eventId = (int) $event->id();
    $orderViewUrl = Url::fromRoute('myeventlane_vendor.console.event_order_view', [
      'event' => $eventId,
      'order' => $order->id(),
    ])->toString();

    $backUrl = Url::fromRoute('myeventlane_vendor.console.event_orders', ['event' => $eventId])->toString();

    $tabs = $this->eventTabsService->getTabs($event, 'orders');

    $orderNumber = $order->getOrderNumber() ?: '#' . $order->id();
    $placed = $order->getPlacedTime();
    $orderDate = $placed ? $this->dateFormatter->format($placed, 'short') : '';
    $customer = $order->getCustomer();
    $purchaserName = $customer ? $customer->getDisplayName() : $this->t('Guest');
    $purchaserEmail = $order->getEmail() ?: ($customer ? $customer->getEmail() : '');

    $payment = $this->buildPaymentSummary($order, $eventId);
    $tickets = $this->buildTicketsSummary($order, $eventId);
    $attendees = $this->buildAttendeesList($order, $eventId, $orderViewUrl);

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => $event->label() . ' — Order ' . $orderNumber,
      'tabs' => $tabs,
      'body' => [
        '#theme' => 'myeventlane_vendor_event_order_view',
        '#event' => $event,
        '#order_number' => $orderNumber,
        '#back_url' => $backUrl,
        '#purchaser' => [
          'order_number' => $orderNumber,
          'order_date' => $orderDate,
          'purchaser_name' => $purchaserName,
          'purchaser_email' => $purchaserEmail,
          'order_status' => $order->getState()->getId(),
        ],
        '#payment' => $payment,
        '#tickets' => $tickets,
        '#attendees' => $attendees,
        '#order_view_url' => $orderViewUrl,
      ],
    ]);
  }

  /**
   * Ensures order's store is allowed (event's store and/or default store).
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertOrderEventStore(NodeInterface $event, OrderInterface $order): void {
    $allowedStoreIds = [];
    $storeId = $this->getEventStoreId($event);
    if ($storeId !== NULL) {
      $allowedStoreIds[] = $storeId;
    }
    $defaultStore = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
    if ($defaultStore) {
      $defId = (int) $defaultStore->id();
      if (!in_array($defId, $allowedStoreIds, TRUE)) {
        $allowedStoreIds[] = $defId;
      }
    }
    if ($allowedStoreIds === []) {
      return;
    }
    if (!in_array((int) $order->getStoreId(), $allowedStoreIds, TRUE)) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Ensures order has at least one item for this event.
   *
   * Item matches if: field_target_event, or variation field_event/
   * field_event_ref, or product field_event = eventId.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertOrderHasEventItems(NodeInterface $event, OrderInterface $order): void {
    $eventId = (int) $event->id();
    foreach ($order->getItems() as $item) {
      if ($this->orderItemIsForEvent($item, $eventId)) {
        return;
      }
    }
    throw new AccessDeniedHttpException();
  }

  /**
   * Checks if an order item is for the given event.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   * @param int $eventId
   *   Event node ID.
   *
   * @return bool
   *   TRUE if the item is for the event.
   */
  private function orderItemIsForEvent(OrderItemInterface $item, int $eventId): bool {
    // Exclude Boost: belongs to Admin, not vendor Orders.
    if ($item->bundle() === 'boost') {
      return FALSE;
    }
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
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
        if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
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
   * Uses field_event_store first; if empty, vendor's field_vendor_store.
   * Default store is allowed in assertOrderEventStore.
   */
  private function getEventStoreId(NodeInterface $event): ?int {
    if ($event->hasField('field_event_store')
      && !$event->get('field_event_store')->isEmpty()) {
      $target = $event->get('field_event_store')->target_id;
      if ($target) {
        return (int) $target;
      }
    }
    if ($event->hasField('field_event_vendor')
      && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_store')
        && !$vendor->get('field_vendor_store')->isEmpty()) {
        $store = $vendor->get('field_vendor_store')->entity;
        if ($store) {
          return (int) $store->id();
        }
      }
    }
    return NULL;
  }

  /**
   * Builds payment summary: subtotal (event items), fees, refunds, total.
   *
   * Subtotal and Tickets are event-scoped (exclude Boost and other non-event
   * items). Total = Subtotal − Fees − Refunds (what vendor keeps). Order-level
   * total (getTotalPrice) includes Boost, so we derive Total from event-scoped
   * amounts. Fees are apportioned by (subtotal / order_total) when the order
   * contains non-event items (e.g. Boost).
   *
   * @return array
   *   Keys: subtotal, fees, refunds, total (formatted strings).
   */
  private function buildPaymentSummary(OrderInterface $order, int $eventId): array {
    $currency = 'AUD';
    $subtotalNumber = 0.0;
    foreach ($this->collectEventOrderItems($order, $eventId) as $item) {
      $total = $item->getTotalPrice();
      if ($total) {
        $subtotalNumber += (float) $total->getNumber();
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

    $refundsCents = $this->getRefundTotalCents((int) $order->id(), $eventId);
    $refundsNumber = $refundsCents / 100.0;

    $orderTotalPrice = $order->getTotalPrice();
    $orderTotalNumber = $orderTotalPrice ? (float) $orderTotalPrice->getNumber() : 0.0;
    if ($orderTotalPrice) {
      $currency = $orderTotalPrice->getCurrencyCode() ?: $currency;
    }

    // Apportion order-level fees to this event by (subtotal / order_total).
    // When the order has Boost or other non-event items, the full fee would
    // overstate this event's share. If order total is 0, use 0 for fees.
    $feesForEvent = 0.0;
    if ($orderTotalNumber > 0 && $feesNumber != 0) {
      $ratio = min(1.0, $subtotalNumber / $orderTotalNumber);
      $feesForEvent = $feesNumber * $ratio;
    }

    // Total = Subtotal − Fees (event's share) − Refunds (what vendor keeps). Do not use
    // $order->getTotalPrice() — it includes Boost and other non-event items.
    $totalNumber = $subtotalNumber - $feesForEvent - $refundsNumber;

    return [
      'subtotal' => $this->formatPrice($subtotalNumber, $currency),
      'fees' => $this->formatPrice($feesForEvent, $currency),
      'refunds' => $this->formatPrice($refundsNumber, $currency),
      'total' => $this->formatPrice($totalNumber, $currency),
    ];
  }

  /**
   * Sum of completed refunds in cents from myeventlane_refund_log.
   */
  private function getRefundTotalCents(int $orderId, int $eventId): int {
    if (!$this->database->schema()->tableExists('myeventlane_refund_log')) {
      return 0;
    }
    $q = $this->database->select('myeventlane_refund_log', 'r')
      ->condition('r.order_id', $orderId)
      ->condition('r.event_id', $eventId)
      ->condition('r.status', 'completed');
    $q->addExpression('COALESCE(SUM(r.amount_cents), 0)', 'sum_cents');
    $result = $q->execute()->fetchField();
    return (int) $result;
  }

  /**
   * Builds tickets summary: event-scoped items grouped by product variation.
   *
   * @return array
   *   List of: ticket_name, unit_price, quantity, line_total (formatted).
   */
  private function buildTicketsSummary(OrderInterface $order, int $eventId): array {
    $rows = [];
    $eventItems = $this->collectEventOrderItems($order, $eventId);
    $byVariation = [];
    foreach ($eventItems as $item) {
      $var = $item->getPurchasedEntity();
      $key = $var ? $var->id() : 'item-' . $item->id();
      if (!isset($byVariation[$key])) {
        $byVariation[$key] = [
          'name' => $this->ticketLabelResolver->getTicketLabel($item),
          'unit' => $item->getUnitPrice(),
          'qty' => 0,
          'line' => 0.0,
          'currency' => 'AUD',
        ];
      }
      $byVariation[$key]['qty'] += (int) $item->getQuantity();
      $total = $item->getTotalPrice();
      if ($total) {
        $byVariation[$key]['line'] += (float) $total->getNumber();
        $byVariation[$key]['currency'] = $total->getCurrencyCode() ?: $byVariation[$key]['currency'];
      }
      $u = $item->getUnitPrice();
      if ($u) {
        $byVariation[$key]['unit'] = $u;
      }
    }
    foreach ($byVariation as $r) {
      $unit = $r['unit'];
      $unitNum = $unit ? (float) $unit->getNumber() : 0.0;
      $curr = $unit ? ($unit->getCurrencyCode() ?: $r['currency']) : $r['currency'];
      $rows[] = [
        'ticket_name' => $r['name'],
        'unit_price' => $this->formatPrice($unitNum, $curr),
        'quantity' => $r['qty'],
        'line_total' => $this->formatPrice($r['line'], $r['currency']),
      ];
    }
    return $rows;
  }

  /**
   * Builds attendees from field_ticket_holder (one attendee per paragraph).
   *
   * @return array
   *   List of: first_name, last_name, email, ticket_type (product variation
   *   label, e.g. Full price, Concession), custom_questions, order_view_url.
   */
  private function buildAttendeesList(OrderInterface $order, int $eventId, string $orderViewUrl): array {
    $list = [];
    foreach ($this->collectEventOrderItems($order, $eventId) as $item) {
      if (!$item->hasField('field_ticket_holder') || $item->get('field_ticket_holder')->isEmpty()) {
        continue;
      }
      $ticketType = $this->ticketLabelResolver->getTicketLabel($item);
      foreach ($item->get('field_ticket_holder')->referencedEntities() as $holder) {
        if (!$holder instanceof EntityInterface) {
          continue;
        }
        $custom = $this->collectCustomQuestions($holder);
        $list[] = [
          'first_name' => $holder->hasField('field_first_name') ? ($holder->get('field_first_name')->value ?? '') : '',
          'last_name' => $holder->hasField('field_last_name') ? ($holder->get('field_last_name')->value ?? '') : '',
          'email' => $holder->hasField('field_email') ? ($holder->get('field_email')->value ?? '') : '',
          'ticket_type' => $ticketType,
          'custom_questions' => $custom,
          'order_view_url' => $orderViewUrl,
        ];
      }
    }
    return $list;
  }

  /**
   * Collects label/value for field_attendee_questions on a holder paragraph.
   *
   * @param \Drupal\Core\Entity\EntityInterface $holder
   *   The ticket holder paragraph (or similar entity).
   *
   * @return array
   *   List of arrays, each with 'label' and 'value' keys for custom questions.
   */
  private function collectCustomQuestions(EntityInterface $holder): array {
    $out = [];
    if (!$holder->hasField('field_attendee_questions') || $holder->get('field_attendee_questions')->isEmpty()) {
      return $out;
    }
    foreach ($holder->get('field_attendee_questions')->referencedEntities() as $q) {
      if (!$q instanceof EntityInterface) {
        continue;
      }
      $label = $q->hasField('field_question_label') && !$q->get('field_question_label')->isEmpty()
        ? ($q->get('field_question_label')->value ?? '') : $this->t('Question');
      $value = $q->hasField('field_attendee_extra_field') && !$q->get('field_attendee_extra_field')->isEmpty()
        ? ($q->get('field_attendee_extra_field')->value ?? '') : '';
      $out[] = ['label' => $label, 'value' => $value];
    }
    return $out;
  }

  /**
   * Returns order items for this event.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   Filtered order items for the event.
   */
  private function collectEventOrderItems(OrderInterface $order, int $eventId): array {
    $out = [];
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      if ($this->orderItemIsForEvent($item, $eventId)) {
        $out[] = $item;
      }
    }
    return $out;
  }

  /**
   * Formats a numeric amount for display.
   */
  private function formatPrice(float $number, string $currency): string {
    if (strtoupper($currency) === 'AUD') {
      return '$' . number_format($number, 2);
    }
    return number_format($number, 2) . ' ' . $currency;
  }

}
