<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Entity\VenueAccess;
use Drupal\myeventlane_venue\Entity\VenueLocation;
use Drupal\myeventlane_venue\Exception\DuplicateVenueException;
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
    protected VenueDuplicateGuard $duplicateGuard,
    protected LockBackendInterface $lock,
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

    $ids = array_values(array_unique(array_map(
      static fn ($id): int => (int) $id,
      $ids
    )));

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
   * @throws \Drupal\myeventlane_venue\Exception\DuplicateVenueException
   */
  public function createVenueWithLocation(array $venueData, array $locationData, int $ownerId): Venue {
    return $this->guardVenueCreation(
      $venueData,
      $locationData,
      $ownerId,
      fn (): Venue => $this->createVenueWithLocationUnchecked($venueData, $locationData, $ownerId),
    );
  }

  /**
   * Serialises, rechecks and executes a saved-venue creation operation.
   *
   * @param array $venueData
   *   Venue name and optional trusted source identity.
   * @param array $locationData
   *   Address and optional provider coordinates.
   * @param int $ownerId
   *   The organiser account ID.
   * @param callable(): T $create
   *   Operation which persists the venue after the duplicate check.
   *
   * @template T
   *
   * @return T
   *   The operation result.
   */
  public function guardVenueCreation(
    array $venueData,
    array $locationData,
    int $ownerId,
    callable $create,
  ): mixed {
    $lock_name = $this->creationLockName($venueData, $locationData, $ownerId);
    $acquired = $this->lock->acquire($lock_name, 15.0);
    if (!$acquired) {
      $this->lock->wait($lock_name, 5);
      $acquired = $this->lock->acquire($lock_name, 15.0);
    }
    if (!$acquired) {
      throw new \RuntimeException('Another request is creating this venue. Try again.');
    }

    try {
      $this->assertNoDuplicate($venueData, $locationData, $ownerId);
      return $create();
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Rejects an accessible saved venue with the same trusted identity.
   */
  private function assertNoDuplicate(array $venueData, array $locationData, int $ownerId): void {
    $owner = $this->entityTypeManager->getStorage('user')->load($ownerId);
    $duplicate = $this->duplicateGuard->findDuplicate(
      trim((string) ($venueData['name'] ?? '')),
      trim((string) ($locationData['address_text'] ?? '')),
      $this->coordinate($locationData['lat'] ?? NULL, -90.0, 90.0),
      $this->coordinate($locationData['lng'] ?? NULL, -180.0, 180.0),
      NULL,
      isset($venueData['enrichment_source_id']) ? (string) $venueData['enrichment_source_id'] : NULL,
      $owner instanceof AccountInterface ? $owner : NULL,
    );
    if ($duplicate instanceof Venue) {
      throw new DuplicateVenueException($duplicate);
    }
  }

  /**
   * Persists a venue after the guarded duplicate check.
   */
  private function createVenueWithLocationUnchecked(array $venueData, array $locationData, int $ownerId): Venue {
    // Create venue.
    $venue_values = [
      'name' => $venueData['name'],
      'visibility' => $venueData['visibility'] ?? Venue::VISIBILITY_SHARED,
      'description' => $venueData['description'] ?? '',
      'primary_address' => trim((string) ($locationData['address_text'] ?? '')),
      'uid' => $ownerId,
    ];
    foreach ([
      'website',
      'phone',
      'email',
      'facebook',
      'instagram',
      'twitter',
      'linkedin',
      'youtube',
      'tiktok',
      'enrichment_source',
      'enrichment_source_id',
      'enrichment_checked',
      'enrichment_accepted_fields',
      'organiser_verified',
    ] as $field_name) {
      if (array_key_exists($field_name, $venueData) && $venueData[$field_name] !== '') {
        $venue_values[$field_name] = $venueData[$field_name];
      }
    }
    $venue = Venue::create($venue_values);
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
   * Creates a stable lock name for concurrent submissions of the same venue.
   */
  private function creationLockName(array $venueData, array $locationData, int $ownerId): string {
    $source_id = trim((string) ($venueData['enrichment_source_id'] ?? ''));
    $identity = $source_id !== ''
      ? 'source:' . $source_id
      : implode('|', [
        $this->normalizeIdentity((string) ($venueData['name'] ?? '')),
        $this->normalizeIdentity((string) ($locationData['address_text'] ?? '')),
      ]);
    return 'myeventlane_venue:create:' . hash('sha256', $ownerId . '|' . $identity);
  }

  /**
   * Normalises submitted text for the short-lived creation lock.
   */
  private function normalizeIdentity(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Returns a valid submitted coordinate.
   */
  private function coordinate(mixed $value, float $minimum, float $maximum): ?float {
    if (!is_numeric($value)) {
      return NULL;
    }
    $coordinate = (float) $value;
    return $coordinate >= $minimum && $coordinate <= $maximum ? $coordinate : NULL;
  }

  /**
   * Keeps the venue profile address and canonical primary location aligned.
   */
  public function syncPrimaryLocation(
    Venue $venue,
    string $address,
    ?float $latitude = NULL,
    ?float $longitude = NULL,
  ): void {
    $address = trim($address);
    if ($address === '') {
      return;
    }

    $location = $this->getPrimaryLocation($venue);
    if (!$location instanceof VenueLocation) {
      $location = VenueLocation::create([
        'venue_id' => $venue->id(),
        'title' => $venue->getName(),
        'address_text' => $address,
        'lat' => $latitude,
        'lng' => $longitude,
        'is_primary' => TRUE,
      ]);
      $location->save();
      return;
    }

    $address_changed = trim($location->getAddressText()) !== $address;
    $location->set('address_text', $address);
    if ($latitude !== NULL && $longitude !== NULL) {
      $location->set('lat', $latitude);
      $location->set('lng', $longitude);
    }
    elseif ($address_changed) {
      // Do not retain coordinates for an address the organiser changed by
      // hand. A subsequent provider selection can safely restore them.
      $location->set('lat', NULL);
      $location->set('lng', NULL);
    }
    $location->save();
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
