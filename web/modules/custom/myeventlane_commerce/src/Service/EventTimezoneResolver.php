<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\node\NodeInterface;

/**
 * Resolves IANA timezone for ticket sale-window comparisons on an event node.
 */
final class EventTimezoneResolver {

  private const ADMINISTRATIVE_AREA_TIMEZONES = [
    'ACT' => 'Australia/Sydney',
    'NSW' => 'Australia/Sydney',
    'VIC' => 'Australia/Melbourne',
    'TAS' => 'Australia/Hobart',
    'QLD' => 'Australia/Brisbane',
    'SA' => 'Australia/Adelaide',
    'NT' => 'Australia/Darwin',
    'WA' => 'Australia/Perth',
  ];

  /**
   * Returns a valid DateTimeZone identifier for the event, or site default.
   */
  public static function getTimezoneId(?NodeInterface $event): string {
    if ($event instanceof NodeInterface
      && $event->hasField('field_series_timezone')
      && !$event->get('field_series_timezone')->isEmpty()) {
      $values = $event->get('field_series_timezone')->getValue();
      $tz = trim((string) ($values[0]['value'] ?? ''));
      if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), TRUE)) {
        return $tz;
      }
    }

    if ($event instanceof NodeInterface
      && $event->hasField('field_location')
      && !$event->get('field_location')->isEmpty()) {
      $rows = $event->get('field_location')->getValue();
      $area = strtoupper(trim((string) ($rows[0]['administrative_area'] ?? '')));
      if (isset(self::ADMINISTRATIVE_AREA_TIMEZONES[$area])) {
        return self::ADMINISTRATIVE_AREA_TIMEZONES[$area];
      }
    }
    return EventDateTimeResolver::FALLBACK_TIMEZONE;
  }

  /**
   * Parses a MEL wall-clock value in the event timezone.
   */
  public static function parseWallClock(string $value, ?NodeInterface $event): ?\DateTimeImmutable {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    $timezone = new \DateTimeZone(self::getTimezoneId($event));
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $value, $timezone);
    $errors = \DateTimeImmutable::getLastErrors();
    if ($date === FALSE
      || ($errors !== FALSE && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
      || $date->format('Y-m-d\\TH:i:s') !== $value) {
      return NULL;
    }

    return $date;
  }

}
