<?php

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\Uuid;

class TicketCodeGenerator {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $etm) {
    $this->entityTypeManager = $etm;
  }

  public function create($order_item, $event) {
    $code = strtoupper(substr(Uuid::generate(), 0, 10));

    $storage = $this->entityTypeManager->getStorage('ticket_code');

    $entity = $storage->create([
      'code' => $code,
      'order_item_id' => $order_item->id(),
      'event_id' => $event->id(),
    ]);

    $entity->save();
    return $entity;
  }

}