<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_commerce\Service\OperationalVariationStockResolver;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;

/**
 * Inspects orders to determine refund eligibility and calculate amounts.
 */
final class RefundOrderInspector implements RefundOrderInspectorInterface {

  /**
   * Donation order item bundles.
   */
  private const DONATION_BUNDLES = [
    'checkout_donation',
    'platform_donation',
    'rsvp_donation',
  ];

  /**
   * Constructs RefundOrderInspector.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The refunds logger channel.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Checks if an order item is a donation.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a donation, FALSE otherwise.
   */
  public function isDonationItem(OrderItemInterface $item): bool {
    return in_array($item->bundle(), self::DONATION_BUNDLES, TRUE);
  }

  /**
   * Checks if an order item is a ticket (not a donation).
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if the item is a ticket, FALSE otherwise.
   */
  public function isTicketItem(OrderItemInterface $item): bool {
    return !$this->isDonationItem($item) && !$this->isOperationalItem($item);
  }

  /**
   * Checks whether an order item is an operational add-on.
   */
  public function isOperationalItem(OrderItemInterface $item): bool {
    $variation = $item->getPurchasedEntity();
    return $variation !== NULL
      && in_array($variation->bundle(), OperationalVariationStockResolver::OPERATIONAL_VARIATION_BUNDLES, TRUE);
  }

  /**
   * Gets the event ID from an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $item
   *   The order item.
   *
   * @return int|null
   *   The event node ID, or NULL if not found.
   */
  public function getEventIdFromItem(OrderItemInterface $item): ?int {
    if (!$item->hasField('field_target_event') || $item->get('field_target_event')->isEmpty()) {
      return NULL;
    }

    $eventId = $item->get('field_target_event')->target_id;
    return $eventId ? (int) $eventId : NULL;
  }

  /**
   * Extracts order items for a specific event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   The event node ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   Array of order items for this event.
   */
  public function extractItemsForEvent(OrderInterface $order, int $event_nid): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      $itemEventId = $this->getEventIdFromItem($item);
      if ($itemEventId === $event_nid) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Loads refundable ticket attendees for an order scoped to an event.
   *
   * Only active ticket attendees are returned (status != cancelled).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   The event node ID.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee[]
   *   Attendees keyed by attendee ID.
   */
  public function loadRefundableTicketAttendeesForOrderEvent(OrderInterface $order, int $event_nid): array {
    $this->logger->notice('Refund attendee load start: order=@order event=@event', [
      '@order' => (int) $order->id(),
      '@event' => $event_nid,
    ]);

    $eventItems = [];
    $expectedTicketUnits = 0;
    foreach ($this->extractItemsForEvent($order, $event_nid) as $item) {
      if ($this->isTicketItem($item)) {
        $eventItems[(int) $item->id()] = $item;
        $expectedTicketUnits += max(0, (int) $item->getQuantity());
      }
    }
    $eventItemIds = array_keys($eventItems);
    if (empty($eventItemIds)) {
      $this->logger->notice('Refund attendee load summary: order=@order event=@event total_before_filter=@total returned=[] expected_units=0', [
        '@order' => (int) $order->id(),
        '@event' => $event_nid,
        '@total' => 0,
      ]);
      return [];
    }

    $candidateAttendees = $this->loadTicketAttendeesForOrderItems($event_nid, $eventItemIds, FALSE);
    $attendees = $this->loadTicketAttendeesForOrderItems($event_nid, $eventItemIds, TRUE);

    $this->logger->notice('Refund attendee load summary: order=@order event=@event total_before_filter=@total returned=@returned expected_units=@expected', [
      '@order' => (int) $order->id(),
      '@event' => $event_nid,
      '@total' => count($candidateAttendees),
      '@returned' => json_encode(array_keys($attendees), JSON_UNESCAPED_SLASHES),
      '@expected' => $expectedTicketUnits,
    ]);

    if (empty($attendees)) {
      return [];
    }

    foreach ($attendees as $attendeeId => $attendee) {
      $orderItemId = (int) ($attendee->get('order_item')->target_id ?? 0);
      $linkedOrderId = 0;
      if ($orderItemId > 0 && isset($eventItems[$orderItemId]) && method_exists($eventItems[$orderItemId], 'getOrderId')) {
        $linkedOrderId = (int) $eventItems[$orderItemId]->getOrderId();
      }
      $linkedEventId = (int) ($attendee->get('event')->target_id ?? 0);

      $this->logger->notice('Refund attendee candidate: attendee=@attendee email=@email ticket=@ticket order_item=@order_item order=@order event=@event', [
        '@attendee' => $attendeeId,
        '@email' => method_exists($attendee, 'getEmail') ? (string) $attendee->getEmail() : '',
        '@ticket' => method_exists($attendee, 'getTicketCode') ? (string) ($attendee->getTicketCode() ?? '') : '',
        '@order_item' => $orderItemId,
        '@order' => $linkedOrderId,
        '@event' => $linkedEventId,
      ]);
    }

    $this->logger->notice('Refund attendee state: order=@order event=@event expected_units=@expected active_refundable=@actual cancelled_or_refunded=@diff', [
      '@order' => (int) $order->id(),
      '@event' => $event_nid,
      '@expected' => $expectedTicketUnits,
      '@actual' => count($attendees),
      '@diff' => $expectedTicketUnits - count($attendees),
    ]);

    return $attendees;
  }

