<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;

/**
 * Lightweight aggregates for admin insight and future intelligence (no ML).
 */
final class NotificationAnalyticsService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Per-notification delivery funnel stats.
   *
   * @return array<string, int|float>
   *   Keys: sent, read, clicked, suppressed, grouped, read_rate, click_rate.
   */
  public function getNotificationStats(int $notificationId): array {
    if ($notificationId < 1) {
      return $this->emptyStats();
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    try {
      $deliveredStatuses = [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_READ,
      ];
      $sent = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $notificationId)
        ->condition('status', $deliveredStatuses, 'IN')
        ->count()
        ->execute();

      $read = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $notificationId)
        ->condition('status', MelNotificationDelivery::STATUS_READ)
        ->count()
        ->execute();

      $clicked = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $notificationId)
        ->condition('clicked_at', 0, '>')
        ->count()
        ->execute();

      $suppressed = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $notificationId)
        ->condition('suppressed', 1)
        ->count()
        ->execute();

      $grouped = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $notificationId)
        ->condition('grouped', 1)
        ->count()
        ->execute();

      $denom = max(1, $sent);
      $readRate = round(($read / $denom) * 100.0, 1);
      $clickRate = round(($clicked / $denom) * 100.0, 1);

      return [
        'sent' => $sent,
        'read' => $read,
        'clicked' => $clicked,
        'suppressed' => $suppressed,
        'grouped' => $grouped,
        'read_rate' => $readRate,
        'click_rate' => $clickRate,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('getNotificationStats failed for @nid: @message', [
        '@nid' => (string) $notificationId,
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyStats();
    }
  }

  /**
   * Simple engagement rollup for a user (all deliveries).
   *
   * @return array<string, int|float>
   */
  public function getUserEngagementStats(int $userId): array {
    if ($userId < 1) {
      return [
        'deliveries' => 0,
        'read' => 0,
        'clicked' => 0,
        'read_rate' => 0.0,
        'click_rate' => 0.0,
      ];
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    try {
      $total = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('recipient_uid', $userId)
        ->count()
        ->execute();
      if ($total === 0) {
        return [
          'deliveries' => 0,
          'read' => 0,
          'clicked' => 0,
          'read_rate' => 0.0,
          'click_rate' => 0.0,
        ];
      }
      $read = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('recipient_uid', $userId)
        ->condition('status', MelNotificationDelivery::STATUS_READ)
        ->count()
        ->execute();
      $clicked = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('recipient_uid', $userId)
        ->condition('clicked_at', 0, '>')
        ->count()
        ->execute();
      $denom = max(1, $total);
      return [
        'deliveries' => $total,
        'read' => $read,
        'clicked' => $clicked,
        'read_rate' => round(($read / $denom) * 100.0, 1),
        'click_rate' => round(($clicked / $denom) * 100.0, 1),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('getUserEngagementStats failed for @uid: @message', [
        '@uid' => (string) $userId,
        '@message' => $e->getMessage(),
      ]);
      return [
        'deliveries' => 0,
        'read' => 0,
        'clicked' => 0,
        'read_rate' => 0.0,
        'click_rate' => 0.0,
      ];
    }
  }

  /**
   * Aggregates across all notifications of a given type (for “best performers”).
   *
   * @return array<string, int|float>
   */
  public function getTypePerformanceStats(string $type): array {
    $notifStorage = $this->entityTypeManager->getStorage('mel_notification');
    try {
      $nids = $notifStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $type)
        ->execute();
      $nids = array_values(array_map('intval', $nids));
      if ($nids === []) {
        return [
          'notifications' => 0,
          'deliveries' => 0,
          'read' => 0,
          'clicked' => 0,
          'read_rate' => 0.0,
          'click_rate' => 0.0,
        ];
      }
      $deliveryStorage = $this->entityTypeManager->getStorage('mel_notification_delivery');
      $deliveries = (int) $deliveryStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $nids, 'IN')
        ->count()
        ->execute();
      if ($deliveries === 0) {
        return [
          'notifications' => count($nids),
          'deliveries' => 0,
          'read' => 0,
          'clicked' => 0,
          'read_rate' => 0.0,
          'click_rate' => 0.0,
        ];
      }
      $read = (int) $deliveryStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $nids, 'IN')
        ->condition('status', MelNotificationDelivery::STATUS_READ)
        ->count()
        ->execute();
      $clicked = (int) $deliveryStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('notification_id', $nids, 'IN')
        ->condition('clicked_at', 0, '>')
        ->count()
        ->execute();
      $denom = max(1, $deliveries);
      return [
        'notifications' => count($nids),
        'deliveries' => $deliveries,
        'read' => $read,
        'clicked' => $clicked,
        'read_rate' => round(($read / $denom) * 100.0, 1),
        'click_rate' => round(($clicked / $denom) * 100.0, 1),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('getTypePerformanceStats failed for @type: @message', [
        '@type' => $type,
        '@message' => $e->getMessage(),
      ]);
      return [
        'notifications' => 0,
        'deliveries' => 0,
        'read' => 0,
        'clicked' => 0,
        'read_rate' => 0.0,
        'click_rate' => 0.0,
      ];
    }
  }

  /**
   * @return array<string, int|float>
   */
  private function emptyStats(): array {
    return [
      'sent' => 0,
      'read' => 0,
      'clicked' => 0,
      'suppressed' => 0,
      'grouped' => 0,
      'read_rate' => 0.0,
      'click_rate' => 0.0,
    ];
  }

}
