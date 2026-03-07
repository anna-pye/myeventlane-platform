<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access callback that blocks vendors from Workspaces admin UI routes.
 */
final class WorkspaceUiAccess {

  /**
   * Blocks vendor role from Workspaces admin pages.
   */
  public static function access(AccountInterface $account): AccessResult {
    if ($account->id() === 1 || $account->hasPermission('administer workspaces') || $account->hasPermission('administer site configuration')) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheContexts(['user.roles']);
    }

    if (in_array('vendor', $account->getRoles(), TRUE)) {
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheContexts(['user.roles']);
    }

    return AccessResult::neutral()
      ->addCacheContexts(['user.roles']);
  }

}
