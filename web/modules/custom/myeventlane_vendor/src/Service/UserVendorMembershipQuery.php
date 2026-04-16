<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Lists vendor entity IDs associated with a user account.
 *
 * Single source for "user ↔ vendor" membership queries (owner uid + field_vendor_users).
 * Used by post-login routing and vendor entry controllers — do not duplicate queries.
 */
final class UserVendorMembershipQuery {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns vendor IDs where the user is owner (uid) or listed in field_vendor_users.
   *
   * @return list<int>
   */
  public function getVendorIdsForUser(int $uid): array {
    if ($uid <= 0 || !$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');

    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->execute();

    $users_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->execute();

    $all_ids = array_merge($owner_ids ?: [], $users_ids ?: []);

    return array_values(array_unique(array_map('intval', $all_ids)));
  }

}
