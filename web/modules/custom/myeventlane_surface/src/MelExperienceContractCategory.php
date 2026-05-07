<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Continuity contract grouping for the canonical experience registry.
 */
enum MelExperienceContractCategory: string {

  case AuthContinuity = 'auth_continuity';

  case CustomerJourney = 'customer_journey';

  case VendorJourney = 'vendor_journey';

  case CheckoutContinuity = 'checkout_continuity';

  case EscalationContinuity = 'escalation_continuity';

}
