<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

/**
 * Notification domain (what happened).
 */
final class NotificationDomain {

  public const TICKETS = 'tickets';

  public const ORDERS = 'orders';

  public const SALES = 'sales';

  public const REFUNDS = 'refunds';

  public const RSVPS = 'rsvps';

  public const FOLLOWERS = 'followers';

  public const EVENT_UPDATES = 'event_updates';

  public const BOOSTS = 'boosts';

  public const REMINDERS = 'reminders';

  public const PLATFORM = 'platform';

  /**
   * @return list<string>
   */
  public static function allowed(): array {
    return [
      self::TICKETS,
      self::ORDERS,
      self::SALES,
      self::REFUNDS,
      self::RSVPS,
      self::FOLLOWERS,
      self::EVENT_UPDATES,
      self::BOOSTS,
      self::REMINDERS,
      self::PLATFORM,
    ];
  }

  /**
   * Domains available under a context tab in the inbox.
   *
   * @return list<string>
   */
  public static function forContext(string $context): array {
    return match ($context) {
      NotificationContext::PERSONAL => [
        self::TICKETS,
        self::ORDERS,
        self::REMINDERS,
        self::EVENT_UPDATES,
      ],
      NotificationContext::BUSINESS => [
        self::SALES,
        self::REFUNDS,
        self::RSVPS,
        self::FOLLOWERS,
        self::EVENT_UPDATES,
        self::BOOSTS,
      ],
      NotificationContext::PLATFORM => [
        self::PLATFORM,
      ],
      default => self::allowed(),
    };
  }

}
