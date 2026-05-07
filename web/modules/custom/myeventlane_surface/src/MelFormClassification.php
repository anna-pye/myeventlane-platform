<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Product-level form UX classification for MELFormSystem governance.
 *
 * Distinct from MelSurfaceId: checkout/support can appear on public/customer
 * routes while borrowing trust-dense or operational presentation rules.
 */
enum MelFormClassification: string {

  case Auth = 'auth';

  case Customer = 'customer';

  case Vendor = 'vendor';

  case Checkout = 'checkout';

  case Admin = 'admin';

  case Support = 'support';

}
