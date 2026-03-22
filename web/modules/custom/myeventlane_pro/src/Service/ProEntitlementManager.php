<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Checks Pro entitlement for platform users.
 */
final class ProEntitlementManager {

  /**
   * The role granting Pro entitlement.
   */
  private const PRO_ROLE = 'mel_pro';

  /**
   * Determines whether the account is currently Pro.
   */
  public function isPro(AccountInterface $account): bool {
    return in_array(self::PRO_ROLE, $account->getRoles(), TRUE);
  }

}