  /**
   * Builds refundable attendee pricing breakdown for an order+event.
   *
   * Amounts are deterministic and allocated per order item by attendee ID
   * ascending so UI and refund execution stay consistent.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   Event node ID.
   *
   * @return array
   *   Array keyed by attendee ID with:
   *   - attendee: EventAttendee entity
   *   - amount_cents: int cents for this attendee's ticket share
   *   - display_name: resolved attendee name
   */
  public function getRefundableTicketAttendeeBreakdown(OrderInterface $order, int $event_nid): array {
    $refundableAttendeesById = $this->loadRefundableTicketAttendeesForOrderEvent($order, $event_nid);
    if (empty($refundableAttendeesById)) {
      return [];
    }

    $orderItemsById = [];
    foreach ($this->extractItemsForEvent($order, $event_nid) as $item) {
      if ($this->isTicketItem($item)) {
        $orderItemsById[(int) $item->id()] = $item;
      }
    }
    if (empty($orderItemsById)) {
      return [];
    }

    $allAttendeesById = $this->loadTicketAttendeesForOrderItems($event_nid, array_keys($orderItemsById), FALSE);
    $allAttendeesByOrderItem = [];
    foreach ($allAttendeesById as $attendeeId => $attendee) {
      $orderItemId = (int) $attendee->get('order_item')->target_id;
      if ($orderItemId <= 0 || !isset($orderItemsById[$orderItemId])) {
        continue;
      }
      $allAttendeesByOrderItem[$orderItemId][] = $attendeeId;
    }

    $refundableLookup = array_fill_keys(array_keys($refundableAttendeesById), TRUE);
    $breakdown = [];
    foreach ($orderItemsById as $orderItemId => $orderItem) {
      $lineAttendeeIds = $allAttendeesByOrderItem[$orderItemId] ?? [];
      if (empty($lineAttendeeIds)) {
        continue;
      }
      sort($lineAttendeeIds, SORT_NUMERIC);

      $lineTotal = $orderItem->getTotalPrice();
      if (!$lineTotal) {
        continue;
      }

      $lineTotalCents = $this->priceToCents($lineTotal);
      $lineQty = max(count($lineAttendeeIds), max(0, (int) $orderItem->getQuantity()));
      if ($lineQty <= 0) {
        continue;
      }

      $baseUnit = intdiv($lineTotalCents, $lineQty);
      $remainder = $lineTotalCents % $lineQty;
      $holderNames = $this->getOrderItemHolderNames($orderItem);
      foreach ($lineAttendeeIds as $index => $attendeeId) {
        if (!isset($refundableLookup[$attendeeId])) {
          continue;
        }

        $storedName = trim((string) $refundableAttendeesById[$attendeeId]->getName());
        $resolvedName = $storedName !== ''
          ? $storedName
          : (string) ($holderNames[$index] ?? '');
        $breakdown[$attendeeId] = [
          'attendee' => $refundableAttendeesById[$attendeeId],
          'amount_cents' => $baseUnit + ($index < $remainder ? 1 : 0),
          'display_name' => $resolvedName,
        ];
      }
    }

    return $breakdown;
  }

