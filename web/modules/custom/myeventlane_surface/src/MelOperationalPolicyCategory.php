<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical operational policy contract groupings (interpretation only).
 */
enum MelOperationalPolicyCategory: string {

  case Trust = 'trust';

  case Suppression = 'suppression';

  case Automation = 'automation';

  case Communication = 'communication';

  case LifecycleSafety = 'lifecycle_safety';

}
