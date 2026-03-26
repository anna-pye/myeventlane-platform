<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Realtime;

/**
 * Publishes a lightweight notification event to a real-time transport.
 *
 * Phase 2 ships a no-op implementation so call sites stay stable.
 *
 * Phase 3 recommendation (MEL): use **Ably** (or similar managed pub/sub) as the
 * transport layer. Keep Drupal (mel_notification + mel_notification_delivery)
 * as the **source of truth** for read/unread and history; publish only derived,
 * per-recipient payloads built by NotificationRealtimePayloadBuilder. Retain HTTP
 * polling as a **fallback** when sockets are unavailable—do not attempt to
 * replicate Ably-style reliability with self-hosted WebSockets early on; the
 * operational cost and edge cases are a poor trade-off at MEL’s current stage.
 */
interface NotificationRealtimePublisherInterface {

  /**
   * Notify one recipient that a new delivery exists (or was updated).
   *
   * @param int $recipient_uid
   *   Target user ID.
   * @param array<string, mixed> $payload
   *   Sanitised payload from NotificationRealtimePayloadBuilder.
   */
  public function publishForRecipient(int $recipient_uid, array $payload): void;

}
