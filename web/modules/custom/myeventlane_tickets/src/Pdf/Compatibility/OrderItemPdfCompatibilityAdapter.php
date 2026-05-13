<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Pdf\Compatibility;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\myeventlane_tickets\Service\TicketPdfEventMetadataHelper;
use Drupal\myeventlane_tickets\Ticket\TicketPdfAttachmentRenderer;

/**
 * Bridges Commerce order-item PDF entry points into shared PDF preparation.
 *
 * Inward-only: normalizes legacy purchase context and delegates rendering to
 * TicketPdfAttachmentRenderer. Does not assert operational entitlement.
 */
final class OrderItemPdfCompatibilityAdapter {

  public function __construct(
    private readonly TicketPdfEventMetadataHelper $eventMetadataHelper,
    private readonly TicketPdfAttachmentRenderer $attachmentRenderer,
  ) {}

  /**
   * @return array{content: string, filename: string, mime: string}
   */
  public function buildAttachment(OrderItemInterface $order_item): array {
    $event = $this->resolveEventForOrderItem($order_item);
    $rows = $this->eventMetadataHelper->legacyPdfRowsForEvent($event);

    $holderName = $this->extractHolderNameFromOrderItem($order_item);

    $ticketCode = sprintf(
      'MEL-%d-%d-%d-%s',
      $event->id(),
      $order_item->getOrder() ? $order_item->getOrder()->id() : 0,
      $order_item->id(),
      strtoupper(substr(md5(uniqid((string) mt_rand(), TRUE)), 0, 6))
    );

    $build = [
      '#theme' => 'ticket_pdf',
      '#order_item' => $order_item,
      '#event_title' => $rows['title'],
      '#event_start' => $rows['start'],
      '#location' => $rows['location'],
      '#holder' => $holderName,
      '#code' => $ticketCode,
      '#legacy' => TRUE,
    ];

    $filename = 'tickets-order-item-' . $order_item->id() . '.pdf';

    return [
      'content' => $this->attachmentRenderer->renderBytes($build),
      'filename' => $filename,
      'mime' => 'application/pdf',
    ];
  }

  private function resolveEventForOrderItem(OrderItemInterface $order_item): EntityInterface {
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
      if ($event) {
        return $event;
      }
    }

    $purchasedEntity = $order_item->getPurchasedEntity();
    if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
      $event = $purchasedEntity->get('field_event')->entity;
      if ($event) {
        return $event;
      }
    }

    if ($purchasedEntity !== NULL && method_exists($purchasedEntity, 'getProduct')) {
      $product = $purchasedEntity->getProduct();
      if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
        $event = $product->get('field_event')->entity;
        if ($event) {
          return $event;
        }
      }
    }

    throw new \LogicException('Event not found for order item.');
  }

  private function extractHolderNameFromOrderItem(OrderItemInterface $order_item): string {
    $holderName = '';
    if ($order_item->hasField('field_ticket_holder') && !$order_item->get('field_ticket_holder')->isEmpty()) {
      $ticketHolders = $order_item->get('field_ticket_holder')->referencedEntities();
      if (!empty($ticketHolders)) {
        $holder = reset($ticketHolders);
        $firstName = $holder->get('field_first_name')->value ?? '';
        $lastName = $holder->get('field_last_name')->value ?? '';
        $holderName = trim($firstName . ' ' . $lastName);
      }
    }
    return $holderName;
  }

}
