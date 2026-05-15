<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Staff-only access for the venue operations workspace route.
 *
 * Vendors and customers are denied because they do not receive the restricted
 * workspace permission; anonymous users are always denied.
 */
final class OperationalWorkspaceAccessChecker implements AccessInterface {

  /**
   * Access callback for the canonical venue operations workspace route.
   */
  public function accessWorkspace(AccountInterface $account): AccessResultInterface {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    if (!$account->hasPermission('view mel venue operations workspace')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    return AccessResult::allowed()->cachePerPermissions();
  }

}