  /**
   * Gets ticket-holder names from an order item, preserving paragraph order.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $orderItem
   *   The order item.
   *
   * @return string[]
   *   Holder names indexed by sequence.
   */
  private function getOrderItemHolderNames(OrderItemInterface $orderItem): array {
    if (!$orderItem->hasField('field_ticket_holder') || $orderItem->get('field_ticket_holder')->isEmpty()) {
      return [];
    }

    $names = [];
    foreach ($orderItem->get('field_ticket_holder')->referencedEntities() as $holder) {
      $first = $holder->hasField('field_first_name') ? trim((string) ($holder->get('field_first_name')->value ?? '')) : '';
      $last = $holder->hasField('field_last_name') ? trim((string) ($holder->get('field_last_name')->value ?? '')) : '';
      $full = trim($first . ' ' . $last);
      $names[] = $full;
    }

    return $names;
  }

  /**
   * Calculates exact cents for selected attendee IDs.
   *
   * The selected attendees must belong to this order+event ticket scope.
   * Allocation is deterministic per order item by attendee ID ascending.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   Event node ID.
   * @param int[] $selectedAttendeeIds
   *   Selected attendee IDs.
   *
   * @return int
   *   Refund amount in cents for the selected attendees.
   *
   * @throws \InvalidArgumentException
   *   Thrown when attendee selection is invalid or ambiguous.
   */
  public function calculateSelectedAttendeeRefundCents(OrderInterface $order, int $event_nid, array $selectedAttendeeIds): int {
    $selected = array_values(array_unique(array_map('intval', $selectedAttendeeIds)));
    $selected = array_values(array_filter($selected, static fn(int $id): bool => $id > 0));
    if (empty($selected)) {
      throw new \InvalidArgumentException('At least one attendee must be selected for partial ticket refund.');
    }

    $breakdown = $this->getRefundableTicketAttendeeBreakdown($order, $event_nid);
    $attendeesById = [];
    foreach ($breakdown as $attendeeId => $entry) {
      $attendeesById[(int) $attendeeId] = $entry['attendee'];
    }
    $allowedIds = array_keys($attendeesById);
    $unknown = array_values(array_diff($selected, $allowedIds));
    if (!empty($unknown)) {
      throw new \InvalidArgumentException('Selected attendee list is invalid for this order/event.');
    }

    $refundCents = 0;
    foreach ($selected as $selectedId) {
      $refundCents += (int) ($breakdown[$selectedId]['amount_cents'] ?? 0);
    }

    if ($refundCents <= 0) {
      throw new \InvalidArgumentException('Selected attendee refund amount resolved to zero.');
    }

    return $refundCents;
  }

  /**
   * Calculates ticket subtotal in cents for a specific event.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $event_nid
   *   The event node ID.
   *
   * @return int
   *   Ticket subtotal in cents.
   */
  public function calculateTicketSubtotalCents(OrderInterface $order, int $event_nid): int {
    $items = $this->extractItemsForEvent($order, $event_nid);
    $total = 0;

    foreach ($items as $item) {
      if ($this->isTicketItem($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $total += $this->priceToCents($totalPrice);
        }
      }
    }

    return $total;
  }

