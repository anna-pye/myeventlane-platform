<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Surface-aware UX posture for intelligence orchestration (tone hints only).
 */
enum MelIntelligenceShellProfile: string {

  case CustomerShell = 'customer_shell';

  case VendorShell = 'vendor_shell';

  case Checkout = 'checkout';

  case PublicSurface = 'public_surface';

  case StaffSurface = 'staff_surface';

  case AuthShell = 'auth_shell';

}
