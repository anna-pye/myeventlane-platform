<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\node\NodeInterface;

/**
 * Resolves IANA timezone for ticket sale-window comparisons on an event node.
 */
final class EventTimezoneResolver {

  /**
   * Returns a valid DateTimeZone identifier for the event, or site default.
   */
  public static function getTimezoneId(?NodeInterface $event): string {
    if ($event instanceof NodeInterface
      && $event->hasField('field_series_timezone')
      && !$event->get('field_series_timezone')->isEmpty()) {
      $tz = trim((string) $event->get('field_series_timezone')->value);
      if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), TRUE)) {
        return $tz;
      }
    }
    return date_default_timezone_get();
  }

}
