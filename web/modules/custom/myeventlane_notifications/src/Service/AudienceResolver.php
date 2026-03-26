<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\user\UserInterface;

/**
 * Resolves notification audiences using indexed queries (no unbounded scans).
 */
final class AudienceResolver {

  private const CHUNK_SIZE = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Validates audience JSON and expands into queue chunks (max 50 UIDs each).
   *
   * @return list<array<int, int>>
   *   List of UID chunks; UIDs are unique within and across chunks.
   */
  public function buildUserIdChunks(MelNotification $notification): array {
    $type = (string) $notification->get('audience_type')->value;
    $raw = (string) ($notification->get('audience_data')->value ?? '');
    $data = $raw !== '' ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new \InvalidArgumentException('Invalid audience_data JSON.');
    }

    $chunks = match ($type) {
      MelNotification::AUDIENCE_ALL => $this->chunkIndexedQuery($this->queryAllActiveUserIds()),
      MelNotification::AUDIENCE_ROLE => $this->chunkIndexedQuery($this->queryRoleUserIds($data)),
      MelNotification::AUDIENCE_USER_IDS => $this->chunkExplicitUserIds($data),
      MelNotification::AUDIENCE_EVENT_ATTENDEES => $this->chunkIndexedQuery($this->queryEventAttendeeUserIds($data)),
      MelNotification::AUDIENCE_VENDOR => $this->chunkVendorUserIds($data),
      default => throw new \InvalidArgumentException('Unknown audience type.'),
    };

