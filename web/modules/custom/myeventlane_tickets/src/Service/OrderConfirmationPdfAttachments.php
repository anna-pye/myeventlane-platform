<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator;
use Psr\Log\LoggerInterface;

/**
 * Adds ticket PDF bytes to order_confirmation mail attachments at send time.
 *
 * Runs after ORDER_PAID has issued ticket rows; PDFs are generated on demand.
 */
final class OrderConfirmationPdfAttachments {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketPdfGenerator $ticketPdfGenerator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Merges PDF attachments for the order into the existing list (ICS, etc.).
   *
   * @param int $orderId
   *   Commerce order ID.
   * @param array $attachments
   *   Existing attachments (filename, content, mime).
   *
   * @return array
   *   Combined attachments; failures are logged and skipped.
   */
  public function mergeForOrder(int $orderId, array $attachments): array {
    if (!$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return $attachments;
    }

    $ticket_storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $ticket_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->execute();

    if ($ids === []) {
      return $attachments;
    }

    /** @var \Drupal\myeventlane_tickets\Entity\Ticket[] $tickets */
    $tickets = $ticket_storage->loadMultiple($ids);

    $legacy_order_item_ids = [];

    foreach ($tickets as $ticket) {
      $holder_ok = !$ticket->get('holder_name')->isEmpty()
        && !$ticket->get('holder_email')->isEmpty();
      if ($holder_ok) {
        try {
          $attachments[] = $this->ticketPdfGenerator->getPdfContentForTicket($ticket);
        }
        catch (\Throwable $e) {
          $this->logger->warning('Order confirmation: PDF not attached for ticket @id: @message', [
            '@id' => (string) $ticket->id(),
            '@message' => $e->getMessage(),
            'order_id' => $orderId,
            'ticket_id' => (int) $ticket->id(),
          ]);
        }
      }
      else {
        $oiid = $ticket->get('order_item_id')->target_id;
        if ($oiid) {
          $legacy_order_item_ids[(int) $oiid] = TRUE;
        }
      }
    }

    foreach (array_keys($legacy_order_item_ids) as $order_item_id) {
      $order_item = $this->entityTypeManager->getStorage('commerce_order_item')->load($order_item_id);
      if (!$order_item instanceof OrderItemInterface) {
        continue;
      }
      try {
        $attachments[] = $this->ticketPdfGenerator->getPdfContentForOrderItem($order_item);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Order confirmation: legacy PDF not attached for order item @id: @message', [
          '@id' => (string) $order_item_id,
          '@message' => $e->getMessage(),
          'order_id' => $orderId,
          'order_item_id' => $order_item_id,
        ]);
      }
    }

    return $attachments;
  }

}
