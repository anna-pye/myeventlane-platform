<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

/**
 * Presentation shell for analytics widgets (Chart.js, Recharts, legacy embeds).
 */
final class MelAnalyticsHelper {

  /**
   * Container modifiers for chart shells.
   *
   * @return list<string>
   */
  public function getFrameClasses(MelSurfaceId $surface, ?string $engine = NULL): array {
    $classes = ['mel-analytics-frame'];
    if ($surface === MelSurfaceId::Vendor) {
      $classes[] = 'mel-analytics-frame--dense';
    }
    elseif ($surface === MelSurfaceId::Staff) {
      $classes[] = 'mel-analytics-frame--plain';
    }
    elseif ($surface === MelSurfaceId::Customer || $surface === MelSurfaceId::Public) {
      $classes[] = 'mel-analytics-frame--spacious';
    }
    if ($engine !== NULL && $engine !== '') {
      $safe = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($engine));
      if (is_string($safe) && $safe !== '') {
        $classes[] = 'mel-analytics-frame--engine-' . $safe;
      }
    }
    return $classes;
  }

}
