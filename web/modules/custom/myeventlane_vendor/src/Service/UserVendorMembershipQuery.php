<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Lists vendor entity IDs associated with a user account and related event scope.
 *
 * Single source for "user ↔ vendor" membership queries (owner uid + field_vendor_users),
 * and for event nodes the user manages as author or via {@see getManagedEventNodeIds()}.
 * Used by post-login routing, vendor KPIs, and ticket/RSVP aggregation — do not duplicate.
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

  /**
   * Event node IDs for events the user manages (author or vendor team member).
   *
   * When $publishedOnly is TRUE, only published events are returned (dashboard KPI parity).
   * When FALSE, draft/unpublished events are included (e.g. payout history for past sales).
   *
   * @param int $uid
   *   The user ID.
   * @param bool $publishedOnly
   *   TRUE to restrict to published nodes (status = 1).
   *
   * @return list<int>
   *   Event node IDs.
   */
  public function getManagedEventNodeIds(int $uid, bool $publishedOnly): array {
    if ($uid <= 0) {
      return [];
    }

    try {
      $vendorIds = $this->getVendorIdsForUser($uid);
      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event');
      if ($publishedOnly) {
        $query->condition('status', 1);
      }
      $or = $query->orConditionGroup();
      $or->condition('uid', $uid);
      if ($vendorIds !== []) {
        $or->condition('field_event_vendor', $vendorIds, 'IN');
      }
      $query->condition($or);
      $ids = $query->execute();
      if (empty($ids)) {
        return [];
      }
      return array_map('intval', array_values($ids));
    }
    catch (\Exception) {
      return [];
    }
  }

}
