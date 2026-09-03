<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves organiser-entered event wall-clock values to real instants.
 *
 * MEL stores event datetime values without an offset. They must be interpreted
 * in the event's IANA timezone before they are compared, formatted, or
 * converted to UTC. Drupal's typed datetime object interprets the same raw
 * value as UTC and must not be used for this contract.
 */
final class EventDateTimeResolver {

  public const FALLBACK_TIMEZONE = 'Australia/Sydney';

  /**
   * Supported organiser-facing Australian timezone choices.
   *
   * The resolver still accepts any valid IANA timezone already stored on an
   * event. This list is deliberately an interface allow-list, not a parser
   * restriction.
   *
   * @var array<string, string>
   */
  public const AUSTRALIAN_TIMEZONES = [
    'Australia/Sydney' => 'New South Wales / ACT',
    'Australia/Melbourne' => 'Victoria',
    'Australia/Hobart' => 'Tasmania',
    'Australia/Brisbane' => 'Queensland',
    'Australia/Adelaide' => 'South Australia',
    'Australia/Darwin' => 'Northern Territory',
    'Australia/Perth' => 'Western Australia',
    'Australia/Eucla' => 'Eucla area',
    'Australia/Broken_Hill' => 'Broken Hill',
    'Australia/Lord_Howe' => 'Lord Howe Island',
  ];

  /**
   * State and territory fallback mapping for legacy events without a timezone.
   *
   * @var array<string, string>
   */
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
   * Maximum legal UTC offset used for timezone-neutral query windows.
   */
  private const MAX_OFFSET_SECONDS = 14 * 3600;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves the event timezone.
   *
   * Location and site defaults are used for legacy data.
   */
  public function getTimezoneId(?NodeInterface $event = NULL): string {
    $explicit = $this->getExplicitTimezone($event);
    if ($explicit !== NULL) {
      return $explicit;
    }

    $fromLocation = $this->getLocationTimezone($event);
    if ($fromLocation !== NULL) {
      return $fromLocation;
    }

    $configured = trim((string) $this->configFactory
      ->get('system.date')
      ->get('timezone.default'));

    return $this->isValidTimezone($configured)
      ? $configured
      : self::FALLBACK_TIMEZONE;
  }

  /**
   * Parses a raw MEL wall-clock value in the event timezone.
   */
  public function parseValue(string $value, ?NodeInterface $event = NULL): ?\DateTimeImmutable {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    $timezone = new \DateTimeZone($this->getTimezoneId($event));
    foreach (['Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d H:i:s'] as $format) {
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
      $errors = \DateTimeImmutable::getLastErrors();
      $valid = $date !== FALSE
        && ($errors === FALSE || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format($format) === $value;
      if ($valid) {
        return $date;
      }
    }

    return NULL;
  }

  /**
   * Resolves an event datetime field using the wall-clock contract.
   */
  public function getFieldDateTime(NodeInterface $event, string $fieldName): ?\DateTimeImmutable {
    if (!$event->hasField($fieldName) || $event->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $values = $event->get($fieldName)->getValue();
    return $this->parseValue((string) ($values[0]['value'] ?? ''), $event);
  }

  /**
   * Resolves an event datetime field to its real Unix timestamp.
   */
  public function getFieldTimestamp(NodeInterface $event, string $fieldName): ?int {
    return $this->getFieldDateTime($event, $fieldName)?->getTimestamp();
  }

  /**
   * Formats an event datetime field in its own timezone.
   */
  public function formatField(NodeInterface $event, string $fieldName, string $format): ?string {
    return $this->getFieldDateTime($event, $fieldName)?->format($format);
  }

  /**
   * Converts an event datetime field to a UTC iCalendar value.
   */
  public function formatFieldForIcalendar(NodeInterface $event, string $fieldName): ?string {
    $date = $this->getFieldDateTime($event, $fieldName);
    if ($date === NULL) {
      return NULL;
    }

    return $date
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('Ymd\\THis\\Z');
  }

  /**
   * Whether an event field's real instant falls inside an inclusive window.
   */
  public function fieldIsWithin(NodeInterface $event, string $fieldName, int $start, int $end): bool {
    $timestamp = $this->getFieldTimestamp($event, $fieldName);
    return $timestamp !== NULL && $timestamp >= $start && $timestamp <= $end;
  }

  /**
   * Returns broad storage bounds safe for later per-event timezone filtering.
   *
   * @return array{0: string, 1: string}
   *   The earliest and latest storage values.
   */
  public function getBroadStorageBounds(int $start, int $end): array {
    return [
      gmdate('Y-m-d\\TH:i:s', $start - self::MAX_OFFSET_SECONDS),
      gmdate('Y-m-d\\TH:i:s', $end + self::MAX_OFFSET_SECONDS),
    ];
  }

  /**
   * Gets an explicitly saved event timezone.
   */
  private function getExplicitTimezone(?NodeInterface $event): ?string {
    if (!$event instanceof NodeInterface
      || !$event->hasField('field_series_timezone')
      || $event->get('field_series_timezone')->isEmpty()) {
      return NULL;
    }

    $values = $event->get('field_series_timezone')->getValue();
    $timezone = trim((string) ($values[0]['value'] ?? ''));
    return $this->isValidTimezone($timezone) ? $timezone : NULL;
  }

  /**
   * Infers a legacy event timezone from its Australian state or territory.
   */
  private function getLocationTimezone(?NodeInterface $event): ?string {
    if (!$event instanceof NodeInterface
      || !$event->hasField('field_location')
      || $event->get('field_location')->isEmpty()) {
      return NULL;
    }

    $rows = $event->get('field_location')->getValue();
    $area = strtoupper(trim((string) ($rows[0]['administrative_area'] ?? '')));
    return self::ADMINISTRATIVE_AREA_TIMEZONES[$area] ?? NULL;
  }

  /**
   * Determines whether a timezone identifier can be constructed.
   */
  private function isValidTimezone(string $timezone): bool {
    if ($timezone === '') {
      return FALSE;
    }

    try {
      new \DateTimeZone($timezone);
      return TRUE;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

}
