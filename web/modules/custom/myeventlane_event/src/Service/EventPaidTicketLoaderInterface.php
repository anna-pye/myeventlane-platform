<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\node\NodeInterface;

/**
 * Loads ordered ticket type entities attached to an event.
 */
interface EventPaidTicketLoaderInterface {

  /**
   * @return list<\Drupal\mel_ticket\Entity\TicketTypeInterface>
   */
  public function loadOrderedTicketsForEvent(NodeInterface $event): array;

}
