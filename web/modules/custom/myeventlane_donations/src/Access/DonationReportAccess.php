<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access for admin donation reporting (not CSV export).
 *
 * Allows Platform Control Centre staff to view donation metrics without
 * full "administer myeventlane donations" (settings/export remain restricted).
 */
final class DonationReportAccess {

  /**
   * View the donation reports dashboard (platform + RSVP breakdowns).
   */
  public static function accessReport(AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer myeventlane donations')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($account->hasPermission('access myeventlane platform control centre')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

}
