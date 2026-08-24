<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketCodeGenerator;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Safely creates revoked canonical rows for legacy completed refunds.
 */
final class LegacyRefundEntitlementRepairer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketCodeGenerator $ticketCodeGenerator,
    private readonly LoggerInterface $logger,
    private readonly Connection $database,
  ) {}

  /**
   * Plans or applies a bounded legacy entitlement repair.
   *
   * @return array{targets: int, created: int, existing: int, blocked: list<string>}
   *   Repair outcome. A dry run reports creatable targets in created.
   */
  public function repair(OrderInterface $order, NodeInterface $event, array $log, bool $apply = FALSE): array {
    $result = [
      'targets' => 0,
      'created' => 0,
      'existing' => 0,
      'blocked' => [],
    ];
    if ((string) ($log['refund_scope'] ?? '') === 'donation_only') {
      return $result;
    }

    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
    $selectedIds = $this->decodeIds((string) ($log['attendee_ids_json'] ?? ''));
    if ((string) ($log['refund_type'] ?? '') !== 'full' && $selectedIds === []) {
      $result['blocked'][] = 'Partial refund has no persisted attendee selection.';
      return $result;
    }

    $orderItemIds = [];
    foreach ($order->getItems() as $orderItem) {
      if ($this->orderItemBelongsToEvent($orderItem, $event)) {
        $orderItemIds[] = (int) $orderItem->id();
      }
    }
    if ($orderItemIds === []) {
      $result['blocked'][] = 'The order has no ticket items safely linked to this event.';
      return $result;
    }

    $query = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', (int) $event->id())
      ->condition('order_item', $orderItemIds, 'IN')
      ->condition('source', EventAttendee::SOURCE_TICKET);
    if ($selectedIds !== []) {
      $query->condition('id', $selectedIds, 'IN');
    }
    $attendees = array_values($attendeeStorage->loadMultiple($query->execute()));
    $result['targets'] = count($selectedIds !== [] ? $selectedIds : $attendees);

    if ($selectedIds !== [] && count($attendees) !== count($selectedIds)) {
      $result['blocked'][] = 'One or more selected attendee records no longer exist for this event.';
      return $result;
    }
    if ($attendees === []) {
      $result['blocked'][] = 'No ticket attendee records remain for this refund.';
      return $result;
    }

    $tickets = $this->loadOrderTickets((int) $order->id(), (int) $event->id());
    $legacyMarkers = [];
    $unmarkedByItem = [];
    foreach ($tickets as $ticket) {
      $marker = $this->legacyAttendeeMarker($ticket);
      $itemId = (int) $ticket->get('order_item_id')->target_id;
      if ($marker > 0) {
        $legacyMarkers[$marker] = TRUE;
      }
      else {
        $unmarkedByItem[$itemId] = TRUE;
      }
    }

    $planned = [];
    foreach ($attendees as $attendee) {
      if (!$attendee instanceof EventAttendee) {
        continue;
      }
      $attendeeId = (int) $attendee->id();
      if (isset($legacyMarkers[$attendeeId])) {
        $result['existing']++;
        continue;
      }

      $orderItem = $attendee->get('order_item')->entity;
      if (!$orderItem instanceof OrderItemInterface
        || !$this->attendeeBelongsToOrderItem($attendee, $order, $event, $orderItem)) {
        $result['blocked'][] = sprintf('Attendee %d is not safely linked to this order and event.', $attendeeId);
        continue;
      }
      $orderItemId = (int) $orderItem->id();
      if (isset($unmarkedByItem[$orderItemId])) {
        $result['blocked'][] = sprintf('Order item %d already has an unmarked canonical ticket; mapping is ambiguous.', $orderItemId);
        continue;
      }

      $result['created']++;
      $planned[] = [$orderItem, $attendee];
    }

    if ($apply && $result['blocked'] === []) {
      // Keep the transaction in scope until every entity save succeeds.
      // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
      $transaction = $this->database->startTransaction();
      foreach ($planned as [$orderItem, $attendee]) {
        $this->createRevokedTicket($order, $event, $orderItem, $attendee, (int) ($log['id'] ?? 0));
      }
      $this->logger->notice('Legacy refund log @log repaired: @created revoked canonical ticket(s), @existing already present.', [
        '@log' => (int) ($log['id'] ?? 0),
        '@created' => $result['created'],
        '@existing' => $result['existing'],
      ]);
    }
    return $result;
  }

  /**
   * Loads canonical tickets for an order and event.
   *
   * @return list<\Drupal\myeventlane_tickets\Entity\Ticket>
   *   Ticket entities.
   */
  private function loadOrderTickets(int $orderId, int $eventId): array {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->condition('event_id', $eventId)
      ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET)
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Creates one permanently revoked canonical entitlement.
   */
  private function createRevokedTicket(
    OrderInterface $order,
    NodeInterface $event,
    OrderItemInterface $orderItem,
    EventAttendee $attendee,
    int $logId,
  ): void {
    $purchasedEntity = $orderItem->getPurchasedEntity();
    $ticket = $this->entityTypeManager->getStorage('myeventlane_ticket')->create([
      'ticket_code' => $this->ticketCodeGenerator->generateUniqueTicketCode(),
      'event_id' => $event->id(),
      'order_id' => $order->id(),
      'order_item_id' => $orderItem->id(),
      'purchased_entity' => $purchasedEntity?->id(),
      'purchaser_uid' => $order->getCustomerId(),
      'holder_name' => trim((string) $attendee->get('name')->value),
      'holder_email' => trim((string) $attendee->get('email')->value),
      'status' => Ticket::STATUS_REFUNDED,
      'entitlement_type' => Ticket::ENTITLEMENT_TICKET,
      'fulfilment_status' => Ticket::FULFILMENT_CANCELLED,
      'metadata_json' => [
        'legacy_refund_repair' => [
          'refund_log_id' => $logId,
          'attendee_id' => (int) $attendee->id(),
        ],
      ],
    ]);
    $ticket->save();

    if ($attendee->getStatus() !== EventAttendee::STATUS_CANCELLED) {
      $attendee->setStatus(EventAttendee::STATUS_CANCELLED);
      $attendee->save();
    }
  }

  /**
   * Confirms the attendee, order item, order and event form one chain.
   */
  private function attendeeBelongsToOrderItem(
    EventAttendee $attendee,
    OrderInterface $order,
    NodeInterface $event,
    OrderItemInterface $orderItem,
  ): bool {
    $itemOrder = $orderItem->getOrder();
    if (!$itemOrder instanceof OrderInterface || (int) $itemOrder->id() !== (int) $order->id()) {
      return FALSE;
    }
    if ((int) $attendee->get('event')->target_id !== (int) $event->id()) {
      return FALSE;
    }
    if ((int) $attendee->get('order_item')->target_id !== (int) $orderItem->id()) {
      return FALSE;
    }

    return $this->orderItemBelongsToEvent($orderItem, $event);
  }

  /**
   * Confirms an order item targets the supplied event.
   */
  private function orderItemBelongsToEvent(OrderItemInterface $orderItem, NodeInterface $event): bool {
    if ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()) {
      return (int) $orderItem->get('field_target_event')->target_id === (int) $event->id();
    }
    $variation = $orderItem->getPurchasedEntity();
    return $variation !== NULL
      && $variation->hasField('field_event')
      && !$variation->get('field_event')->isEmpty()
      && (int) $variation->get('field_event')->target_id === (int) $event->id();
  }

  /**
   * Reads the durable attendee marker from a repaired ticket.
   */
  private function legacyAttendeeMarker(Ticket $ticket): int {
    if (!$ticket->hasField('metadata_json') || $ticket->get('metadata_json')->isEmpty()) {
      return 0;
    }
    $metadata = $ticket->get('metadata_json')->first()?->getValue() ?? [];
    return is_array($metadata) ? (int) ($metadata['legacy_refund_repair']['attendee_id'] ?? 0) : 0;
  }

  /**
   * Decodes a persisted attendee ID list.
   *
   * @return list<int>
   *   Positive unique IDs.
   */
  private function decodeIds(string $json): array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn(int $id): bool => $id > 0)));
  }

}
