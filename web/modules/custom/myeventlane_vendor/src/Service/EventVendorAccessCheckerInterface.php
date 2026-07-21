<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Canonical per-event organiser ownership API (workspace parity).
 *
 * @see \Drupal\myeventlane_vendor\Service\EventVendorAccessChecker
 * @see docs/adr/ADR-0008-canonical-event-ownership.md
 */
interface EventVendorAccessCheckerInterface {

  /**
   * TRUE when the account is the event author or linked organiser manager.
   *
   * Includes organiser entity owner (vendor uid) and field_vendor_users.
   * Does not grant staff/admin bypass — callers apply named permissions.
   */
  public function accountHasWorkspaceParityForEvent(NodeInterface $event, AccountInterface $account): bool;

}
