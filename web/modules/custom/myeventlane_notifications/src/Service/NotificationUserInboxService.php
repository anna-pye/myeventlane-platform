<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;
use Drupal\myeventlane_notifications\NotificationContext;
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

  /**
   * Total unread across all contexts.
   */
  public function countUnread(int $uid): int {
    return $this->countUnreadForContexts($uid, NotificationContext::allowed());
  }

  /**
   * Unread count for specific contexts (bell surfaces).
   *
   * @param list<string> $contexts
   */
  public function countUnreadForContexts(int $uid, array $contexts): int {
    if ($uid < 1 || $contexts === []) {
      return 0;
    }
    $notificationIds = $this->notificationIdsForContexts($contexts);
    if ($notificationIds === []) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('notification_id', $notificationIds, 'IN')
      ->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN');
    $query->count();
    return (int) $query->execute();
  }

  /**
   * Breakdown of unread counts by context.
   *
   * @return array{personal: int, business: int, platform: int, unread: int}
   */
  public function countUnreadBreakdown(int $uid): array {
    $personal = $this->countUnreadForContexts($uid, [NotificationContext::PERSONAL]);
    $business = $this->countUnreadForContexts($uid, [NotificationContext::BUSINESS]);
    $platform = $this->countUnreadForContexts($uid, [NotificationContext::PLATFORM]);
    return [
      'personal' => $personal,
      'business' => $business,
      'platform' => $platform,
      'unread' => $personal + $business + $platform,
    ];
  }

  /**
   * @param list<string> $contexts
   *
   * @return list<int>
   */
  public function getUnreadDeliveryIds(int $uid, int $limit = self::BELL_PREVIEW_LIMIT, array $contexts = []): array {
    if ($uid < 1) {
      return [];
    }
    $limit = max(1, min(50, $limit));
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN')
      ->sort('delivered_at', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, $limit);

    if ($contexts !== []) {
      $notificationIds = $this->notificationIdsForContexts($contexts);
      if ($notificationIds === []) {
        return [];
      }
      $query->condition('notification_id', $notificationIds, 'IN');
    }

    $ids = $query->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * @return list<int>
   */
  public function getInboxDeliveryIds(int $uid, string $tab, string $filter, int $page): array {
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

    if ($filter === NotificationFilter::FILTER_UNREAD) {
      $query->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN');
    }

    $contexts = NotificationFilter::contextsForTab($tab);
    $domains = NotificationFilter::domainsForFilter($filter);
    $notificationIds = $this->notificationIdsForScope($contexts, $domains);
    if ($notificationIds === []) {
      return [];
    }
    $query->condition('notification_id', $notificationIds, 'IN');

    $ids = $query->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * @param list<string>|null $contexts
   * @param list<string>|null $domains
   *
   * @return list<int>
   */
  private function notificationIdsForScope(?array $contexts, ?array $domains): array {
    $notifQuery = $this->entityTypeManager->getStorage('mel_notification')->getQuery()
      ->accessCheck(FALSE);
    if ($contexts !== NULL) {
      $notifQuery->condition('context', $contexts, 'IN');
    }
    if ($domains !== NULL) {
      $notifQuery->condition('domain', $domains, 'IN');
    }
    $ids = $notifQuery->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * @param list<string> $contexts
   *
   * @return list<int>
   */
  private function notificationIdsForContexts(array $contexts): array {
    return $this->notificationIdsForScope($contexts, NULL);
  }

  /**
   * Marks every unread delivery for the user as read.
   *
   * @param list<string>|null $contexts
   *   When set, only deliveries in these contexts are marked read.
   *
   * @return int
   *   Number of rows updated.
   */
  public function markAllRead(int $uid, ?array $contexts = NULL): int {
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

    if ($contexts !== NULL && $contexts !== []) {
      $notificationIds = $this->notificationIdsForContexts($contexts);
      if ($notificationIds === []) {
        return 0;
      }
      $query->condition('notification_id', $notificationIds, 'IN');
    }

    $ids = $query->execute();
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
