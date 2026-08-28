<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
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

  public const ACTION_CENTRE_LIMIT = 100;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly Connection $database,
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
  public function countUnreadForContexts(int $uid, array $contexts, bool $focused = FALSE): int {
    if ($uid < 1 || $contexts === []) {
      return 0;
    }
    if ($focused) {
      return array_sum($this->countFocusedUnreadBreakdown($uid, $contexts));
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
    $counts = [
      NotificationContext::PERSONAL => 0,
      NotificationContext::BUSINESS => 0,
      NotificationContext::PLATFORM => 0,
    ];
    if ($uid > 0) {
      $query = $this->database->select('mel_notification_delivery', 'd');
      $query->innerJoin('mel_notification', 'n', 'n.id = d.notification_id');
      $query->fields('n', ['context']);
      $query->addExpression('COUNT(d.id)', 'unread_count');
      $query->condition('d.recipient_uid', $uid)
        ->condition('d.suppressed', 0)
        ->condition('d.status', [
          MelNotificationDelivery::STATUS_SENT,
          MelNotificationDelivery::STATUS_PENDING,
        ], 'IN')
        ->condition('n.context', array_keys($counts), 'IN');
      $query->groupBy('n.context');
      foreach ($query->execute() as $row) {
        $context = (string) $row->context;
        if (array_key_exists($context, $counts)) {
          $counts[$context] = (int) $row->unread_count;
        }
      }
    }

    return [
      'personal' => $counts[NotificationContext::PERSONAL],
      'business' => $counts[NotificationContext::BUSINESS],
      'platform' => $counts[NotificationContext::PLATFORM],
      'unread' => array_sum($counts),
    ];
  }

  /**
   * Counts focused unread semantic groups without hydrating entities.
   *
   * @param int $uid
   *   Recipient user ID.
   * @param list<string> $contexts
   *   Notification contexts to count.
   *
   * @return array<string, int>
   *   Grouped unread counts keyed by requested context.
   */
  public function countFocusedUnreadBreakdown(int $uid, array $contexts): array {
    $contexts = array_values(array_intersect($contexts, NotificationContext::allowed()));
    $counts = array_fill_keys($contexts, 0);
    if ($uid < 1 || $contexts === []) {
      return $counts;
    }

    foreach ([TRUE, FALSE] as $hasGroupKey) {
      $query = $this->database->select('mel_notification_delivery', 'd');
      $query->innerJoin('mel_notification', 'n', 'n.id = d.notification_id');
      $query->fields('n', ['context']);
      $query->condition('d.recipient_uid', $uid)
        ->condition('d.suppressed', 0)
        ->condition('d.status', [
          MelNotificationDelivery::STATUS_SENT,
          MelNotificationDelivery::STATUS_PENDING,
        ], 'IN')
        ->condition('n.context', $contexts, 'IN');
      $attention = $query->orConditionGroup()
        ->condition('n.requires_action', 1)
        ->condition('n.priority', ['high', 'critical'], 'IN');
      $query->condition($attention);

      if ($hasGroupKey) {
        $query->isNotNull('n.group_key')
          ->condition('n.group_key', '', '<>');
        $query->addExpression('COUNT(DISTINCT n.group_key)', 'group_count');
      }
      else {
        $emptyGroup = $query->orConditionGroup()
          ->isNull('n.group_key')
          ->condition('n.group_key', '');
        $query->condition($emptyGroup);
        $query->addExpression('COUNT(DISTINCT n.id)', 'group_count');
      }
      $query->groupBy('n.context');

      foreach ($query->execute() as $row) {
        $context = (string) $row->context;
        if (array_key_exists($context, $counts)) {
          $counts[$context] += (int) $row->group_count;
        }
      }
    }

    return $counts;
  }

  /**
   * @param list<string> $contexts
   *
   * @return list<int>
   */
  public function getUnreadDeliveryIds(int $uid, int $limit = self::BELL_PREVIEW_LIMIT, array $contexts = [], bool $focused = FALSE): array {
    if ($uid < 1) {
      return [];
    }
    $limit = max(1, min(50, $limit));
    if ($focused && $contexts !== []) {
      return array_slice($this->getFocusedUnreadDeliveryIds($uid, $contexts), 0, $limit);
    }
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
   * Returns one unread delivery per semantic group for the organiser bell.
   *
   * @param int $uid
   *   Recipient user ID.
   * @param list<string> $contexts
   *   Notification contexts included in the organiser bell.
   *
   * @return list<int>
   *   Representative delivery IDs, newest first.
   */
  private function getFocusedUnreadDeliveryIds(int $uid, array $contexts): array {
    $notificationIds = $this->notificationIdsForFocusedBell($contexts);
    if ($notificationIds === []) {
      return [];
    }

    $deliveryStorage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $ids = $deliveryStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('notification_id', $notificationIds, 'IN')
      ->condition('status', [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], 'IN')
      ->sort('delivered_at', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    if ($ids === []) {
      return [];
    }

    $deliveries = $deliveryStorage->loadMultiple($ids);
    $notificationStorage = $this->entityTypeManager->getStorage('mel_notification');
    $notifications = $notificationStorage->loadMultiple($notificationIds);
    $out = [];
    $seen = [];
    foreach ($ids as $id) {
      $delivery = $deliveries[$id] ?? NULL;
      if (!$delivery instanceof MelNotificationDelivery) {
        continue;
      }
      $notificationId = (int) $delivery->get('notification_id')->target_id;
      $notification = $notifications[$notificationId] ?? NULL;
      if ($notification === NULL) {
        continue;
      }
      $groupKey = trim((string) $notification->get('group_key')->value);
      $key = $groupKey !== ''
        ? (string) $notification->get('context')->value . '|' . $groupKey
        : 'notification|' . $notificationId;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $out[] = (int) $id;
    }
    return $out;
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
   * Returns recent organiser deliveries, optionally scoped to one event.
   *
   * Sectioning into attention, activity and platform updates is presentation
   * logic because handled state belongs to the per-user delivery.
   *
   * @return list<int>
   */
  public function getActionCentreDeliveryIds(int $uid, ?int $eventId = NULL): array {
    if ($uid < 1) {
      return [];
    }

    $notificationQuery = $this->entityTypeManager->getStorage('mel_notification')->getQuery()
      ->accessCheck(FALSE)
      ->condition('context', NotificationContext::vendorBellContexts(), 'IN');
    if ($eventId !== NULL && $eventId > 0) {
      $notificationQuery->condition('event_id', $eventId);
    }
    $notificationIds = array_values(array_map('intval', $notificationQuery->execute()));
    if ($notificationIds === []) {
      return [];
    }

    $notificationStorage = $this->entityTypeManager->getStorage('mel_notification');
    $actionNotificationQuery = $notificationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('context', NotificationContext::vendorBellContexts(), 'IN')
      ->condition('requires_action', 1);
    if ($eventId !== NULL && $eventId > 0) {
      $actionNotificationQuery->condition('event_id', $eventId);
    }
    $actionNotificationIds = array_values(array_map('intval', $actionNotificationQuery->execute()));

    $deliveryStorage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $attentionIds = [];
    if ($actionNotificationIds !== []) {
      $attentionQuery = $deliveryStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('recipient_uid', $uid)
        ->condition('suppressed', 0)
        ->condition('notification_id', $actionNotificationIds, 'IN')
        ->sort('delivered_at', 'DESC')
        ->sort('id', 'DESC');
      $unresolved = $attentionQuery->orConditionGroup()
        ->notExists('resolved_at')
        ->condition('resolved_at', 0);
      $attentionQuery->condition($unresolved);
      $attentionIds = array_values(array_map('intval', $attentionQuery->execute()));
    }

    $recentIds = $deliveryStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('notification_id', $notificationIds, 'IN')
      ->sort('delivered_at', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, self::ACTION_CENTRE_LIMIT)
      ->execute();
    return array_values(array_unique([
      ...$attentionIds,
      ...array_map('intval', $recentIds),
    ]));
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
   * Organiser bell scope: action-required or genuinely high-value updates.
   *
   * @param list<string> $contexts
   *
   * @return list<int>
   */
  private function notificationIdsForFocusedBell(array $contexts): array {
    $query = $this->entityTypeManager->getStorage('mel_notification')->getQuery()
      ->accessCheck(FALSE)
      ->condition('context', $contexts, 'IN');
    $attention = $query->orConditionGroup()
      ->condition('requires_action', 1)
      ->condition('priority', ['high', 'critical'], 'IN');
    $query->condition($attention);
    return array_values(array_map('intval', $query->execute()));
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

  /**
   * Marks exactly one delivery as read for the recipient.
   */
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
    if (in_array((string) $entity->get('status')->value, [
      MelNotificationDelivery::STATUS_SENT,
      MelNotificationDelivery::STATUS_PENDING,
    ], TRUE)) {
      $entity->set('status', MelNotificationDelivery::STATUS_READ);
      $entity->set('read_at', $this->time->getRequestTime());
      $entity->save();
    }
    return TRUE;
  }

  /**
   * Marks a grouped organiser-bell summary as read for the recipient.
   */
  public function markReadGroup(int $uid, int $deliveryId): bool {
    if ($uid < 1 || $deliveryId < 1) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery|null $delivery */
    $delivery = $storage->load($deliveryId);
    if (!$delivery instanceof MelNotificationDelivery
      || (int) $delivery->get('recipient_uid')->target_id !== $uid) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    foreach ($storage->loadMultiple($this->deliveryIdsForGroup($uid, $delivery)) as $groupDelivery) {
      $status = (string) $groupDelivery->get('status')->value;
      if (!in_array($status, [
        MelNotificationDelivery::STATUS_SENT,
        MelNotificationDelivery::STATUS_PENDING,
      ], TRUE)) {
        continue;
      }
      $groupDelivery->set('status', MelNotificationDelivery::STATUS_READ);
      $groupDelivery->set('read_at', $now);
      $groupDelivery->save();
    }
    return TRUE;
  }

  /**
   * Marks an action-required delivery as handled for its recipient.
   */
  public function markHandledOne(int $uid, int $deliveryId): bool {
    if ($uid < 1 || $deliveryId < 1) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery|null $delivery */
    $delivery = $storage->load($deliveryId);
    if (!$delivery instanceof MelNotificationDelivery
      || (int) $delivery->get('recipient_uid')->target_id !== $uid) {
      return FALSE;
    }
    $notification = $this->entityTypeManager->getStorage('mel_notification')
      ->load($delivery->get('notification_id')->target_id);
    if (!$notification || empty($notification->get('requires_action')->value)) {
      return FALSE;
    }
    $now = $this->time->getRequestTime();
    $groupIds = $this->deliveryIdsForGroup($uid, $delivery, TRUE);
    foreach ($storage->loadMultiple($groupIds) as $groupDelivery) {
      $groupDelivery->set('resolved_at', $now);
      $groupDelivery->set('status', MelNotificationDelivery::STATUS_READ);
      $groupDelivery->set('read_at', (int) ($groupDelivery->get('read_at')->value ?? 0) ?: $now);
      $groupDelivery->save();
    }
    return TRUE;
  }

  /**
   * Finds deliveries represented by the same semantic notification group.
   *
   * @param int $uid
   *   Recipient user ID.
   * @param \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery
   *   Representative delivery.
   * @param bool $requiresAction
   *   Whether to restrict the group to action-required notifications.
   *
   * @return list<int>
   *   Delivery IDs in the semantic group.
   */
  private function deliveryIdsForGroup(int $uid, MelNotificationDelivery $delivery, bool $requiresAction = FALSE): array {
    $notificationStorage = $this->entityTypeManager->getStorage('mel_notification');
    $notification = $notificationStorage->load($delivery->get('notification_id')->target_id);
    if ($notification === NULL) {
      return [(int) $delivery->id()];
    }
    $groupKey = trim((string) $notification->get('group_key')->value);
    if ($groupKey === '') {
      return [(int) $delivery->id()];
    }

    $notificationQuery = $notificationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('group_key', $groupKey)
      ->condition('context', (string) $notification->get('context')->value);
    if ($requiresAction) {
      $notificationQuery->condition('requires_action', 1);
    }
    $notificationIds = array_values(array_map('intval', $notificationQuery->execute()));
    if ($notificationIds === []) {
      return [(int) $delivery->id()];
    }

    $ids = $this->entityTypeManager->getStorage('mel_notification_delivery')->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient_uid', $uid)
      ->condition('suppressed', 0)
      ->condition('notification_id', $notificationIds, 'IN')
      ->execute();
    return $ids === []
      ? [(int) $delivery->id()]
      : array_values(array_map('intval', $ids));
  }

}
