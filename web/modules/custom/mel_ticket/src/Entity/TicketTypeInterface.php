<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Entity;

use Drupal\commerce_price\Price;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Contract for MEL ticket type entities.
 */
interface TicketTypeInterface extends ContentEntityInterface {

  /**
   * Human-visible ticket name.
   */
  public function getTitle(): string;

  /**
   * One of: rsvp, paid, external.
   */
  public function getTicketKind(): string;

  /**
   * Whether the ticket may be attached to multiple events.
   */
  public function isReusable(): bool;

  /**
   * Builds a Commerce Price object from the stored price field, if any.
   */
  public function toPriceValue(): ?Price;

  /**
   * External URL for ticket_kind external (URI string).
   */
  public function getExternalUrlString(): ?string;

}
