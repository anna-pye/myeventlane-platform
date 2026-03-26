<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;
use Drupal\myeventlane_notifications\Realtime\NotificationRealtimePublisherInterface;
use Drupal\myeventlane_notifications\Service\NotificationDecisionEngine;
use Drupal\myeventlane_notifications\NotificationSurface;
use Drupal\myeventlane_notifications\Service\NotificationManager;
use Drupal\myeventlane_notifications\Service\NotificationRealtimePayloadBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates per-user delivery rows in bounded batches (retry- and idempotent-safe).
 *
 * @QueueWorker(
 *   id = "myeventlane_notification_dispatch",
 *   title = @Translation("MyEventLane notification dispatch"),
 *   cron = {"time" = 45}
 * )
 */
final class NotificationDispatchWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public const QUEUE_ID = 'myeventlane_notification_dispatch';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly NotificationManager $notificationManager,
    private readonly NotificationDecisionEngine $decisionEngine,
    private readonly NotificationRealtimePublisherInterface $realtimePublisher,
    private readonly NotificationRealtimePayloadBuilder $realtimePayloadBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.channel.myeventlane_notifications'),
      $container->get('datetime.time'),
      $container->get('state'),
      $container->get('lock'),
      $container->get('myeventlane_notifications.notification_manager'),
      $container->get('myeventlane_notifications.decision_engine'),
      $container->get('myeventlane_notifications.realtime_publisher'),
      $container->get('myeventlane_notifications.realtime_payload'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      $this->logger->error('Notification dispatch queue item was not an array.');
      return;
    }

    $notificationId = (int) ($data['notification_id'] ?? 0);
    $userIds = $data['user_ids'] ?? [];
    $batchIndex = isset($data['batch_index']) ? (int) $data['batch_index'] : 0;
    $batchTotal = isset($data['batch_total']) ? (int) $data['batch_total'] : 1;

    if ($notificationId < 1 || !is_array($userIds)) {
      $this->logger->error('Malformed notification dispatch item for notification @id.', [
        '@id' => (string) $notificationId,
      ]);
      return;
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (count($userIds) > 50) {
      $this->logger->error('Notification dispatch chunk exceeded 50 users; truncating.', [
        'notification_id' => $notificationId,
      ]);
      $userIds = array_slice($userIds, 0, 50);
    }

    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification|null $notification */
    $notification = $this->entityTypeManager->getStorage('mel_notification')->load($notificationId);
    if (!$notification instanceof MelNotification) {
      $this->logger->warning('Notification @id missing; dropping queue item.', ['@id' => (string) $notificationId]);
      return;
    }

    $lockName = 'mel_notif_batchtrack_' . $notificationId;
    if (!$this->lock->acquire($lockName, 60.0)) {
      throw new \RuntimeException('Could not acquire batch lock for notification ' . $notificationId);
    }

    try {
      $doneKey = $this->notificationManager->batchDoneKey($notificationId);
      $done = $this->state->get($doneKey, []);
      if (!is_array($done)) {
        $done = [];
      }
      if (in_array($batchIndex, $done, TRUE)) {
        return;
      }

      $status = (string) $notification->get('status')->value;
      if ($status === MelNotification::STATUS_QUEUED) {
        $notification->set('status', MelNotification::STATUS_PROCESSING);
        $notification->save();
      }

      $deliveryStorage = $this->entityTypeManager->getStorage('mel_notification_delivery');
      $now = $this->time->getRequestTime();

      foreach ($userIds as $uid) {
        if ($uid < 1) {
          continue;
        }

        $existing = $deliveryStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('notification_id', $notificationId)
          ->condition('recipient_uid', $uid)
          ->range(0, 1)
          ->execute();

        if ($existing !== []) {
          continue;
        }

        try {
          $presentation = $this->decisionEngine->resolveDeliveryPresentation($notification, $uid);
          $surface = $presentation['surface'];
          $suppressed = (bool) $presentation['suppressed'];
          $groupKey = trim((string) $notification->get('group_key')->value);
          $grouped = $groupKey !== '';
          /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
          $delivery = $deliveryStorage->create([
            'notification_id' => $notificationId,
            'recipient_uid' => $uid,
            'status' => MelNotificationDelivery::STATUS_SENT,
            'delivered_at' => $now,
            'surface' => $surface,
            'suppressed' => $suppressed,
            'grouped' => $grouped,
          ]);
          $delivery->save();

          if (!$suppressed) {
            try {
              $payload = $this->realtimePayloadBuilder->buildForDelivery($delivery, $notification, $uid);
              $this->realtimePublisher->publishForRecipient($uid, $payload);
            }
            catch (\Throwable $e) {
              $this->logger->error('Realtime publish preparation failed for delivery @id: @message', [
                '@id' => (string) $delivery->id(),
                '@message' => $e->getMessage(),
              ]);
            }
          }
        }
        catch (\Throwable $e) {
          if ($this->isDuplicateKeyException($e)) {
            $this->logger->notice('Duplicate delivery skipped (notification @nid, user @uid).', [
              '@nid' => (string) $notificationId,
              '@uid' => (string) $uid,
            ]);
            continue;
          }

          $this->logger->error('Failed delivery row for notification @nid user @uid: @message', [
            '@nid' => (string) $notificationId,
            '@uid' => (string) $uid,
            '@message' => $e->getMessage(),
          ]);

          try {
            $failedExisting = $deliveryStorage->getQuery()
              ->accessCheck(FALSE)
              ->condition('notification_id', $notificationId)
              ->condition('recipient_uid', $uid)
              ->range(0, 1)
              ->execute();
            if ($failedExisting !== []) {
              continue;
            }
            /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $failed */
            $failed = $deliveryStorage->create([
              'notification_id' => $notificationId,
              'recipient_uid' => $uid,
              'status' => MelNotificationDelivery::STATUS_FAILED,
              'surface' => NotificationSurface::BELL_INBOX,
              'suppressed' => FALSE,
              'grouped' => FALSE,
            ]);
            $failed->save();
          }
          catch (\Throwable $inner) {
            if ($this->isDuplicateKeyException($inner)) {
              continue;
            }
            $this->logger->error('Could not persist failed delivery marker for notification @nid user @uid: @message', [
              '@nid' => (string) $notificationId,
              '@uid' => (string) $uid,
              '@message' => $inner->getMessage(),
            ]);
          }
        }
      }

      $done[] = $batchIndex;
      sort($done);
      if (count($done) >= $batchTotal) {
        $this->state->delete($doneKey);
        $this->state->delete($this->notificationManager->batchTotalKey($notificationId));
        $this->state->delete($this->notificationManager->audienceCountKey($notificationId));
        $this->finalizeNotification($notificationId);
      }
      else {
        $this->state->set($doneKey, $done);
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  private function finalizeNotification(int $notificationId): void {
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification|null $notification */
    $notification = $this->entityTypeManager->getStorage('mel_notification')->load($notificationId);
    if (!$notification instanceof MelNotification) {
      return;
    }

    $current = (string) $notification->get('status')->value;
    if (!in_array($current, [
      MelNotification::STATUS_PROCESSING,
      MelNotification::STATUS_QUEUED,
    ], TRUE)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $failed = count($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('notification_id', $notificationId)
      ->condition('status', MelNotificationDelivery::STATUS_FAILED)
      ->execute());

    $sentOrRead = count($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('notification_id', $notificationId)
      ->condition('status', [MelNotificationDelivery::STATUS_SENT, MelNotificationDelivery::STATUS_READ], 'IN')
      ->execute());

    if ($failed === 0) {
      $notification->set('status', MelNotification::STATUS_SENT);
    }
    elseif ($sentOrRead > 0) {
      $notification->set('status', MelNotification::STATUS_PARTIALLY_FAILED);
    }
    else {
      $notification->set('status', MelNotification::STATUS_FAILED);
    }
    $notification->save();
  }

  private function isDuplicateKeyException(\Throwable $e): bool {
    if ($e instanceof \PDOException) {
      $code = (string) $e->getCode();
      if ($code === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
        return TRUE;
      }
    }
    $msg = $e->getMessage();
    return str_contains($msg, 'Duplicate') || str_contains($msg, 'unique constraint') || str_contains($msg, 'UNIQUE constraint failed');
  }

}
