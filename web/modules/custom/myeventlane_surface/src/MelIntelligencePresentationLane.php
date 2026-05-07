<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Where and how intelligence may appear (UX lane, not access).
 */
enum MelIntelligencePresentationLane: string {

  /**
   * Standard guided prompts for authenticated product shells.
   */
  case Guidance = 'guidance';

  /**
   * Minimal, trust-sensitive checkout lane (low density).
   */
  case CheckoutMinimal = 'checkout_minimal';

  /**
   * Discovery-oriented public hints (non-invasive).
   */
  case PublicDiscovery = 'public_discovery';

  /**
   * Staff-only diagnostic summaries (no public recommendation semantics).
   */
  case StaffDiagnostic = 'staff_diagnostic';

}
