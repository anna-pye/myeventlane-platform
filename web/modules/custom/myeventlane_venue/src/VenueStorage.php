<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Custom storage handler for Venue entities.
 */
class VenueStorage extends SqlContentEntityStorage {

  /**
   * Checks if a venue is referenced by any Event.
   *
   * @param int $venue_id
   *   The venue entity ID.
   *
   * @return bool
   *   TRUE if the venue is referenced by at least one event, FALSE otherwise.
   */
  public function venueIsReferenced(int $venue_id): bool {
    // Query node__field_venue table for references to this venue.
    $query = $this->database->select('node__field_venue', 'nv');
    $query->addField('nv', 'entity_id');
    $query->condition('nv.field_venue_target_id', $venue_id);
    $query->range(0, 1);

    $result = $query->execute()->fetchField();

    return !empty($result);
  }

}
