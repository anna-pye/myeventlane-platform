<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;

/**
 * Builds minimal, user-safe payloads for realtime channels (Ably Phase 3).
 */
final class NotificationRealtimePayloadBuilder {

  public function __construct(
    private readonly NotificationUserInboxService $inboxService,
    private readonly NotificationViewBuilder $viewBuilder,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function buildForDelivery(MelNotificationDelivery $delivery, MelNotification $notification, int $recipient_uid): array {
    $preview = $this->viewBuilder->messagePreview((string) $notification->get('message')->value);
    $action = $this->viewBuilder->buildTrackedActionForDelivery($delivery, $notification);

    $payload = [
      'delivery_id' => (int) $delivery->id(),
      'notification_id' => (int) $notification->id(),
      'title' => (string) $notification->get('title')->value,
      'message_preview' => $preview,
      'type' => (string) $notification->get('type')->value,
      'timestamp' => (int) ($delivery->get('delivered_at')->value ?? $notification->get('created')->value ?? 0),
      'surface' => (string) $delivery->get('surface')->value,
      'toast_eligible' => $this->viewBuilder->isToastEligible($delivery),
    ];

    if ($action !== NULL) {
      $payload['action'] = [
        'label' => $action['label'],
        'url' => $action['url'],
      ];
    }

    try {
      $payload['unread_total'] = $this->inboxService->countUnread($recipient_uid);
    }
    catch (\Throwable) {
      $payload['unread_total'] = NULL;
    }

    return $payload;
  }

}
