<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

use Drupal\Core\Session\AccountInterface;

/**
 * Shared trust rule for vendor console HTTP access (non-admin bypass paths).
 *
 * Matches: "access vendor console" permission OR organiser (vendor) user role.
 *
 * @see MelVendorOrganiserRole::MACHINE_NAME
 * @see \Drupal\myeventlane_vendor\Access\VendorConsoleAccess::access()
 * @see \Drupal\myeventlane_core\EventSubscriber\VendorConsoleGateRedirectSubscriber::onRequest()
 * @see \Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController::assertVendorAccess()
 */
final class VendorConsoleTrust {

  private function __construct() {}

  /**
   * Whether the account is treated like a vendor console user (permission or role).
   */
  public static function accountIsTrustedForVendorConsole(AccountInterface $account): bool {
    if ($account->isAnonymous()) {
      return FALSE;
    }
    return $account->hasPermission('access vendor console')
      || $account->hasRole(MelVendorOrganiserRole::MACHINE_NAME);
  }

}
