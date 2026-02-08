<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_venue\Entity\Venue;

/**
 * Single source of truth for venue access resolution.
 *
 * Used by access handlers, controllers, and forms to determine access.
 */
class VenueAccessResolver {

  /**
   * Constructs VenueAccessResolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Checks if a user can view a venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check (defaults to current user).
   *
   * @return bool
   *   TRUE if the user can view the venue.
   */
  public function canView(Venue $venue, ?AccountInterface $account = NULL): bool {
    $account = $account ?? $this->currentUser;

    // Admin can view anything.
    if ($account->hasPermission('administer myeventlane venues')) {
      return TRUE;
    }

    // Owner can always view.
    if ((int) $venue->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }

    // Public venues are viewable.
    if ($venue->isPublic()) {
      return $account->hasPermission('access vendor venues');
    }

    // Check for explicit access grant.
    return $this->hasExplicitAccess($venue, $account);
  }

  /**
   * Checks if a user can use a venue (for creating events).
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check (defaults to current user).
   *
   * @return bool
   *   TRUE if the user can use the venue.
   */
  public function canUse(Venue $venue, ?AccountInterface $account = NULL): bool {
    // Using a venue requires view access.
    return $this->canView($venue, $account);
  }

  /**
   * Checks if a user can update a venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check (defaults to current user).
   *
   * @return bool
   *   TRUE if the user can update the venue.
   */
  public function canUpdate(Venue $venue, ?AccountInterface $account = NULL): bool {
    $account = $account ?? $this->currentUser;

    // Admin can update anything.
    if ($account->hasPermission('administer myeventlane venues')) {
      return TRUE;
    }

    // Only owner can update.
    return (int) $venue->getOwnerId() === (int) $account->id();
  }

  /**
   * Checks if the user has explicit access grant.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE if explicit access exists.
   */
  public function hasExplicitAccess(Venue $venue, AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('myeventlane_venue_access');
      $count = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('venue_id', $venue->id())
        ->condition('uid', $account->id())
        ->count()
        ->execute();

      return $count > 0;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Gets venues accessible by a user.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account (defaults to current user).
   *
   * @return \Drupal\myeventlane_venue\Entity\Venue[]
   *   Array of accessible venues.
   */
  public function getAccessibleVenues(?AccountInterface $account = NULL): array {
    $account = $account ?? $this->currentUser;
    $venueStorage = $this->entityTypeManager->getStorage('myeventlane_venue');

    // Admin gets all venues.
    if ($account->hasPermission('administer myeventlane venues')) {
      return $venueStorage->loadMultiple();
    }

    $venueIds = [];

    // 1. Venues owned by user.
    $ownedIds = $venueStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $account->id())
      ->execute();
    $venueIds = array_merge($venueIds, array_values($ownedIds));

    // 2. Public venues.
    $publicIds = $venueStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('visibility', Venue::VISIBILITY_PUBLIC)
      ->execute();
    $venueIds = array_merge($venueIds, array_values($publicIds));

    // 3. Venues with explicit access grants.
    try {
      $accessStorage = $this->entityTypeManager->getStorage('myeventlane_venue_access');
      $accessGrants = $accessStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $account->id())
        ->execute();

      if (!empty($accessGrants)) {
        $grants = $accessStorage->loadMultiple($accessGrants);
        foreach ($grants as $grant) {
          $venueIds[] = $grant->get('venue_id')->target_id;
        }
      }
    }
    catch (\Exception $e) {
      // Access entity type may not exist.
    }

    $venueIds = array_unique(array_filter($venueIds));

    if (empty($venueIds)) {
      return [];
    }

    return $venueStorage->loadMultiple($venueIds);
  }

}
