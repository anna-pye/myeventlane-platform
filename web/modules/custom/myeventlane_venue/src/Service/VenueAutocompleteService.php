<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Service for venue autocomplete functionality.
 *
 * Provides vendor-filtered venue search and creation.
 */
class VenueAutocompleteService {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a VenueAutocompleteService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Gets venues accessible by the current user's vendor.
   *
   * @param string $string
   *   The search string.
   *
   * @return array
   *   Array of venue entities keyed by ID.
   */
  public function getAccessibleVenues(string $string = ''): array {
    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_venue');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor', $vendor->id())
      ->sort('name', 'ASC');

    if (!empty($string)) {
      $query->condition('name', $string, 'CONTAINS');
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * Gets autocomplete suggestions for venues.
   *
   * @param string $string
   *   The search string.
   *
   * @return array
   *   Array of autocomplete suggestions in format expected by autocomplete.
   */
  public function getAutocompleteSuggestions(string $string = ''): array {
    $venues = $this->getAccessibleVenues($string);
    $suggestions = [];

    foreach ($venues as $venue) {
      $suggestions[] = [
        'value' => $venue->getName() . ' (' . $venue->id() . ')',
        'label' => $venue->getName(),
        'venue_id' => $venue->id(),
        'venue' => $venue,
      ];
    }

    return $suggestions;
  }

  /**
   * Creates a new venue from address data.
   *
   * @param string $name
   *   The venue name.
   * @param array $address_data
   *   Address field data.
   * @param float|null $latitude
   *   Latitude coordinate.
   * @param float|null $longitude
   *   Longitude coordinate.
   * @param string|null $place_id
   *   Place ID from Google/Apple Maps.
   *
   * @return \Drupal\myeventlane_venue\Entity\Venue|null
   *   The created venue, or NULL on failure.
   */
  public function createVenueFromAddress(
    string $name,
    array $address_data = [],
    ?float $latitude = NULL,
    ?float $longitude = NULL,
    ?string $place_id = NULL,
  ) {
    $vendor = $this->getCurrentUserVendor();
    if (!$vendor) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_venue');
    $venue = $storage->create([
      'name' => $name,
      'field_vendor' => $vendor->id(),
      'field_location' => $address_data,
      'field_latitude' => $latitude,
      'field_longitude' => $longitude,
      'field_place_id' => $place_id,
    ]);

    try {
      $venue->save();
      return $venue;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('myeventlane_venue')->error('Failed to create venue: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Gets the current user's vendor.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  protected function getCurrentUserVendor(): ?Vendor {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1);

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }

    $vendor = $storage->load(reset($ids));
    return $vendor instanceof Vendor ? $vendor : NULL;
  }

}
