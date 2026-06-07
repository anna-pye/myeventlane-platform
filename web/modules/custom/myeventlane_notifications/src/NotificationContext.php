<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

/**
 * Notification audience context (not Drupal user role).
 *
 * Controls which bell/inbox surface shows a delivery.
 */
final class NotificationContext {

  public const PERSONAL = 'personal';

  public const BUSINESS = 'business';

  public const PLATFORM = 'platform';

  /**
   * @return list<string>
   */
  public static function allowed(): array {
    return [
      self::PERSONAL,
      self::BUSINESS,
      self::PLATFORM,
    ];
  }

  /**
   * Contexts visible on the public-site bell (personal + platform).
   *
   * @return list<string>
   */
  public static function publicBellContexts(): array {
    return [self::PERSONAL, self::PLATFORM];
  }

  /**
   * Contexts visible on the vendor-dashboard bell (business + platform).
   *
   * @return list<string>
   */
  public static function vendorBellContexts(): array {
    return [self::BUSINESS, self::PLATFORM];
  }

}
