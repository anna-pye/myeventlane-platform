<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Visibility tier for governed traces (not Drupal permissions — UX envelope).
 */
enum MelObservabilityVisibilityLevel: int {

  case None = 0;

  case Minimal = 1;

  case Operational = 2;

  case Diagnostic = 3;

}
