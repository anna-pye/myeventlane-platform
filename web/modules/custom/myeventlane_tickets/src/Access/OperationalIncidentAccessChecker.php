<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Staff-only access for operational incident and recovery read surfaces.
 *
 * Mirrors {@see OperationalWorkspaceAccessChecker}: vendors, customers, and
 * anonymous callers are denied; escalation tooling remains admin/staff scoped.
 */
final class OperationalIncidentAccessChecker implements AccessInterface {

  /**
   * Access callback for incident / recovery workspace capabilities.
   */
  public function accessOperationalIncidentWorkspace(AccountInterface $account): AccessResultInterface {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    if (!$account->hasPermission('view mel venue operations workspace')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    return AccessResult::allowed()->cachePerPermissions();
  }

}
