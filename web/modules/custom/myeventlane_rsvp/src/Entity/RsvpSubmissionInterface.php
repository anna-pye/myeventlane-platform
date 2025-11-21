<?php

namespace Drupal\myeventlane_rsvp\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for RSVP submissions.
 */
interface RsvpSubmissionInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the RSVP status.
   *
   * @return string
   *   The status value.
   */
  public function getStatus(): string;

  /**
   * Sets the RSVP status.
   *
   * @param string $status
   *   The new status.
   *
   * @return $this
   */
  public function setStatus(string $status): static;

  /**
   * Gets the attendee name.
   *
   * @return string|null
   *   The attendee name.
   */
  public function getAttendeeName(): ?string;

  /**
   * Sets the attendee name.
   *
   * @param string $name
   *   The attendee name.
   *
   * @return $this
   */
  public function setAttendeeName(string $name): static;

  /**
   * Gets the related event node ID.
   *
   * @return int|null
   *   The event node ID.
   */
  public function getEventId(): ?int;

  /**
   * Sets the related event node ID.
   *
   * @param int $nid
   *   The event node ID.
   *
   * @return $this
   */
  public function setEventId(int $nid): static;

}