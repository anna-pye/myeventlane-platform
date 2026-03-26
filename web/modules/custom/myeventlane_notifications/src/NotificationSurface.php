<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

/**
 * Where a delivery should surface in the product UI (toast vs bell vs quiet).
 *
 * Phase 3 note (Ably): realtime payloads should respect the same surface rules;
 * the Drupal delivery row remains the source of truth for read state.
 */
final class NotificationSurface {

  public const TOAST_INBOX = 'toast_inbox';

  public const BELL_INBOX = 'bell_inbox';

  public const INBOX_ONLY = 'inbox_only';

  /**
   * @return list<string>
   */
  public static function allowed(): array {
    return [
      self::TOAST_INBOX,
      self::BELL_INBOX,
      self::INBOX_ONLY,
    ];
  }

}
