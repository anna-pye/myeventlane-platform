<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

/**
 * Interface for {@see UserVendorMembershipQuery}.
 */
interface UserVendorMembershipQueryInterface {

  /**
   * Returns vendor IDs where the user is owner or listed in field_vendor_users.
   *
   * @return list<int>
   *   Vendor entity IDs.
   */
  public function getVendorIdsForUser(int $uid): array;

  /**
   * Event node IDs for events the user manages (author or vendor team member).
   *
   * @return list<int>
   *   Event node IDs.
   */
  public function getManagedEventNodeIds(int $uid, bool $publishedOnly): array;

}
