<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Lists vendor entity IDs associated with a user account and related event scope.
 *
 * Single source for "user ↔ vendor" membership queries (owner uid + field_vendor_users),
 * and for event nodes the user manages as author or via {@see getManagedEventNodeIds()}.
 * Used by post-login routing, vendor KPIs, and ticket/RSVP aggregation — do not duplicate.
 */
final class UserVendorMembershipQuery implements UserVendorMembershipQueryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
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
        // Parity with VendorDashboardController::getUserEvents(): optional legacy
        // event → vendor reference when present on the event bundle.
        $eventFields = $this->entityFieldManager->getFieldDefinitions('node', 'event');
        if (isset($eventFields['field_vendor'])) {
          $or->condition('field_vendor', $vendorIds, 'IN');
        }
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
