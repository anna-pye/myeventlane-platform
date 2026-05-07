<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Session\AccountInterface;

/**
 * PHP gate for MELObservabilitySystem diagnostics (surface + permission).
 */
final class MelObservabilityDiagnosticsAccess {

  public const PERMISSION = 'access mel observability diagnostics';

  /**
   * Whether full observability payloads, panels, and client hints may be built.
   */
  public function mayViewDiagnostics(MelSurfaceId $surface, AccountInterface $account): bool {
    if ($surface !== MelSurfaceId::Staff) {
      return FALSE;
    }
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    return $account->hasPermission(self::PERMISSION);
  }

}