  /**
   * Lists whole add-on lines that are safe to refund and return to stock.
   *
   * A line is excluded if entitlement issuance is incomplete or any unit was
   * already collected, redeemed, refunded, or voided. Partial add-on line
   * refunds are intentionally unsupported until unit selection is explicit.
   *
   * @return array<int, array{item: OrderItemInterface, label: string, quantity: int, amount_cents: int}>
   *   Refundable lines keyed by order item ID.
   */
  public function getRefundableOperationalItemBreakdown(OrderInterface $order, int $event_nid): array {
    $ticketStorage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $breakdown = [];

    foreach ($this->extractItemsForEvent($order, $event_nid) as $item) {
      if (!$item instanceof OrderItemInterface || !$this->isOperationalItem($item)) {
        continue;
      }
      $itemId = (int) $item->id();
      $quantity = max(0, (int) round((float) $item->getQuantity()));
      if ($itemId < 1 || $quantity < 1) {
        continue;
      }

      $entitlementIds = $ticketStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', (int) $order->id())
        ->condition('order_item_id', $itemId)
        ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET, '<>')
        ->sort('id', 'ASC')
        ->execute();
      $entitlements = array_values($ticketStorage->loadMultiple($entitlementIds));
      if (count($entitlements) !== $quantity) {
        continue;
      }

      $safe = TRUE;
      foreach ($entitlements as $entitlement) {
        $status = (string) ($entitlement->get('status')->value ?? '');
        $fulfilment = (string) ($entitlement->get('fulfilment_status')->value ?? '');
        if (in_array($status, [Ticket::STATUS_REFUNDED, Ticket::STATUS_VOID], TRUE)
          || in_array($fulfilment, [Ticket::FULFILMENT_COLLECTED, Ticket::FULFILMENT_REDEEMED], TRUE)) {
          $safe = FALSE;
          break;
        }
      }
      $total = $item->getTotalPrice();
      if (!$safe || $total === NULL) {
        continue;
      }

      $variation = $item->getPurchasedEntity();
      $label = $variation !== NULL ? trim((string) $variation->label()) : '';
      $breakdown[$itemId] = [
        'item' => $item,
        'label' => $label !== '' ? $label : 'Event extra',
        'quantity' => $quantity,
        'amount_cents' => $this->priceToCents($total),
      ];
    }

