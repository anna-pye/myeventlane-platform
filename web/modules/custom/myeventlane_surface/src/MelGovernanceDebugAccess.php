<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Session\AccountInterface;

/**
 * Staff-only gate for governance debug attachments (not observability diagnostics).
 */
final class MelGovernanceDebugAccess {

  public const PERMISSION = 'access mel governance debug';

  /**
   * Whether explainability-safe governance debug hints may be attached to the page.
   */
  public function mayViewDebug(MelSurfaceId $surface, AccountInterface $account): bool {
    if ($surface !== MelSurfaceId::Staff) {
      return FALSE;
    }
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    return $account->hasPermission(self::PERMISSION);
  }

}
