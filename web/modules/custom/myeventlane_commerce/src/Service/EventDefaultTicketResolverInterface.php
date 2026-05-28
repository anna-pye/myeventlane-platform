<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves the organiser default ticket for customer-facing ticket ordering.
 */
interface EventDefaultTicketResolverInterface {

  /**
   * Resolves the default ticket for an event (same semantics as the book form).
   */
  public function getDefaultTicket(NodeInterface $event): ?TicketTypeInterface;

}
