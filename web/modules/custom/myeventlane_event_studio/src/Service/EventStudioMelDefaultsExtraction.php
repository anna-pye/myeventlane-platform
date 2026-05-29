<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Render\Element;

/**
 * Extracts #default_value trees from Form API mel elements (baseline contract).
 */
final class EventStudioMelDefaultsExtraction {

  /**
   * @param array<string, mixed> $element
   *
   * @return mixed
   */
  public static function extractDefaultsRecursive(array $element): mixed {
    if (array_key_exists('#default_value', $element)) {
      return $element['#default_value'];
    }
    $children = Element::children($element);
    if ($children === []) {
      return NULL;
    }
    $out = [];
    foreach ($children as $key) {
      $child = $element[$key];
      if (!is_array($child)) {
        continue;
      }
      $out[$key] = self::extractDefaultsRecursive($child);
    }
    return $out;
  }

}
