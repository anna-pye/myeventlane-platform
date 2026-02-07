<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Entity\VenueAccess;
use Drupal\myeventlane_venue\Entity\VenueLocation;
use Psr\Log\LoggerInterface;

/**
 * Manages venue operations.
 */
class VenueManager {

  /**
   * Constructs VenueManager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected VenueAccessResolver $accessResolver,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Gets locations for a venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue.
   *
   * @return \Drupal\myeventlane_venue\Entity\VenueLocation[]
   *   Array of venue locations.
   */
  public function getLocations(Venue $venue): array {
    $storage = $this->entityTypeManager->getStorage('myeventlane_venue_location');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('venue_id', $venue->id())
      ->sort('is_primary', 'DESC')
      ->sort('title', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * Gets the primary location for a venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue.
   *
   * @return \Drupal\myeventlane_venue\Entity\VenueLocation|null
   *   The primary location, or NULL.
   */
  public function getPrimaryLocation(Venue $venue): ?VenueLocation {
    $storage = $this->entityTypeManager->getStorage('myeventlane_venue_location');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('venue_id', $venue->id())
      ->condition('is_primary', TRUE)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      // Fall back to first location.
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('venue_id', $venue->id())
        ->range(0, 1)
        ->execute();
    }

    if (empty($ids)) {
      return NULL;
    }

    $location = $storage->load(reset($ids));
    return $location instanceof VenueLocation ? $location : NULL;
  }

  /**
   * Creates a venue with a primary location.
   *
   * @param array $venueData
   *   Venue data: name, visibility, description.
   * @param array $locationData
   *   Location data: title, address_text, lat, lng.
   * @param int $ownerId
   *   The owner user ID.
   *
   * @return \Drupal\myeventlane_venue\Entity\Venue
   *   The created venue.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createVenueWithLocation(array $venueData, array $locationData, int $ownerId): Venue {
    // Create venue.
    $venue = Venue::create([
      'name' => $venueData['name'],
      'visibility' => $venueData['visibility'] ?? Venue::VISIBILITY_SHARED,
      'description' => $venueData['description'] ?? '',
      'uid' => $ownerId,
    ]);
    $venue->save();

    $this->logger->info('Created venue @name (ID: @id) for user @uid', [
      '@name' => $venue->getName(),
      '@id' => $venue->id(),
      '@uid' => $ownerId,
    ]);

    // Create primary location.
    $location = VenueLocation::create([
      'venue_id' => $venue->id(),
      'title' => $locationData['title'] ?? $venueData['name'],
      'address_text' => $locationData['address_text'],
      'lat' => $locationData['lat'] ?? NULL,
      'lng' => $locationData['lng'] ?? NULL,
      'is_primary' => TRUE,
      'notes' => $locationData['notes'] ?? '',
    ]);
    $location->save();

    $this->logger->info('Created primary location @title for venue @venue', [
      '@title' => $location->getTitle(),
      '@venue' => $venue->getName(),
    ]);

    return $venue;
  }

  /**
   * Accepts a share link and grants access.
   *
   * @param string $token
   *   The share token.
   * @param int $uid
   *   The user ID to grant access to.
   *
   * @return \Drupal\myeventlane_venue\Entity\Venue|null
   *   The venue if successful, NULL otherwise.
   */
  public function acceptShareLink(string $token, int $uid): ?Venue {
    $venueStorage = $this->entityTypeManager->getStorage('myeventlane_venue');
    $ids = $venueStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('share_token', $token)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      $this->logger->warning('Share link with invalid token attempted: @token', [
        '@token' => $token,
      ]);
      return NULL;
    }

    $venue = $venueStorage->load(reset($ids));
    if (!$venue instanceof Venue) {
      return NULL;
    }

    // Don't grant access if user is the owner.
    if ((int) $venue->getOwnerId() === $uid) {
      return $venue;
    }

    // Check if access already exists.
    if ($this->accessResolver->hasExplicitAccess($venue, \Drupal::entityTypeManager()->getStorage('user')->load($uid))) {
      return $venue;
    }

    // Create access grant.
    $access = VenueAccess::createGrant($venue, $uid);
    $access->save();

    $this->logger->info('Granted access to venue @venue for user @uid via share link', [
      '@venue' => $venue->getName(),
      '@uid' => $uid,
    ]);

    return $venue;
  }

  /**
   * Gets the location count for a venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue.
   *
   * @return int
   *   The number of locations.
   */
  public function getLocationCount(Venue $venue): int {
    $storage = $this->entityTypeManager->getStorage('myeventlane_venue_location');
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('venue_id', $venue->id())
      ->count()
      ->execute();
  }

}
