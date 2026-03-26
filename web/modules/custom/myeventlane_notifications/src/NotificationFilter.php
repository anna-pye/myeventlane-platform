<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

/**
 * Inbox filter keys (query ?filter=).
 */
final class NotificationFilter {

  public const ALL = 'all';

  public const UNREAD = 'unread';

  public const TICKETS = 'tickets';

  public const EVENTS = 'events';

  public const REMINDERS = 'reminders';

  public const PLATFORM = 'platform';

  /**
   * @return list<string>
   */
  public static function allowed(): array {
    return [
      self::ALL,
      self::UNREAD,
      self::TICKETS,
      self::EVENTS,
      self::REMINDERS,
      self::PLATFORM,
    ];
  }

}
