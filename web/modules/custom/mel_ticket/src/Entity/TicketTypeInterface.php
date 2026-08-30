<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Entity;

use Drupal\commerce_price\Price;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * Contract for MEL ticket type entities.
 */
interface TicketTypeInterface extends ContentEntityInterface, EntityPublishedInterface {

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
   * Whether this is a private setup used to create fresh event tickets.
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
