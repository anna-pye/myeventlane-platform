<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

/**
 * Product surface identifiers for MEL (not themes).
 */
enum MelSurfaceId: string {

  case Public = 'public';

  case Auth = 'auth';

  case Customer = 'customer';

  case Vendor = 'vendor';

  case Staff = 'staff';

}