    return $this->dedupeChunks($chunks);
  }

  /**
   * Returns distinct active UIDs that follow any of the given category term IDs (flag).
   *
   * @param list<int> $termIds
   *
   * @return list<int>
   */
  public function resolveCategoryFollowerUserIds(array $termIds): array {
    $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds))));
    if ($termIds === [] || !$this->moduleHandler->moduleExists('flag')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('flagging');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', 'follow_category')
      ->condition('entity_type', 'taxonomy_term')
      ->condition('entity_id', $termIds, 'IN')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $uids = [];
    foreach ($storage->loadMultiple($ids) as $flagging) {
      $uid = (int) $flagging->getOwnerId();
      if ($uid > 0 && $this->loadActiveUser($uid)) {
        $uids[$uid] = $uid;
      }
    }
    return array_values($uids);
  }

  /**
   * @return \Drupal\Core\Database\Query\SelectInterface
   */
  private function queryAllActiveUserIds(): \Drupal\Core\Database\Query\SelectInterface {
    return $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid'])
      ->condition('u.default_langcode', 1)
      ->condition('u.uid', 0, '>')
      ->condition('u.status', 1)
      ->orderBy('u.uid');
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   */
  private function queryRoleUserIds(array $data): \Drupal\Core\Database\Query\SelectInterface {
    $roles = $data['roles'] ?? [];
    if (!is_array($roles) || $roles === []) {
      throw new \InvalidArgumentException('Audience role requires non-empty roles array.');
    }

    $valid = user_roles(TRUE);
    foreach ($roles as $role) {
      if (!is_string($role) || !isset($valid[$role])) {
        throw new \InvalidArgumentException('Invalid role in audience_data.');
      }
    }

    $query = $this->database->select('user__roles', 'ur');
    $query->join('users_field_data', 'uf', 'uf.uid = ur.entity_id AND uf.default_langcode = 1');
    $query->fields('ur', ['entity_id'])
      ->condition('ur.bundle', 'user')
      ->condition('ur.roles_target_id', $roles, 'IN')
      ->condition('uf.status', 1)
      ->condition('ur.entity_id', 0, '>')
      ->orderBy('ur.entity_id');

    $query->distinct();
    return $query;
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   */
  private function queryEventAttendeeUserIds(array $data): \Drupal\Core\Database\Query\SelectInterface {
    if (!$this->moduleHandler->moduleExists('myeventlane_event_attendees')) {
      throw new \InvalidArgumentException('The event attendees module must be enabled for this audience type.');
    }
    if (!$this->database->schema()->tableExists('event_attendee')) {
      throw new \InvalidArgumentException('Event attendee storage is not available.');
    }

    $eventId = (int) ($data['event_id'] ?? 0);
    if ($eventId < 1) {
      throw new \InvalidArgumentException('event_id is required for event_attendees audience.');
    }

    $nodes = $this->entityTypeManager->getStorage('node')->load($eventId);
    if ($nodes === NULL || $nodes->bundle() !== 'event') {
      throw new \InvalidArgumentException('Invalid event node.');
    }

    $query = $this->database->select('event_attendee', 'ea')
      ->fields('ea', ['uid_target_id'])
      ->condition('ea.event_target_id', $eventId)
      ->condition('ea.uid_target_id', 0, '>')
      ->orderBy('ea.uid_target_id');

    $query->distinct();
    return $query;
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return list<array<int, int>>
   */
  private function chunkVendorUserIds(array $data): array {
    $uids = [];
    if (!empty($data['user_id'])) {
      $uid = (int) $data['user_id'];
      if ($uid > 0 && $this->loadActiveUser($uid)) {
        $uids[$uid] = $uid;
      }
    }
    elseif (!empty($data['store_id'])) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->load((int) $data['store_id']);
      if ($store && method_exists($store, 'getOwnerId')) {
        $uid = (int) $store->getOwnerId();
        if ($uid > 0 && $this->loadActiveUser($uid)) {
          $uids[$uid] = $uid;
        }
      }
    }
    elseif (!empty($data['event_id'])) {
      $event = $this->entityTypeManager->getStorage('node')->load((int) $data['event_id']);
      if ($event && $event->bundle() === 'event') {
        $vendorUid = $this->resolveEventOrganiserUid($event);
        if ($vendorUid !== NULL) {
          $uids[$vendorUid] = $vendorUid;
        }
      }
    }

    if ($uids === []) {
      $this->logger->warning('Vendor audience resolved to zero users (check audience_data).');
      return [];
    }

    return array_chunk(array_values($uids), self::CHUNK_SIZE);
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return list<array<int, int>>
   */
  private function chunkExplicitUserIds(array $data): array {
    $ids = $data['user_ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
      throw new \InvalidArgumentException('user_ids array is required.');
    }
    $uids = [];
    foreach ($ids as $id) {
      $uid = (int) $id;
      if ($uid > 0 && $this->loadActiveUser($uid)) {
        $uids[$uid] = $uid;
      }
    }
    return array_chunk(array_values($uids), self::CHUNK_SIZE);
  }

  /**
   * @param \Drupal\Core\Database\Query\SelectInterface $select
   *
   * @return list<array<int, int>>
   */
  private function chunkIndexedQuery(\Drupal\Core\Database\Query\SelectInterface $select): array {
    $chunks = [];
    $current = [];
    $result = $select->execute();
    foreach ($result as $row) {
      $uid = (int) (array_values((array) $row)[0] ?? 0);
      if ($uid < 1 || !$this->loadActiveUser($uid)) {
        continue;
      }
      if (isset($current[$uid])) {
        continue;
      }
      $current[$uid] = $uid;
      if (count($current) >= self::CHUNK_SIZE) {
        $chunks[] = array_values($current);
        $current = [];
      }
    }
    if ($current !== []) {
      $chunks[] = array_values($current);
    }
    return $chunks;
  }

  /**
   * @param list<array<int, int>> $chunks
   *
   * @return list<array<int, int>>
   */
  private function dedupeChunks(array $chunks): array {
    $seen = [];
    $out = [];
    foreach ($chunks as $chunk) {
      $part = [];
      foreach ($chunk as $uid) {
        $uid = (int) $uid;
        if ($uid < 1 || isset($seen[$uid])) {
          continue;
        }
        $seen[$uid] = TRUE;
        $part[] = $uid;
      }
      if ($part !== []) {
        $out[] = array_values($part);
      }
    }
    return $out;
  }

  private function loadActiveUser(int $uid): bool {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user instanceof UserInterface && $user->isActive();
  }

  /**
   * Resolves organiser UID from an event node (vendor field or author).
   */
  public function resolveEventOrganiserUid(object $event): ?int {
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && method_exists($vendor, 'getOwnerId')) {
        $uid = (int) $vendor->getOwnerId();
        if ($uid > 0 && $this->loadActiveUser($uid)) {
          return $uid;
        }
      }
    }
    $ownerId = (int) $event->getOwnerId();
    if ($ownerId > 0 && $this->loadActiveUser($ownerId)) {
      return $ownerId;
    }
    return NULL;
  }

}
