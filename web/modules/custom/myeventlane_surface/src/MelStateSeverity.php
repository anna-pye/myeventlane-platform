<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Interpretation severity for UX prioritisation (not access control).
 */
enum MelStateSeverity: string {

  case Info = 'info';

  case Low = 'low';

  case Medium = 'medium';

  case High = 'high';

  case Critical = 'critical';

}
