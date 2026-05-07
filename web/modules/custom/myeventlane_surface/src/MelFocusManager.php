<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Focus orchestration hints for progressive enhancement (behaviour completed in JS).
 *
 * Does not manipulate the DOM — exposes stable data attributes and IDs only.
 */
final class MelFocusManager {

  /**
   * @return array<string, string>
   */
  public function modalFocusHints(string $dialog_id): array {
    return [
      'trap_region_id' => $dialog_id,
      'restore_strategy' => 'previous_focus',
    ];
  }

  /**
   * @return array<string, string>
   */
  public function disclosureFocusHints(string $toggle_id, string $panel_id): array {
    return [
      'toggle_id' => $toggle_id,
      'panel_id' => $panel_id,
      'restore_strategy' => 'toggle',
    ];
  }

  /**
   * @return array<string, string>
   */
  public function notificationFocusHints(bool $steal_focus): array {
    return [
      'steal_focus' => $steal_focus ? 'true' : 'false',
      'restore_strategy' => $steal_focus ? 'previous_focus' : 'none',
    ];
  }

}
