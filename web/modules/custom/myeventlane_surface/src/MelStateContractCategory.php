<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical contract grouping for MELStateSystem registry entries.
 */
enum MelStateContractCategory: string {

  case AccountReadinessCustomer = 'account_readiness_customer';

  case AccountReadinessVendor = 'account_readiness_vendor';

  case EventLifecycle = 'event_lifecycle';

  case Checkout = 'checkout';

  case Trust = 'trust';

  case FeatureEligibility = 'feature_eligibility';

}
