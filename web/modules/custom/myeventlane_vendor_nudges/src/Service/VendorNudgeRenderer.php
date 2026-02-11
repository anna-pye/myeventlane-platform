<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_nudges\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface;

/**
 * Renders vendor nudges for the dashboard.
 *
 * Builds the complete render array including the nudge container,
 * individual nudge cards, and dismiss UI. Dismiss is local only —
 * no server-side tracking or storage is involved.
 */
final class VendorNudgeRenderer {

  use StringTranslationTrait;

  /**
   * Builds a render array for the given nudges.
   *
   * @param \Drupal\myeventlane_vendor_nudges\Nudge\VendorNudgeInterface[] $nudges
   *   An array of applicable nudge instances.
   *
   * @return array
   *   A Drupal render array using the 'vendor_nudges' theme hook,
   *   or an empty array if no nudges are provided.
   */
  public function build(array $nudges): array {
    if (empty($nudges)) {
      return [];
    }

    $items = [];
    foreach ($nudges as $nudge) {
      if (!$nudge instanceof VendorNudgeInterface) {
        continue;
      }

      $rendered = $nudge->render();
      $items[] = [
        'id' => $nudge->id(),
        'label' => $nudge->label(),
        'message' => $rendered['message'] ?? '',
        'learn_more_url' => $rendered['learn_more_url'] ?? NULL,
      ];
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#theme' => 'vendor_nudges',
      '#nudges' => $items,
      '#heading' => $this->t('Helpful tips'),
      '#attached' => [
        'library' => ['myeventlane_vendor_nudges/vendor-nudges'],
      ],
      '#cache' => ['max-age' => 0],
      '#weight' => -10,
    ];
  }

}
