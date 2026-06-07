<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

/**
 * Inbox tab and filter keys (query ?tab= and ?filter=).
 */
final class NotificationFilter {

  public const TAB_ALL = 'all';

  public const TAB_PERSONAL = 'personal';

  public const TAB_BUSINESS = 'business';

  public const TAB_PLATFORM = 'platform';

  public const FILTER_ALL = 'all';

  public const FILTER_UNREAD = 'unread';

  // Personal domain filters.
  public const FILTER_TICKETS = 'tickets';

  public const FILTER_ORDERS = 'orders';

  public const FILTER_REMINDERS = 'reminders';

  // Business domain filters.
  public const FILTER_SALES = 'sales';

  public const FILTER_REFUNDS = 'refunds';

  public const FILTER_RSVPS = 'rsvps';

  public const FILTER_FOLLOWERS = 'followers';

  public const FILTER_EVENT_UPDATES = 'event_updates';

  public const FILTER_BOOSTS = 'boosts';

  // Platform domain filter.
  public const FILTER_SYSTEM = 'system';

  // Legacy keys (backwards compatible query strings).
  public const LEGACY_EVENTS = 'events';

  public const LEGACY_PLATFORM = 'platform';

  /**
   * @return list<string>
   */
  public static function allowedTabs(): array {
    return [
      self::TAB_ALL,
      self::TAB_PERSONAL,
      self::TAB_BUSINESS,
      self::TAB_PLATFORM,
    ];
  }

  /**
   * @return list<string>
   */
  public static function allowedFilters(): array {
    return [
      self::FILTER_ALL,
      self::FILTER_UNREAD,
      self::FILTER_TICKETS,
      self::FILTER_ORDERS,
      self::FILTER_REMINDERS,
      self::FILTER_SALES,
      self::FILTER_REFUNDS,
      self::FILTER_RSVPS,
      self::FILTER_FOLLOWERS,
      self::FILTER_EVENT_UPDATES,
      self::FILTER_BOOSTS,
      self::FILTER_SYSTEM,
      self::LEGACY_EVENTS,
      self::LEGACY_PLATFORM,
    ];
  }

  /**
   * @deprecated Use allowedTabs() and allowedFilters().
   *
   * @return list<string>
   */
  public static function allowed(): array {
    return self::allowedFilters();
  }

  /**
   * Domain filters shown for a context tab.
   *
   * @return list<string>
   */
  public static function filtersForTab(string $tab): array {
    return match ($tab) {
      self::TAB_PERSONAL => [
        self::FILTER_ALL,
        self::FILTER_UNREAD,
        self::FILTER_TICKETS,
        self::FILTER_ORDERS,
        self::FILTER_EVENT_UPDATES,
        self::FILTER_REMINDERS,
      ],
      self::TAB_BUSINESS => [
        self::FILTER_ALL,
        self::FILTER_UNREAD,
        self::FILTER_SALES,
        self::FILTER_REFUNDS,
        self::FILTER_RSVPS,
        self::FILTER_FOLLOWERS,
        self::FILTER_EVENT_UPDATES,
        self::FILTER_BOOSTS,
      ],
      self::TAB_PLATFORM => [
        self::FILTER_ALL,
        self::FILTER_UNREAD,
        self::FILTER_SYSTEM,
      ],
      default => [
        self::FILTER_ALL,
        self::FILTER_UNREAD,
        self::FILTER_TICKETS,
        self::FILTER_ORDERS,
        self::FILTER_REMINDERS,
        self::FILTER_SALES,
        self::FILTER_REFUNDS,
        self::FILTER_RSVPS,
        self::FILTER_FOLLOWERS,
        self::FILTER_EVENT_UPDATES,
        self::FILTER_BOOSTS,
        self::FILTER_SYSTEM,
      ],
    };
  }

  /**
   * Maps a filter key to notification domain(s).
   *
   * @return list<string>|null
   */
  public static function domainsForFilter(string $filter): ?array {
    return match ($filter) {
      self::FILTER_TICKETS => [NotificationDomain::TICKETS],
      self::FILTER_ORDERS => [NotificationDomain::ORDERS],
      self::FILTER_REMINDERS => [NotificationDomain::REMINDERS],
      self::FILTER_SALES => [NotificationDomain::SALES],
      self::FILTER_REFUNDS => [NotificationDomain::REFUNDS],
      self::FILTER_RSVPS => [NotificationDomain::RSVPS],
      self::FILTER_FOLLOWERS => [NotificationDomain::FOLLOWERS],
      self::FILTER_EVENT_UPDATES, self::LEGACY_EVENTS => [NotificationDomain::EVENT_UPDATES],
      self::FILTER_BOOSTS => [NotificationDomain::BOOSTS],
      self::FILTER_SYSTEM, self::LEGACY_PLATFORM => [NotificationDomain::PLATFORM],
      default => NULL,
    };
  }

  /**
   * Maps a tab key to notification context(s).
   *
   * @return list<string>|null
   */
  public static function contextsForTab(string $tab): ?array {
    return match ($tab) {
      self::TAB_PERSONAL => [NotificationContext::PERSONAL],
      self::TAB_BUSINESS => [NotificationContext::BUSINESS],
      self::TAB_PLATFORM => [NotificationContext::PLATFORM],
      default => NULL,
    };
  }

}
