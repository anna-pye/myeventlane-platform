<?php

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Generates unique ticket codes for order items.
 */
class TicketCodeGenerator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidGenerator;

  /**
   * Constructs a TicketCodeGenerator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_generator
   *   The UUID generator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, UuidInterface $uuid_generator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->uuidGenerator = $uuid_generator;
  }

  /**
   * Creates a ticket code entity for an order item and event.
   *
   * @param object $order_item
   *   The order item entity.
   * @param object $event
   *   The event entity.
   *
   * @return \Drupal\myeventlane_tickets\Entity\TicketCode
   *   The created ticket code entity.
   */
  public function create($order_item, $event) {
    $code = $this->uuidGenerator->generate();

    $storage = $this->entityTypeManager->getStorage('ticket_code');

    $entity = $storage->create([
      'code' => $code,
      'order_item_id' => $order_item->id(),
      'event_id' => $event->id(),
    ]);

    $entity->save();
    return $entity;
  }

  /**
   * Generates a unique ticket code for the new Ticket entity.
   *
   * @return string
   *   A unique ticket code.
   */
  public function generateUniqueTicketCode(): string {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $max_attempts = 10;
    $attempt = 0;

    do {
      // Generate a secure random code: MEL-{timestamp}-{random}.
      $code = 'MEL-' . time() . '-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
      $attempt++;

      // Check if code already exists.
      $existing = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('ticket_code', $code)
        ->range(0, 1)
        ->execute();

      if (empty($existing)) {
        return $code;
      }
    } while ($attempt < $max_attempts);

    // Fallback: use UUID if we can't generate a unique code (very unlikely).
    return 'MEL-' . str_replace('-', '', $this->uuidGenerator->generate());
  }

}
