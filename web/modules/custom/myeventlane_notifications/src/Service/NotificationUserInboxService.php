<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;
use Drupal\myeventlane_notifications\NotificationFilter;

/**
 * Current-user-scoped inbox queries (no cross-user access).
 */
final class NotificationUserInboxService {

  public const BELL_PREVIEW_LIMIT = 12;

  public const INBOX_PAGE_SIZE = 25;

  public const INBOX_MAX_OFFSET = 2000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  public function countUnread(int $uid): int {
    if ($uid < 1) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN');
    $query->count();
    return (int) $query->execute();
  }

  /**
   * @return list<int>
   */
  public function getUnreadDeliveryIds(int $uid, int $limit = self::BELL_PREVIEW_LIMIT): array {
    if ($uid < 1) {
      return [];
    }
    $limit = max(1, min(50, $limit));
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN')
      ->sort('delivered_at', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * @return list<int>
   */
  public function getInboxDeliveryIds(int $uid, string $filter, int $page): array {
    if ($uid < 1) {
      return [];
    }
    $page = max(0, $page);
    $offset = $page * self::INBOX_PAGE_SIZE;
    if ($offset > self::INBOX_MAX_OFFSET) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->sort('delivered_at', 'DESC')
      ->sort('id', 'DESC')
      ->range($offset, self::INBOX_PAGE_SIZE);

    if ($filter === NotificationFilter::UNREAD) {
      $query->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN');
    }

    $notificationTypeFilter = $this->notificationTypesForFilter($filter);
    if ($notificationTypeFilter !== NULL) {
      $nids = $this->entityTypeManager->getStorage('mel_notification')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $notificationTypeFilter, 'IN')
        ->execute();
      $nids = array_values(array_map('intval', $nids));
      if ($nids === []) {
        return [];
      }
      $query->condition('notification_id', $nids, 'IN');
    }

    $ids = $query->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * @return list<int>|null
   *   Notification type machine names, or NULL when filter does not restrict type.
   */
  private function notificationTypesForFilter(string $filter): ?array {
    return match ($filter) {
      NotificationFilter::TICKETS => [MelNotification::TYPE_TICKET],
      NotificationFilter::EVENTS => [MelNotification::TYPE_EVENT],
      NotificationFilter::REMINDERS => [MelNotification::TYPE_REMINDER],
      NotificationFilter::PLATFORM => [
        MelNotification::TYPE_SYSTEM,
        MelNotification::TYPE_PROMO,
      ],
      default => NULL,
    };
  }

  /**
   * Marks every unread delivery for the user as read.
   *
   * @return int
   *   Number of rows updated.
   */
  public function markAllRead(int $uid): int {
    if ($uid < 1) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN')
      ->execute();
    $ids = array_values(array_map('intval', $ids));
    if ($ids === []) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $count = 0;
    foreach (array_chunk($ids, 50) as $chunk) {
      /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery[] $entities */
      $entities = $storage->loadMultiple($chunk);
      foreach ($entities as $entity) {
        if ((int) $entity->get('recipient_uid')->target_id !== $uid) {
          continue;
        }
        $entity->set('status', MelNotificationDelivery::STATUS_READ);
        $entity->set('read_at', $now);
        $entity->save();
        $count++;
      }
    }
    return $count;
  }

  public function markReadOne(int $uid, int $deliveryId): bool {
    if ($uid < 1 || $deliveryId < 1) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $deliveryId)
      ->condition('recipient_uid', $uid)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return FALSE;
    }
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery|null $entity */
    $entity = $storage->load(reset($ids));
    if (!$entity instanceof MelNotificationDelivery) {
      return FALSE;
    }
    $entity->set('status', MelNotificationDelivery::STATUS_READ);
    $entity->set('read_at', $this->time->getRequestTime());
    $entity->save();
    return TRUE;
  }

}

