<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles completed ticket refunds with admission entitlements.
 */
final class RefundTicketEntitlementReconciler {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Cancels roster entries and revokes their canonical tickets idempotently.
   *
   * @return int
   *   Number of ticket admissions covered by the completed refund.
   */
  public function reconcile(OrderInterface $order, NodeInterface $event, array $log): int {
    if ((string) ($log['refund_scope'] ?? '') === 'donation_only') {
      return 0;
    }

    $selectedIds = $this->decodeIds((string) ($log['attendee_ids_json'] ?? ''));
    if ((string) ($log['refund_type'] ?? '') !== 'full' && $selectedIds === []) {
      $this->logger->warning('Skipped ticket entitlement reconciliation for refund log @log_id: partial refund has no attendee selection.', [
        '@log_id' => (int) ($log['id'] ?? 0),
      ]);
      return 0;
    }

    $eventId = (int) $event->id();
    $orderId = (int) $order->id();
    $orderItemIds = [];
    foreach ($order->getItems() as $item) {
      if ($this->orderItemIsForEvent($item, $eventId)) {
        $orderItemIds[] = (int) $item->id();
      }
    }
    $orderItemIds = array_values(array_unique(array_filter($orderItemIds)));
    if ($eventId < 1 || $orderId < 1 || $orderItemIds === []) {
      $this->logger->error('Cannot reconcile ticket entitlements for refund log @log_id: invalid order, event, or event order items.', [
        '@log_id' => (int) ($log['id'] ?? 0),
      ]);
      return 0;
    }

    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
    $attendeeIds = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('source', EventAttendee::SOURCE_TICKET)
      ->condition('order_item', $orderItemIds, 'IN')
      ->sort('id', 'ASC')
      ->execute();
    $attendees = array_values($attendeeStorage->loadMultiple($attendeeIds));

    $ticketStorage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ticketIds = $ticketStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->condition('event_id', $eventId)
      ->condition('order_item_id', $orderItemIds, 'IN')
      ->condition('entitlement_type', Ticket::ENTITLEMENT_TICKET)
      ->sort('id', 'ASC')
      ->execute();
    $tickets = array_values($ticketStorage->loadMultiple($ticketIds));

    $attendeesByItem = $this->groupByOrderItem($attendees, 'order_item');
    $ticketsByItem = $this->groupByOrderItem($tickets, 'order_item_id');
    $selectedLookup = array_fill_keys($selectedIds, TRUE);
    $affected = 0;

    foreach ($orderItemIds as $orderItemId) {
      $itemAttendees = $attendeesByItem[$orderItemId] ?? [];
      $itemTickets = $ticketsByItem[$orderItemId] ?? [];

      // A full refund targets every admission. No attendee-to-ticket pairing is
      // needed, so legacy count drift must not leave a usable ticket behind.
      if ($selectedIds === []) {
        foreach ($itemAttendees as $attendee) {
          $this->cancelAttendee($attendee);
        }
        foreach ($itemTickets as $ticket) {
          if ($this->refundTicket($ticket)) {
            $affected++;
          }
        }
        continue;
      }

      // Partial refunds require an exact positional mapping. Refuse to guess
      // when historical data has drifted, rather than revoking the wrong seat.
      if (count($itemAttendees) !== count($itemTickets)) {
        $this->logger->error('Ticket entitlement reconciliation blocked for order item @item: @attendees attendees do not match @tickets tickets.', [
          '@item' => $orderItemId,
          '@attendees' => count($itemAttendees),
          '@tickets' => count($itemTickets),
        ]);
        continue;
      }

      foreach ($itemAttendees as $index => $attendee) {
        if (!$attendee instanceof EventAttendee) {
          continue;
        }
        if ($selectedIds !== [] && !isset($selectedLookup[(int) $attendee->id()])) {
          continue;
        }

        $ticket = $itemTickets[$index] ?? NULL;
        if (!$ticket instanceof ContentEntityInterface) {
          continue;
        }
        $affected++;
        $this->cancelAttendee($attendee);
        $this->refundTicket($ticket);
      }
    }

    $this->logger->notice('Reconciled @count refunded ticket entitlement(s) for refund log @log_id.', [
      '@count' => $affected,
      '@log_id' => (int) ($log['id'] ?? 0),
    ]);
    return $affected;
  }

  /**
   * Cancels an attendee only when its state still grants admission.
   */
  private function cancelAttendee(mixed $attendee): void {
    if (!$attendee instanceof EventAttendee || $attendee->getStatus() === EventAttendee::STATUS_CANCELLED) {
      return;
    }
    $attendee->setStatus(EventAttendee::STATUS_CANCELLED);
    $attendee->save();
  }

  /**
   * Marks a canonical ticket refunded and cancels fulfilment idempotently.
   */
  private function refundTicket(mixed $ticket): bool {
    if (!$ticket instanceof ContentEntityInterface) {
      return FALSE;
    }
    if ((string) $ticket->get('status')->value !== Ticket::STATUS_REFUNDED) {
      $ticket->set('status', Ticket::STATUS_REFUNDED);
      if ($ticket->hasField('fulfilment_status')) {
        $ticket->set('fulfilment_status', Ticket::FULFILMENT_CANCELLED);
      }
      $ticket->save();
    }
    return TRUE;
  }

  /**
   * Groups entities by an entity-reference field while preserving ID order.
   */
  private function groupByOrderItem(array $entities, string $field): array {
    $grouped = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
        continue;
      }
      $grouped[(int) $entity->get($field)->target_id][] = $entity;
    }
    return $grouped;
  }

  /**
   * Decodes a persisted attendee ID list.
   *
   * @return list<int>
   *   Positive unique attendee IDs.
   */
  private function decodeIds(string $json): array {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn(int $id): bool => $id > 0)));
  }

  /**
   * Determines whether a Commerce order item belongs to the refunded event.
   */
  private function orderItemIsForEvent(mixed $item, int $eventId): bool {
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()
      && (int) $item->get('field_target_event')->target_id === $eventId) {
      return TRUE;
    }

    $variation = $item->getPurchasedEntity();
    if (!$variation) {
      return FALSE;
    }
    foreach (['field_event', 'field_event_ref'] as $field) {
      if ($variation->hasField($field) && !$variation->get($field)->isEmpty()
        && (int) $variation->get($field)->target_id === $eventId) {
        return TRUE;
      }
    }

    try {
      $product = $variation->getProduct();
      return $product
        && $product->hasField('field_event')
        && !$product->get('field_event')->isEmpty()
        && (int) $product->get('field_event')->target_id === $eventId;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
