<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Aligns reporting routes with Platform Control Centre access.
 *
 * Staff granted "access myeventlane platform control centre" can open reporting
 * screens without separately assigning "view myeventlane reports" / "view admin reports".
 * Those granular permissions still grant access on their own (e.g. finance-only roles).
 */
final class PlatformControlReportingAccess {

  /**
   * Access for the main Reports tab (/admin/myeventlane/reports).
   */
  public static function accessPlatformReportsTab(AccountInterface $account): AccessResult {
    return static::pccOr($account, 'view myeventlane reports');
  }

  /**
   * Access for myeventlane_reporting admin sections (vendors/events/finance/overview).
   */
  public static function accessAdminReportingConsole(AccountInterface $account): AccessResult {
    return static::pccOr($account, 'view admin reports');
  }

  private static function pccOr(AccountInterface $account, string $fallbackPermission): AccessResult {
    if ($account->hasPermission('access myeventlane platform control centre')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($account->hasPermission($fallbackPermission)) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

}
