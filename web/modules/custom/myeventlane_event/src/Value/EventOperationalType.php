<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Value;

/**
 * Defines future-facing operational event type identifiers.
 *
 * These constants are metadata contracts only. They do not change existing
 * field_event_type storage values or booking behavior.
 */
final class EventOperationalType {

  public const RSVP = 'rsvp';
  public const TICKETED = 'ticketed';
  public const HYBRID = 'hybrid';
  public const SEATED = 'seated';
  public const FESTIVAL = 'festival';
  public const DIGITAL = 'digital';
  public const EXTERNAL = 'external';

  /**
   * Returns canonical metadata for future Studio decisions.
   *
   * @return array<string, array<string, mixed>>
   */
  public static function definitions(): array {
    return [
      self::RSVP => [
        'label' => 'RSVP event',
        'commerce_required' => FALSE,
        'attendance_access' => TRUE,
        'seating_required' => FALSE,
      ],
      self::TICKETED => [
        'label' => 'Ticketed event',
        'commerce_required' => TRUE,
        'attendance_access' => TRUE,
        'seating_required' => FALSE,
      ],
      self::HYBRID => [
        'label' => 'Hybrid event',
        'commerce_required' => TRUE,
        'attendance_access' => TRUE,
        'seating_required' => FALSE,
      ],
      self::SEATED => [
        'label' => 'Seated event',
        'commerce_required' => TRUE,
        'attendance_access' => TRUE,
        'seating_required' => TRUE,
      ],
      self::FESTIVAL => [
        'label' => 'Festival',
        'commerce_required' => TRUE,
        'attendance_access' => TRUE,
        'seating_required' => FALSE,
      ],
      self::DIGITAL => [
        'label' => 'Digital event',
        'commerce_required' => FALSE,
        'attendance_access' => TRUE,
        'seating_required' => FALSE,
      ],
      self::EXTERNAL => [
        'label' => 'External booking event',
        'commerce_required' => FALSE,
        'attendance_access' => FALSE,
        'seating_required' => FALSE,
      ],
    ];
  }

  /**
   * Maps current field_event_type values onto operational type identifiers.
   */
  public static function fromFieldEventType(string $field_event_type): string {
    return match ($field_event_type) {
      'paid' => self::TICKETED,
      'both' => self::HYBRID,
      'rsvp' => self::RSVP,
      'external' => self::EXTERNAL,
      default => $field_event_type,
    };
  }

}