    return $breakdown;
  }

  /**
   * Validates whole add-on line selections and returns their exact cents.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order containing the selected operational lines.
   * @param int $event_nid
   *   The event node ID that owns the operational lines.
   * @param array<int|string, int|string> $selectedItemQuantities
   *   Order item IDs mapped to the full quantity being refunded.
   *
   * @return int
   *   The exact selected operational subtotal in cents.
   */
  public function calculateSelectedOperationalRefundCents(
    OrderInterface $order,
    int $event_nid,
    array $selectedItemQuantities,
  ): int {
    $selected = [];
    foreach ($selectedItemQuantities as $itemId => $quantity) {
      $itemId = (int) $itemId;
      $quantity = (int) $quantity;
      if ($itemId > 0 && $quantity > 0) {
        $selected[$itemId] = $quantity;
      }
    }
    if ($selected === []) {
      return 0;
    }

    $breakdown = $this->getRefundableOperationalItemBreakdown($order, $event_nid);
    $total = 0;
    foreach ($selected as $itemId => $quantity) {
      $line = $breakdown[$itemId] ?? NULL;
      if ($line === NULL || $quantity !== $line['quantity']) {
        throw new \InvalidArgumentException('Selected event extras are no longer fully refundable.');
      }
      $total += $line['amount_cents'];
    }
    return $total;
  }

  /**
   * Calculates donation total in cents for the entire order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Donation total in cents.
   */
  public function calculateDonationTotalCents(OrderInterface $order): int {
    $total = 0;

    foreach ($order->getItems() as $item) {
      if ($this->isDonationItem($item)) {
        $totalPrice = $item->getTotalPrice();
        if ($totalPrice) {
          $total += $this->priceToCents($totalPrice);
        }
      }
    }

    return $total;
  }

  /**
   * Calculates refundable amount in cents based on payments and existing refunds.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return int
   *   Refundable amount in cents.
   */
  public function calculateRefundableAmountCents(OrderInterface $order): int {
    // Get all payments for this order.
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order->id())
      ->condition('state', ['completed', 'partially_refunded'], 'IN')
      ->execute();

    if (empty($paymentIds)) {
      return 0;
    }

    $payments = $paymentStorage->loadMultiple($paymentIds);
    $totalPaid = 0;
    $totalRefunded = 0;

    foreach ($payments as $payment) {
      $amount = $payment->getAmount();
      if ($amount) {
        $totalPaid += $this->priceToCents($amount);
      }

      $refundedAmount = $payment->getRefundedAmount();
      if ($refundedAmount) {
        $totalRefunded += $this->priceToCents($refundedAmount);
      }
    }

    return max(0, $totalPaid - $totalRefunded);
  }

  /**
   * Masks an email address for display.
   *
   * @param string $email
   *   The email address.
   *
   * @return string
   *   Masked email (e.g., "u***@example.com").
   */
  public function maskEmail(string $email): string {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $maskedLocal = strlen($local) > 1
      ? substr($local, 0, 1) . str_repeat('*', min(3, strlen($local) - 1))
      : '*';
    return $maskedLocal . '@' . $domain;
  }

  /**
   * Converts a Commerce price to integer cents deterministically.
   */
  public function priceToCents(Price $price): int {
    return $this->decimalStringToCents($price->getNumber());
  }

  /**
   * Converts a decimal money string to integer cents deterministically.
   *
   * Uses string arithmetic and half-up rounding at the 3rd decimal place.
   *
   * @throws \InvalidArgumentException
   *   Thrown when input is not a non-negative decimal string.
   */
  public function decimalStringToCents(string $value): int {
    $normalized = trim($value);
    if ($normalized === '' || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
      throw new \InvalidArgumentException('Invalid money value.');
    }

    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    $fractionDigits = preg_replace('/\D/', '', $fraction) ?? '';

    $fractionForRounding = str_pad($fractionDigits, 3, '0');
    $centsDigits = substr($fractionForRounding, 0, 2);
    $roundDigit = (int) $fractionForRounding[2];

    $cents = ((int) $whole * 100) + (int) $centsDigits;
    if ($roundDigit >= 5) {
      $cents++;
    }

    return $cents;
  }

  /**
   * Loads ticket attendees for event-linked order items.
   *
   * @param int $event_nid
   *   The event node ID.
   * @param int[] $eventItemIds
   *   Order item IDs linked to the event.
   * @param bool $excludeCancelled
   *   TRUE to exclude cancelled attendees.
   *
   * @return \Drupal\myeventlane_event_attendees\Entity\EventAttendee[]
   *   Attendees keyed by attendee ID.
   */
  private function loadTicketAttendeesForOrderItems(int $event_nid, array $eventItemIds, bool $excludeCancelled): array {
    $eventItemIds = array_values(array_unique(array_filter(array_map('intval', $eventItemIds))));
    if ($event_nid <= 0 || empty($eventItemIds)) {
      return [];
    }

    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
    $query = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event_nid)
      ->condition('source', EventAttendee::SOURCE_TICKET)
      ->condition('order_item', $eventItemIds, 'IN')
      ->sort('id', 'ASC');

    if ($excludeCancelled) {
      $query->condition('status', EventAttendee::STATUS_CANCELLED, '<>');
    }

    $attendeeIds = $query->execute();
    if (empty($attendeeIds)) {
      return [];
    }

    $loaded = $attendeeStorage->loadMultiple($attendeeIds);
    $attendees = [];
    foreach ($loaded as $attendee) {
      if ($attendee instanceof EventAttendee) {
        $attendees[(int) $attendee->id()] = $attendee;
      }
    }

    return $attendees;
  }

  /**
   * Returns a human-readable refund policy message for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   Event node.
   *
   * @return string
   *   Policy message suitable for customer/vendor pages.
   */
  public function getEventRefundPolicyMessage(NodeInterface $event): string {
    if (!$event->hasField('field_refund_policy') || $event->get('field_refund_policy')->isEmpty()) {
      return 'Not specified.';
    }

    $policy = trim((string) $event->get('field_refund_policy')->value);
    return match ($policy) {
      '1_day', 'refund_24h' => 'Refund requests are allowed up to 24 hours before event start.',
      '7_days', 'refund_7d' => 'Refund requests are allowed up to 7 days before event start.',
      '14_days' => 'Refund requests are allowed up to 14 days before event start.',
      '30_days' => 'Refund requests are allowed up to 30 days before event start.',
      'case_by_case' => 'Refund requests are handled case-by-case by the event organiser.',
      'no_refunds', 'none_specified' => 'Refunds are not offered for this event.',
      default => ucfirst(str_replace('_', ' ', $policy)) . '.',
    };
  }

}
