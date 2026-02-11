<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Nudge;

use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Defines the interface for vendor educational nudges.
 *
 * Nudges are optional, non-punitive tips shown on vendor dashboards.
 * They must NEVER expose metrics, scores, or comparisons.
 * They must NEVER trigger automation or escalations.
 */
interface VendorNudgeInterface {

  /**
   * Returns the unique machine name for this nudge.
   *
   * @return string
   *   The nudge identifier.
   */
  public function id(): string;

  /**
   * Returns the human-readable label for this nudge.
   *
   * @return string
   *   The nudge label in Australian English.
   */
  public function label(): string;

  /**
   * Determines whether this nudge should be shown to the given vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param array $context
   *   Aggregate context data built by the evaluator. Contains health
   *   signals and aggregate indicators only — never raw metrics.
   *
   * @return bool
   *   TRUE if the nudge applies, FALSE otherwise.
   */
  public function shouldApply(Vendor $vendor, array $context): bool;

  /**
   * Returns a safe render array for this nudge.
   *
   * The returned array must contain:
   * - 'message': (string) Friendly, neutral text in Australian English.
   * - 'learn_more_url': (string|null) Optional URL for further reading.
   *
   * Must NEVER include metrics, counts, scores, or comparisons.
   *
   * @return array
   *   A safe render array with nudge content.
   */
  public function render(): array;

}
