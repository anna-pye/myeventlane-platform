<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Access;

/**
 * Pure route binding helpers for refund requests.
 */
final class RefundRequestRouteBinder {

  /**
   * Whether a loaded refund request belongs to the route event.
   *
   * @param array<string, mixed>|null $request
   *   Loaded refund request row, or NULL when missing.
   * @param int $eventId
   *   Route event node ID.
   *
   * @return bool
   *   TRUE when the request is present and event_id matches.
   */
  public static function requestBelongsToEvent(?array $request, int $eventId): bool {
    if (!is_array($request) || $eventId <= 0) {
      return FALSE;
    }
    return (int) ($request['event_id'] ?? 0) === $eventId;
  }

}
