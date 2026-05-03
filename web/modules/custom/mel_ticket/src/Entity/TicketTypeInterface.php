<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Entity;

use Drupal\commerce_price\Price;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Contract for MEL ticket type entities.
 */
interface TicketTypeInterface extends ContentEntityInterface {

  public const LIFECYCLE_ACTIVE = 'active';

  public const LIFECYCLE_ARCHIVED = 'archived';

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

  /**
   * Explicit lifecycle state for this ticket type.
   */
  public function getLifecycleStatus(): string;

  /**
   * Whether this ticket type has been archived.
   */
  public function isArchived(): bool;

  /**
   * Whether this ticket type is the organiser-recommended default for its event.
   */
  public function isDefaultTicket(): bool;

  /**
   * Whether this ticket type is marked as the organiser-controlled best value.
   */
  public function isBestValueTicket(): bool;

}
