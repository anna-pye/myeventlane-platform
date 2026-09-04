<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Entity\VenueLocation;

/**
 * Finds conservative, access-aware duplicates before a venue is created.
 */
final class VenueDuplicateGuard {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VenueAccessResolver $accessResolver,
    private readonly VenueCandidateScorer $scorer,
  ) {}

  /**
   * Returns an accessible duplicate, without exposing private venue records.
   */
  public function findDuplicate(
    string $name,
    string $address,
    ?float $latitude = NULL,
    ?float $longitude = NULL,
    ?int $excludeVenueId = NULL,
    ?string $sourceId = NULL,
    ?AccountInterface $account = NULL,
  ): ?Venue {
    $source_id = trim((string) $sourceId);
    $normalized_name = $this->scorer->normalize($name);
    foreach ($this->accessResolver->getAccessibleVenues($account) as $venue) {
      if (!$venue instanceof Venue) {
        continue;
      }
      if ($excludeVenueId !== NULL && (int) $venue->id() === $excludeVenueId) {
        continue;
      }
      if ($source_id !== ''
        && $venue->hasField('enrichment_source_id')
        && trim((string) $venue->get('enrichment_source_id')->value) === $source_id) {
        return $venue;
      }
      if ($normalized_name !== $this->scorer->normalize($venue->getName())) {
        continue;
      }

      $location = $this->primaryLocation($venue);
      $venue_address = $venue->hasField('primary_address')
        ? trim((string) $venue->get('primary_address')->value)
        : '';
      if ($venue_address === '') {
        $venue_address = $location?->getAddressText() ?? '';
      }
      if ($this->scorer->isDuplicate($name, $address, $latitude, $longitude, [
        'name' => $venue->getName(),
        'address' => $venue_address,
        'latitude' => $location?->getLatitude(),
        'longitude' => $location?->getLongitude(),
      ])) {
        return $venue;
      }
    }

    return NULL;
  }

  /**
   * Loads the preferred location without depending on VenueManager.
   */
  private function primaryLocation(Venue $venue): ?VenueLocation {
    $storage = $this->entityTypeManager->getStorage('myeventlane_venue_location');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('venue_id', $venue->id())
      ->sort('is_primary', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $location = $storage->load(reset($ids));
    return $location instanceof VenueLocation ? $location : NULL;
  }

}
