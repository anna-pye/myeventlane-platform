<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

/**
 * Product policy for notifications that need an organiser decision or action.
 *
 * Priority is deliberately not used here. A high-priority update may be
 * important without requiring work from the organiser.
 */
final class NotificationAttentionPolicy {

  private const ACTION_CONTEXTS = [
    'boost_expiring',
    'capacity_reached',
    'low_stock',
    'refund_requested',
  ];

  /**
   * Determines whether a notification belongs in Needs your attention.
   */
  public static function requiresAction(string $context, string $actionContext): bool {
    return $context === NotificationContext::BUSINESS
      && in_array($actionContext, self::ACTION_CONTEXTS, TRUE);
  }

}
