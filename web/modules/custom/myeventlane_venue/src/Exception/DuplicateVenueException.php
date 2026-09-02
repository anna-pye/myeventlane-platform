<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Exception;

use Drupal\myeventlane_venue\Entity\Venue;

/**
 * Raised when a venue creation request matches an accessible saved venue.
 */
final class DuplicateVenueException extends \RuntimeException {

  public function __construct(
    private readonly Venue $duplicateVenue,
  ) {
    parent::__construct(sprintf('Venue already exists as "%s".', $duplicateVenue->getName()));
  }

  /**
   * Returns the existing venue the organiser should reuse.
   */
  public function getDuplicateVenue(): Venue {
    return $this->duplicateVenue;
  }

}
