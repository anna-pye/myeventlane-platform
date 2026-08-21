<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Access;

use Drupal\Core\Access\AccessResult;

/**
 * Hides the legacy MEL transfer workflow when direct-charge mode is enabled.
 */
final class LegacyPayoutAccess {

  public static function access(): AccessResult {
    $config = \Drupal::config('myeventlane_core.settings');
    return AccessResult::forbiddenIf((bool) $config->get('direct_charge_enabled'))
      ->addCacheableDependency($config);
  }

}
