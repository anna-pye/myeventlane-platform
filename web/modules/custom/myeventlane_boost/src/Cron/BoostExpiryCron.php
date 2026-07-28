<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Cron;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\myeventlane_boost\Service\BoostEntitlementManager;
use Drupal\myeventlane_notifications\Service\BusinessNotificationTriggerService;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cron handler to expire boosted events and notify vendors.
 *
 * Uses entitlement state as the notification idempotency authority.
 */
final class BoostExpiryCron implements ContainerInjectionInterface {

  /**
   * Maximum lifetime of an expiry notification lock.
   */
  private const LOCK_TTL = 300.0;

  /**
   * Maximum number of automatic expiry notification attempts.
   */
  private const MAX_NOTIFICATION_ATTEMPTS = 5;

  /**
   * Constructs a BoostExpiryCron.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\myeventlane_boost\Service\BoostEntitlementManager $entitlementManager
   *   The entitlement manager.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\myeventlane_notifications\Service\BusinessNotificationTriggerService|null $businessNotificationTrigger
   *   Optional business notification trigger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MailManagerInterface $mailManager,
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly LockBackendInterface $lock,
    private readonly ?BusinessNotificationTriggerService $businessNotificationTrigger = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_boost'),
      $container->get('plugin.manager.mail'),
      $container->get('myeventlane_boost.entitlement_manager'),
      $container->get('lock'),
      $container->has('myeventlane_notifications.business_trigger')
        ? $container->get('myeventlane_notifications.business_trigger')
        : NULL,
    );
  }

  /**
   * Process expired boosts.
   *
   * Claims each expired entitlement before sending its notification.
   */
  public function process(): void {
    // Expiry is not notification-batch limited: promotion state must be
    // correct even when more than 500 notifications are awaiting delivery.
    $this->entitlementManager->expireEndedEntitlements();

    $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', BoostEntitlementInterface::STATUS_EXPIRED)
      ->condition('ends', $this->time->getRequestTime(), '<=')
      ->condition('expiry_notification_status', BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT, '<>')
      ->condition('expiry_notification_attempts', self::MAX_NOTIFICATION_ATTEMPTS, '<')
      ->notExists('expiry_notified_at')
      ->range(0, 500)
      ->execute();

    if (empty($ids)) {
      return;
    }

    foreach (array_map('intval', $ids) as $entitlementId) {
      $this->processEntitlement($entitlementId);
    }
  }

