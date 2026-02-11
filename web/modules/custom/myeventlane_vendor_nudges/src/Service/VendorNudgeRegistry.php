<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Service;

use Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface;

/**
 * Collects and provides all registered vendor nudges.
 *
 * Nudges are collected via the 'myeventlane_vendor_nudge' service tag
 * using Symfony's tagged iterator. New nudges are automatically
 * discovered when tagged in services.yml.
 */
final class VendorNudgeRegistry {

  /**
   * The collected nudge instances keyed by ID.
   *
   * @var \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface[]
   */
  private array $nudges = [];

  /**
   * Constructs a VendorNudgeRegistry.
   *
   * @param iterable $taggedNudges
   *   An iterable of services tagged with 'myeventlane_vendor_nudge'.
   */
  public function __construct(iterable $taggedNudges) {
    foreach ($taggedNudges as $nudge) {
      if ($nudge instanceof VendorNudgeInterface) {
        $this->nudges[$nudge->id()] = $nudge;
      }
    }
  }

  /**
   * Returns all registered nudges.
   *
   * @return \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface[]
   *   An array of nudge instances keyed by nudge ID.
   */
  public function getNudges(): array {
    return $this->nudges;
  }

  /**
   * Returns a single nudge by ID.
   *
   * @param string $id
   *   The nudge ID.
   *
   * @return \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface|null
   *   The nudge instance, or NULL if not found.
   */
  public function getNudge(string $id): ?VendorNudgeInterface {
    return $this->nudges[$id] ?? NULL;
  }

}
