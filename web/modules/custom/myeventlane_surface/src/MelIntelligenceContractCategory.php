<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical intelligence contract groupings (orchestration metadata only).
 */
enum MelIntelligenceContractCategory: string {

  case CustomerGuidance = 'customer_guidance';

  case VendorGuidance = 'vendor_guidance';

  case CheckoutGuidance = 'checkout_guidance';

  case LifecycleGuidance = 'lifecycle_guidance';

  case EngagementGuidance = 'engagement_guidance';

  case StaffDiagnostic = 'staff_diagnostic';

}