  /**
   * Notifies once for all simultaneously ended entitlements on an event.
   */
  private function processEntitlement(int $entitlementId): void {
    $storage = $this->entityTypeManager
      ->getStorage('myeventlane_boost_entitlement');
    $entitlement = $storage->load($entitlementId);
    if (!$entitlement instanceof BoostEntitlementInterface) {
      return;
    }
    $eventId = (int) ($entitlement->get('event')->target_id ?? 0);
    if ($eventId <= 0) {
      $this->logger->error('Boost expiry notification failed: entitlement @entitlement_id has no event.', [
        '@entitlement_id' => $entitlementId,
      ]);
      return;
    }

    $lockName = 'myeventlane_boost.expiry_notification.event.' . $eventId;
    if (!$this->lock->acquire($lockName, self::LOCK_TTL)) {
      $this->logger->info('Boost expiry notification skipped because event @event_id is already being processed.', [
        '@event_id' => $eventId,
        '@entitlement_id' => $entitlementId,
      ]);
      return;
    }

    try {
      $storage->resetCache([$entitlementId]);
      $entitlement = $storage->load($entitlementId);
      if (!$entitlement instanceof BoostEntitlementInterface) {
        return;
      }

      if ((string) $entitlement->get('expiry_notification_status')->value === BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT
        || !$entitlement->get('expiry_notified_at')->isEmpty()) {
        return;
      }

      if ((int) ($entitlement->get('ends')->value ?? 0) > $this->time->getRequestTime()
        || (string) $entitlement->get('status')->value === BoostEntitlementInterface::STATUS_REVOKED) {
        return;
      }

      $activeIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('status', BoostEntitlementInterface::STATUS_ACTIVE)
        ->condition('ends', $this->time->getRequestTime(), '>')
        ->range(0, 1)
        ->execute();
      if ($activeIds !== []) {
        return;
      }

      $pendingIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('status', BoostEntitlementInterface::STATUS_EXPIRED)
        ->condition('ends', $this->time->getRequestTime(), '<=')
        ->condition(
          'expiry_notification_status',
          BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
          '<>',
        )
        ->condition(
          'expiry_notification_attempts',
          self::MAX_NOTIFICATION_ATTEMPTS,
          '<',
        )
        ->notExists('expiry_notified_at')
        ->execute();
      if ($pendingIds === []) {
        return;
      }

      $event = $entitlement->get('event')->entity;
      if (!$event instanceof NodeInterface) {
        $this->logger->error('Boost expiry notification failed: entitlement @entitlement_id has no event.', [
          '@entitlement_id' => $entitlementId,
        ]);
        return;
      }

      $pendingEntitlements = $storage->loadMultiple($pendingIds);
      $attempt = 0;
      foreach ($pendingEntitlements as $pendingEntitlement) {
        $nextAttempt = (int) ($pendingEntitlement
          ->get('expiry_notification_attempts')->value ?? 0) + 1;
        $attempt = max($attempt, $nextAttempt);
        $pendingEntitlement->set(
          'expiry_notification_status',
          BoostEntitlementInterface::EXPIRY_NOTIFICATION_PROCESSING,
        );
        $pendingEntitlement->set(
          'expiry_notification_attempts',
          $nextAttempt,
        );
        $pendingEntitlement->save();
      }

      // Persist the idempotency decision before invoking the external mail
      // transport. A worker stopping after Postmark accepts the message must
      // not leave a retryable row that can send the same email again.
      foreach ($pendingEntitlements as $pendingEntitlement) {
        $pendingEntitlement->set(
          'expiry_notification_status',
          BoostEntitlementInterface::EXPIRY_NOTIFICATION_SENT,
        );
        $pendingEntitlement->set(
          'expiry_notified_at',
          $this->time->getRequestTime(),
        );
        $pendingEntitlement->save();
      }

      try {
        $sent = $this->notifyVendor($event);
      }
      catch (\Throwable $exception) {
        $sent = FALSE;
        $this->logger->error('Boost expiry mail transport threw for event @event_id, attempt @attempt: @message', [
          '@event_id' => $event->id(),
          '@attempt' => $attempt,
          '@message' => $exception->getMessage(),
        ]);
      }

      if ($sent) {
        $this->logger->notice('Sent boost expiry notification once for @entitlement_count entitlement(s) on event @event_id, attempt @attempt.', [
          '@entitlement_id' => $entitlementId,
          '@event_id' => $event->id(),
          '@attempt' => $attempt,
          '@entitlement_count' => count($pendingEntitlements),
        ]);
      }
      else {
        foreach ($pendingEntitlements as $pendingEntitlement) {
          $pendingEntitlement->set(
            'expiry_notification_status',
            BoostEntitlementInterface::EXPIRY_NOTIFICATION_PENDING,
          );
          $pendingEntitlement->set('expiry_notified_at', NULL);
          $pendingEntitlement->save();
        }
        $this->logger->error('Boost expiry notification failed for @entitlement_count entitlement(s) on event @event_id, attempt @attempt; retry remains pending.', [
          '@entitlement_id' => $entitlementId,
          '@event_id' => $event->id(),
          '@attempt' => $attempt,
          '@entitlement_count' => count($pendingEntitlements),
        ]);
        if ($attempt >= self::MAX_NOTIFICATION_ATTEMPTS) {
          $this->logger->critical('Boost expiry notification exhausted automatic retries for event @event_id after @attempt attempts.', [
            '@event_id' => $event->id(),
            '@attempt' => $attempt,
          ]);
        }
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Sends an expiration notification to the event owner.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   */
  private function notifyVendor(NodeInterface $node): bool {
    $owner = $node->getOwner();
    if ($owner === NULL) {
      return FALSE;
    }

    $email = $owner->getEmail();
    if (empty($email)) {
      return FALSE;
    }

    $params = [
      'node' => $node,
      'vendor_name' => $this->preferredRecipientName($owner),
    ];

    $langcode = $owner->getPreferredLangcode() ?: 'en';

    $result = $this->mailManager->mail(
      'myeventlane_boost',
      'boost_expired',
      $email,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    $sent = !empty($result['result']);
    if ($sent && $this->businessNotificationTrigger !== NULL) {
      try {
        $this->businessNotificationTrigger->onBoostCompleted($node, (int) $owner->id());
      }
      catch (\Throwable $exception) {
        // The email has already been accepted. Ancillary in-app notification
        // failure must not make the email retryable.
        $this->logger->error('Boost completion notification failed after expiry email was sent for event @event_id: @message', [
          '@event_id' => $node->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    return $sent;
  }

  /**
   * Returns a human-facing recipient name without exposing an account handle.
   */
  private function preferredRecipientName(UserInterface $owner): string {
    if ($owner->hasField('field_display_name')
      && !$owner->get('field_display_name')->isEmpty()) {
      $name = trim((string) $owner->get('field_display_name')->value);
      if ($name !== '') {
        return $name;
      }
    }

    $displayName = trim($owner->getDisplayName());
    return $displayName !== '' ? $displayName : 'there';
  }

}
