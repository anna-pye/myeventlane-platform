<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Realtime;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * No-op publisher for Phase 2 (polling remains the live transport).
 */
final class NullNotificationRealtimePublisher implements NotificationRealtimePublisherInterface {

  public function __construct(
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function publishForRecipient(int $recipient_uid, array $payload): void {
    $this->logger->debug('Realtime publish skipped (null transport) for uid @uid delivery @did.', [
      '@uid' => (string) $recipient_uid,
      '@did' => isset($payload['delivery_id']) ? (string) $payload['delivery_id'] : '?',
    ]);
  }

}
