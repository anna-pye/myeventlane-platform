<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Plugin\QueueWorker\NotificationDispatchWorker;

/**
 * Creates notifications, resolves audiences, and enqueues dispatch batches.
 */
final class NotificationManager {

  private const SUPPRESS_TTL = 900;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly AudienceResolver $audienceResolver,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly CacheBackendInterface $cache,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * @param array<string, mixed> $data
   *   Keys: type, title, message, audience_type, audience_data (array|string JSON),
   *   channels (list), status (optional), scheduled_at (optional int),
   *   priority (optional), priority_score (optional), suppression_key (optional),
   *   group_key (optional), action_label (optional), action_uri (optional),
   *   action_context (optional).
   */
  public function createNotification(array $data): MelNotification {
    $audienceData = $data['audience_data'] ?? [];
    if (is_array($audienceData)) {
      $audienceJson = json_encode($audienceData, JSON_THROW_ON_ERROR);
    }
    else {
      $raw = trim((string) $audienceData);
      $audienceJson = $raw === '' ? '{}' : $raw;
      json_decode($audienceJson, TRUE, 512, JSON_THROW_ON_ERROR);
    }

    $channels = $data['channels'] ?? ['toast'];
    if (!is_array($channels)) {
      throw new \InvalidArgumentException('channels must be an array.');
    }

    $suppressionKey = trim((string) ($data['suppression_key'] ?? ''));
    if ($suppressionKey !== '') {
      $cacheKey = 'myeventlane_notifications:suppress:' . hash('sha256', $suppressionKey);
      if ($this->cache->get($cacheKey)) {
        $this->logger->notice('Suppressed duplicate notification for key @key.', ['@key' => $suppressionKey]);
        throw new \RuntimeException('Suppressed duplicate notification (suppression_key).');
      }
    }

    $actionLabel = trim((string) ($data['action_label'] ?? ''));
    $actionUri = $this->normalizeActionUri($data['action_uri'] ?? '');
    $actionContext = trim((string) ($data['action_context'] ?? ''));
    if ($actionLabel !== '' && $actionUri === '') {
      throw new \InvalidArgumentException('action_label requires a valid action_uri.');
    }
    if ($actionUri !== '' && $actionLabel === '') {
      throw new \InvalidArgumentException('action_uri requires action_label.');
    }

    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $entity */
    $entity = $this->entityTypeManager->getStorage('mel_notification')->create([
      'type' => $data['type'],
      'title' => $data['title'],
      'message' => $data['message'],
      'audience_type' => $data['audience_type'],
      'audience_data' => $audienceJson,
      'status' => $data['status'] ?? MelNotification::STATUS_DRAFT,
      'scheduled_at' => $data['scheduled_at'] ?? NULL,
      'priority' => $data['priority'] ?? MelNotification::PRIORITY_NORMAL,
      'priority_score' => (int) ($data['priority_score'] ?? 0),
      'suppression_key' => $suppressionKey,
      'group_key' => trim((string) ($data['group_key'] ?? '')),
      'action_label' => $actionLabel,
      'action_uri' => $actionUri,
      'action_context' => $actionContext,
    ]);
    foreach ($channels as $channel) {
      $entity->get('channels')->appendItem($channel);
    }
    $entity->save();

    if ($suppressionKey !== '') {
      $cacheKey = 'myeventlane_notifications:suppress:' . hash('sha256', $suppressionKey);
      $this->cache->set($cacheKey, TRUE, $this->time->getRequestTime() + self::SUPPRESS_TTL);
    }

    return $entity;
  }

  /**
   * @param mixed $uri
   */
  private function normalizeActionUri(mixed $uri): string {
    $uri = trim((string) $uri);
    if ($uri === '') {
      return '';
    }
    if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
      throw new \InvalidArgumentException('action_uri must be a site-relative path starting with /.');
    }
    return $uri;
  }

  /**
   * Enqueues dispatch jobs (draft|failed|partially_failed → queued).
   */
  public function queueNotification(MelNotification $notification): void {
    $id = (int) $notification->id();
    $status = (string) $notification->get('status')->value;

    if (in_array($status, [
      MelNotification::STATUS_SENT,
      MelNotification::STATUS_CANCELLED,
      MelNotification::STATUS_QUEUED,
      MelNotification::STATUS_PROCESSING,
    ], TRUE)) {
      return;
    }

    $scheduled = (int) ($notification->get('scheduled_at')->value ?? 0);
    if ($scheduled > $this->time->getRequestTime()) {
      $notification->set('status', MelNotification::STATUS_DRAFT);
      $notification->save();
      return;
    }

    $lockName = 'myeventlane_notifications_queue_' . $id;
    if (!$this->lock->acquire($lockName, 30.0)) {
      $this->logger->warning('Could not acquire queue lock for notification @id.', ['@id' => (string) $id]);
      return;
    }

    try {
      if ($this->state->get($this->batchTotalKey($id)) !== NULL) {
        return;
      }

      try {
        $chunks = $this->audienceResolver->buildUserIdChunks($notification);
      }
      catch (\Throwable $e) {
        $this->logger->error('Audience resolution failed for notification @id: @message', [
          '@id' => (string) $notification->id(),
          '@message' => $e->getMessage(),
        ]);
        throw $e;
      }

      $notification->set('status', MelNotification::STATUS_QUEUED);
      $notification->save();

      $queue = $this->queueFactory->get(NotificationDispatchWorker::QUEUE_ID);
      if ($chunks === []) {
        $notification->set('status', MelNotification::STATUS_SENT);
        $notification->save();
        return;
      }

      $totalBatches = count($chunks);
      $totalUsers = 0;
      foreach ($chunks as $userIds) {
        $totalUsers += count($userIds);
      }

      $this->state->set($this->batchTotalKey($id), $totalBatches);
      $this->state->set($this->audienceCountKey($id), $totalUsers);
      $this->state->set($this->batchDoneKey($id), []);

      foreach ($chunks as $i => $userIds) {
        $queue->createItem([
          'notification_id' => $id,
          'user_ids' => $userIds,
          'batch_index' => $i,
          'batch_total' => $totalBatches,
        ]);
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * @return list<int>
   */
  public function resolveAudience(MelNotification $notification): array {
    $chunks = $this->audienceResolver->buildUserIdChunks($notification);
    if ($chunks === []) {
      return [];
    }
    $merged = array_merge(...$chunks);
    return array_values(array_unique(array_map('intval', $merged)));
  }

  public function batchTotalKey(int $id): string {
    return 'myeventlane_notifications.batch_total.' . $id;
  }

  public function batchDoneKey(int $id): string {
    return 'myeventlane_notifications.batch_done.' . $id;
  }

  public function audienceCountKey(int $id): string {
    return 'myeventlane_notifications.audience_count.' . $id;
  }

}
