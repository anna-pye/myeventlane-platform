<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Schedules and dispatches Pro subscription lifecycle notifications.
 */
final class ProSubscriptionLifecycleScheduler {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const BATCH_SIZE = 200;
  private const RENEWAL_REMINDER_WINDOW_SECONDS = 259200;
  private const STATUS_SCHEDULED = 'scheduled';
  private const STATUS_SENT = 'sent';
  private const STATUS_FAILED = 'failed';
  private const STATUS_CANCELLED = 'cancelled';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MessagingManager $messagingManager,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly ProEntitlementReconciler $reconciler,
    private readonly ProRecoveryAnalyticsService $recoveryAnalyticsService,
    private readonly ProBoostProvisioner $proBoostProvisioner,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Runs all lifecycle scheduling and dispatch tasks.
   *
   * @return array{renewal_scheduled:int, failed_scheduled:int, failed_cancelled:int, renewal_sent:int, failed_sent:int, grace_revoked:int, boost_sync_enqueued:int}
   *   Execution stats.
   */
  public function run(): array {
    $renewalScheduled = $this->scheduleRenewalReminders();
    $failedScheduled = $this->scheduleFailedPaymentSequence();
    $failedCancelled = $this->cancelFailedSequenceForRecovered();
    $renewalSent = $this->dispatchDueRenewalReminders();
    $failedSent = $this->dispatchDueFailedPaymentEmails();
    $graceRevoked = $this->reconciler->reconcileExpiredGracePeriods();
    $boostSyncEnqueued = $this->proBoostProvisioner->enqueueActiveProStoreSyncs(500);

    $stats = [
      'renewal_scheduled' => $renewalScheduled,
      'failed_scheduled' => $failedScheduled,
      'failed_cancelled' => $failedCancelled,
      'renewal_sent' => $renewalSent,
      'failed_sent' => $failedSent,
      'grace_revoked' => $graceRevoked,
      'boost_sync_enqueued' => $boostSyncEnqueued,
    ];

    $this->logger->info(
      'Pro lifecycle cron: renewal_scheduled=@renewal_scheduled failed_scheduled=@failed_scheduled failed_cancelled=@failed_cancelled renewal_sent=@renewal_sent failed_sent=@failed_sent grace_revoked=@grace_revoked boost_sync_enqueued=@boost_sync_enqueued',
      [
        '@renewal_scheduled' => $renewalScheduled,
        '@failed_scheduled' => $failedScheduled,
        '@failed_cancelled' => $failedCancelled,
        '@renewal_sent' => $renewalSent,
        '@failed_sent' => $failedSent,
        '@grace_revoked' => $graceRevoked,
        '@boost_sync_enqueued' => $boostSyncEnqueued,
      ],
    );

    return $stats;
  }

  /**
   * Handles day-0 dunning immediately when a renewal payment is declined.
   *
   * Clears any prior sequence rows for the subscription, queues the day-0
   * email, and records the row as sent (or scheduled if queueing fails).
   */
  public function onPaymentFailedImmediate(SubscriptionInterface $subscription): bool {
    $subId = (int) $subscription->id();
    $customer = $subscription->getCustomer();
    if ($customer instanceof UserInterface && $this->resolveGraceExpiry($customer) > $this->time->getRequestTime()) {
      return FALSE;
    }

    $this->database->delete('myeventlane_pro_failed_payment_sequence')
      ->condition('subscription_id', $subId)
      ->execute();

    $now = $this->time->getRequestTime();
    foreach ([3, 6] as $step) {
      $this->safeInsertFailedSequenceRow(
        $subId,
        $step,
        $now + ($step * 86400),
        self::STATUS_SCHEDULED,
        NULL,
      );
    }

    $recipient = $this->resolveRecipientEmail($subscription);
    if ($recipient === NULL) {
      $this->logger->error('Pro dunning: no recipient email for subscription @id day-0 notice.', ['@id' => (string) $subId]);
      $this->safeInsertFailedSequenceRow($subId, 0, $now, self::STATUS_SCHEDULED, NULL);
      return TRUE;
    }

    $messageId = $this->messagingManager->queue('pro_subscription_payment_failed_day_0', $recipient, [
      'first_name' => $this->resolveCustomerFirstName($subscription),
      'subscription_id' => $subId,
      'step' => 0,
      // Distinguish a genuine later failed-renewal cycle while keeping replay
      // events inside the current grace window idempotent.
      'failure_cycle' => $now,
    ]);

    if ($messageId === NULL) {
      $this->logger->error('Pro dunning: messaging queue rejected day-0 email for subscription @id.', ['@id' => (string) $subId]);
      $this->safeInsertFailedSequenceRow($subId, 0, $now, self::STATUS_SCHEDULED, NULL);
      return TRUE;
    }

    $this->safeInsertFailedSequenceRow($subId, 0, $now, self::STATUS_SENT, $now);
    $this->logger->notice('Pro dunning: subscription @id entered payment failure; day-0 organiser email queued.', [
      '@id' => (string) $subId,
    ]);
    return TRUE;
  }

  /**
   * Cancels outstanding dunning and queues one recovery confirmation.
   */
  public function onPaymentRecovered(SubscriptionInterface $subscription): int {
    $subscriptionId = (int) $subscription->id();
    $failureCycle = $this->database->select('myeventlane_pro_failed_payment_sequence', 'f')
      ->fields('f', ['scheduled_at'])
      ->condition('subscription_id', $subscriptionId)
      ->condition('step', 0)
      ->orderBy('scheduled_at', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $cancelled = (int) $this->database->update('myeventlane_pro_failed_payment_sequence')
      ->fields(['status' => self::STATUS_CANCELLED])
      ->condition('subscription_id', $subscriptionId)
      ->condition('status', self::STATUS_SCHEDULED)
      ->execute();

    // A normal successful renewal has no failed-payment cycle and must not
    // generate a recovery message.
    if ($failureCycle === FALSE) {
      return $cancelled;
    }

    $recipient = $this->resolveRecipientEmail($subscription);
    if ($recipient === NULL) {
      $this->logger->error('Pro recovery: no recipient email for subscription @id.', [
        '@id' => (string) $subscriptionId,
      ]);
      return $cancelled;
    }

    $cycle = (int) $failureCycle;
    $messageId = $this->messagingManager->queue(
      'pro_subscription_payment_recovered',
      $recipient,
      [
        'first_name' => $this->resolveCustomerFirstName($subscription),
        'subscription_id' => $subscriptionId,
        'failure_cycle' => $cycle,
        'manage_url' => '/vendor/pro/manage',
      ],
      [
        'idempotency_key' => sprintf('pro-payment-recovered:%d:%d', $subscriptionId, $cycle),
      ],
    );

    if ($messageId !== NULL) {
      $this->logger->notice('Pro recovery: organiser confirmation queued for subscription @id.', [
        '@id' => (string) $subscriptionId,
      ]);
    }

    return $cancelled;
  }

  /**
   * Schedules renewal reminders for subscriptions renewing within 3 days.
   */
  private function scheduleRenewalReminders(): int {
    $now = $this->time->getRequestTime();
    $scheduled = 0;

    foreach ($this->loadProSubscriptions() as $subscription) {
      if (!$this->stateResolver->isActive($subscription)) {
        continue;
      }

      $renewalTimestamp = $this->resolveRenewalTimestamp($subscription);
      if ($renewalTimestamp === NULL) {
        continue;
      }

      $secondsUntilRenewal = $renewalTimestamp - $now;
      if ($secondsUntilRenewal < 0 || $secondsUntilRenewal > self::RENEWAL_REMINDER_WINDOW_SECONDS) {
        continue;
      }

      $scheduledAt = $renewalTimestamp - self::RENEWAL_REMINDER_WINDOW_SECONDS;
      $scheduled += $this->insertRenewalReminder((int) $subscription->id(), $scheduledAt);
    }

    return $scheduled;
  }

  /**
   * Schedules day-3 and day-6 failed payment notifications (day-0 is immediate).
   */
  private function scheduleFailedPaymentSequence(): int {
    $scheduled = 0;
    $now = $this->time->getRequestTime();
    $graceSeconds = $this->getGraceSeconds();

    foreach ($this->loadProSubscriptions() as $subscription) {
      $customer = $subscription->getCustomer();
      if (!$customer instanceof UserInterface || $customer->isAnonymous()) {
        continue;
      }

      $graceExpiry = $this->resolveGraceExpiry($customer);
      if ($graceExpiry <= $now) {
        continue;
      }
      $sequenceStart = $graceExpiry > 0 ? ($graceExpiry - $graceSeconds) : $now;

      foreach ([3, 6] as $step) {
        $scheduledAt = $sequenceStart + ($step * 86400);
        $scheduled += $this->insertFailedSequence((int) $subscription->id(), $step, $scheduledAt);
      }
    }

    if ($scheduled > 0) {
      $this->logger->info('Pro dunning: scheduled @count follow-up failed-payment reminder rows (days 3 and 6).', [
        '@count' => (string) $scheduled,
      ]);
    }

    return $scheduled;
  }

  /**
   * Cancels pending failed-payment sequence rows when subscription recovers.
   */
  private function cancelFailedSequenceForRecovered(): int {
    $subscriptionIds = $this->database->select('myeventlane_pro_failed_payment_sequence', 'f')
      ->fields('f', ['subscription_id'])
      ->condition('f.status', self::STATUS_SCHEDULED)
      ->distinct()
      ->execute()
      ->fetchCol();

    if ($subscriptionIds === []) {
      return 0;
    }

    $updated = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');

    foreach (array_chunk(array_map('intval', $subscriptionIds), self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);
      foreach ($subscriptions as $subscription) {
        if (!$subscription instanceof SubscriptionInterface) {
          continue;
        }

        $customer = $subscription->getCustomer();
        if ($customer instanceof UserInterface && $this->resolveGraceExpiry($customer) > $this->time->getRequestTime()) {
          continue;
        }

        $updated += (int) $this->database->update('myeventlane_pro_failed_payment_sequence')
          ->fields([
            'status' => self::STATUS_CANCELLED,
          ])
          ->condition('subscription_id', (int) $subscription->id())
          ->condition('status', self::STATUS_SCHEDULED)
          ->execute();
      }
      $storage->resetCache($chunk);
    }

    return $updated;
  }

  /**
   * Dispatches due renewal reminder notifications.
   */
  private function dispatchDueRenewalReminders(): int {
    $now = $this->time->getRequestTime();
    $rows = $this->database->select('myeventlane_pro_renewal_notifications', 'r')
      ->fields('r')
      ->condition('status', self::STATUS_SCHEDULED)
      ->condition('scheduled_at', $now, '<=')
      ->range(0, self::BATCH_SIZE)
      ->execute()
      ->fetchAllAssoc('id');

    if ($rows === []) {
      return 0;
    }

    $sent = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $subscriptionIds = array_values(array_filter(array_map(static function (object $row): int {
      return isset($row->subscription_id) ? (int) $row->subscription_id : 0;
    }, $rows)));
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
    $subscriptions = $storage->loadMultiple($subscriptionIds);

    foreach ($rows as $id => $row) {
      $subscriptionId = isset($row->subscription_id) ? (int) $row->subscription_id : 0;
      $subscription = $subscriptions[$subscriptionId] ?? NULL;
      if (!$subscription instanceof SubscriptionInterface) {
        $this->markRenewalNotification((int) $id, self::STATUS_FAILED);
        continue;
      }

      if (!$this->stateResolver->isActive($subscription)) {
        $this->markRenewalNotification((int) $id, self::STATUS_CANCELLED);
        continue;
      }

      $recipient = $this->resolveRecipientEmail($subscription);
      $renewalTimestamp = $this->resolveRenewalTimestamp($subscription);
      if ($recipient === NULL || $renewalTimestamp === NULL) {
        $this->markRenewalNotification((int) $id, self::STATUS_FAILED);
        continue;
      }

      $roi = $this->buildRoiSummary($subscription);
      $messageId = $this->messagingManager->queue('pro_subscription_renewal_reminder', $recipient, [
        'first_name' => $this->resolveCustomerFirstName($subscription),
        'next_billing_date' => date('F j, Y', $renewalTimestamp),
        'last_month_recovered_revenue' => number_format((float) $roi['recovered_revenue'], 2, '.', ''),
        'roi_multiple' => number_format((float) $roi['roi_multiple'], 2, '.', ''),
        'subscription_id' => (int) $subscription->id(),
      ]);

      if ($messageId === NULL) {
        $this->markRenewalNotification((int) $id, self::STATUS_FAILED);
        continue;
      }

      $this->markRenewalNotification((int) $id, self::STATUS_SENT, $now);
      $sent++;
    }

    return $sent;
  }

  /**
   * Dispatches due failed-payment sequence notifications.
   */
  private function dispatchDueFailedPaymentEmails(): int {
    $now = $this->time->getRequestTime();
    $rows = $this->database->select('myeventlane_pro_failed_payment_sequence', 'f')
      ->fields('f')
      ->condition('status', self::STATUS_SCHEDULED)
      ->condition('scheduled_at', $now, '<=')
      ->range(0, self::BATCH_SIZE)
      ->execute()
      ->fetchAllAssoc('id');

    if ($rows === []) {
      return 0;
    }

    $sent = 0;
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $subscriptionIds = array_values(array_filter(array_map(static function (object $row): int {
      return isset($row->subscription_id) ? (int) $row->subscription_id : 0;
    }, $rows)));
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
    $subscriptions = $storage->loadMultiple($subscriptionIds);

    foreach ($rows as $id => $row) {
      $subscriptionId = isset($row->subscription_id) ? (int) $row->subscription_id : 0;
      $subscription = $subscriptions[$subscriptionId] ?? NULL;
      if (!$subscription instanceof SubscriptionInterface) {
        $this->markFailedSequence((int) $id, self::STATUS_FAILED);
        continue;
      }

      $customer = $subscription->getCustomer();
      if (!$customer instanceof UserInterface || $this->resolveGraceExpiry($customer) <= $now) {
        $this->markFailedSequence((int) $id, self::STATUS_CANCELLED);
        continue;
      }

      $recipient = $this->resolveRecipientEmail($subscription);
      if ($recipient === NULL) {
        $this->markFailedSequence((int) $id, self::STATUS_FAILED);
        continue;
      }

      $step = isset($row->step) ? (int) $row->step : -1;
      $template = match ($step) {
        0 => 'pro_subscription_payment_failed_day_0',
        3 => 'pro_subscription_payment_failed_day_3',
        6 => 'pro_subscription_payment_failed_day_6',
        default => '',
      };

      if ($template === '') {
        $this->markFailedSequence((int) $id, self::STATUS_FAILED);
        continue;
      }

      $messageId = $this->messagingManager->queue($template, $recipient, [
        'first_name' => $this->resolveCustomerFirstName($subscription),
        'subscription_id' => (int) $subscription->id(),
        'step' => $step,
      ]);

      if ($messageId === NULL) {
        $this->markFailedSequence((int) $id, self::STATUS_FAILED);
        continue;
      }

      $this->markFailedSequence((int) $id, self::STATUS_SENT, $now);
      $sent++;
    }

    return $sent;
  }

  /**
   * Idempotent insert for renewal reminder rows.
   */
  private function insertRenewalReminder(int $subscriptionId, int $scheduledAt): int {
    try {
      $this->database->insert('myeventlane_pro_renewal_notifications')
        ->fields([
          'subscription_id' => $subscriptionId,
          'scheduled_at' => $scheduledAt,
          'status' => self::STATUS_SCHEDULED,
        ])
        ->execute();
      return 1;
    }
    catch (IntegrityConstraintViolationException) {
      return 0;
    }
  }

  /**
   * Idempotent insert for failed sequence rows.
   */
  private function insertFailedSequence(int $subscriptionId, int $step, int $scheduledAt): int {
    try {
      $this->insertFailedSequenceWithStatus($subscriptionId, $step, $scheduledAt, self::STATUS_SCHEDULED, NULL);
      return 1;
    }
    catch (IntegrityConstraintViolationException) {
      return 0;
    }
  }

  /**
   * Inserts a failed-payment sequence row with explicit status.
   */
  private function insertFailedSequenceWithStatus(int $subscriptionId, int $step, int $scheduledAt, string $status, ?int $sentAt): void {
    $fields = [
      'subscription_id' => $subscriptionId,
      'step' => $step,
      'scheduled_at' => $scheduledAt,
      'status' => $status,
    ];
    if ($sentAt !== NULL) {
      $fields['sent_at'] = $sentAt;
    }

    $this->database->insert('myeventlane_pro_failed_payment_sequence')
      ->fields($fields)
      ->execute();
  }

  /**
   * Inserts a failed-payment row; logs and swallows duplicate-key races.
   */
  private function safeInsertFailedSequenceRow(int $subscriptionId, int $step, int $scheduledAt, string $status, ?int $sentAt): void {
    try {
      $this->insertFailedSequenceWithStatus($subscriptionId, $step, $scheduledAt, $status, $sentAt);
    }
    catch (IntegrityConstraintViolationException) {
      $this->logger->warning('Pro dunning: could not insert failed-payment sequence row for subscription @s step @t (duplicate or constraint).', [
        '@s' => (string) $subscriptionId,
        '@t' => (string) $step,
      ]);
    }
  }

  /**
   * Grace period length in seconds from Pro settings.
   */
  private function getGraceSeconds(): int {
    $days = (int) ($this->configFactory->get('myeventlane_pro.settings')->get('grace_days') ?? 7);
    return max(1, $days) * 86400;
  }

  /**
   * Marks a renewal notification row.
   */
  private function markRenewalNotification(int $id, string $status, ?int $sentAt = NULL): void {
    $fields = ['status' => $status];
    if ($sentAt !== NULL) {
      $fields['sent_at'] = $sentAt;
    }

    $this->database->update('myeventlane_pro_renewal_notifications')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();

    if ($status === self::STATUS_FAILED) {
      $this->logger->error('Failed to dispatch Pro renewal reminder row @id.', ['@id' => $id]);
    }
  }

  /**
   * Marks a failed payment sequence row.
   */
  private function markFailedSequence(int $id, string $status, ?int $sentAt = NULL): void {
    $fields = ['status' => $status];
    if ($sentAt !== NULL) {
      $fields['sent_at'] = $sentAt;
    }

    $this->database->update('myeventlane_pro_failed_payment_sequence')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();

    if ($status === self::STATUS_FAILED) {
      $this->logger->error('Failed to dispatch Pro failed-payment sequence row @id.', ['@id' => $id]);
    }
  }

  /**
   * Resolves renewal timestamp for a subscription's current period end.
   */
  private function resolveRenewalTimestamp(SubscriptionInterface $subscription): ?int {
    if (!method_exists($subscription, 'getNextRenewalTime')) {
      return NULL;
    }

    $timestamp = (int) $subscription->getNextRenewalTime();
    return $timestamp > 0 ? $timestamp : NULL;
  }

  /**
   * Resolves recipient email from subscription customer.
   */
  private function resolveRecipientEmail(SubscriptionInterface $subscription): ?string {
    $customer = $subscription->getCustomer();
    if (!$customer instanceof UserInterface) {
      return NULL;
    }

    $email = trim((string) $customer->getEmail());
    return $email !== '' ? $email : NULL;
  }

  /**
   * Resolves display name token for lifecycle emails.
   */
  private function resolveCustomerFirstName(SubscriptionInterface $subscription): string {
    $customer = $subscription->getCustomer();
    if (!$customer instanceof UserInterface) {
      return 'there';
    }

    $name = trim((string) $customer->getDisplayName());
    return $name !== '' ? $name : 'there';
  }

  /**
   * Builds ROI context for reminder email payload.
   *
   * @return array{recovered_revenue: float, roi_multiple: float}
   *   ROI summary.
   */
  private function buildRoiSummary(SubscriptionInterface $subscription): array {
    $customer = $subscription->getCustomer();
    if (!$customer instanceof UserInterface) {
      return [
        'recovered_revenue' => 0.0,
        'roi_multiple' => 0.0,
      ];
    }

    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $customer->id())
      ->range(0, 1)
      ->execute();

    if ($vendorIds === []) {
      return [
        'recovered_revenue' => 0.0,
        'roi_multiple' => 0.0,
      ];
    }

    $vendor = $vendorStorage->load((int) reset($vendorIds));
    if (!$vendor instanceof Vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return [
        'recovered_revenue' => 0.0,
        'roi_multiple' => 0.0,
      ];
    }

    $store = $vendor->get('field_vendor_store')->entity;
    if (!$store || $store->id() === NULL) {
      return [
        'recovered_revenue' => 0.0,
        'roi_multiple' => 0.0,
      ];
    }

    $roi = $this->recoveryAnalyticsService->estimateProROI((int) $store->id(), 1);
    return [
      'recovered_revenue' => (float) ($roi['recovered_revenue'] ?? 0.0),
      'roi_multiple' => (float) ($roi['roi_multiple'] ?? 0.0),
    ];
  }

  /**
   * Resolves grace expiry timestamp from user field.
   */
  private function resolveGraceExpiry(UserInterface $user): int {
    if (!$user->hasField('field_pro_grace_expires')) {
      return 0;
    }

    return (int) ($user->get('field_pro_grace_expires')->value ?? 0);
  }

  /**
   * Loads Pro subscriptions in memory-safe batches.
   *
   * @return \Generator<int, \Drupal\commerce_recurring\Entity\SubscriptionInterface>
   *   Subscription iterator.
   */
  private function loadProSubscriptions(): \Generator {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('subscription_id', 'ASC')
      ->execute();

    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface[] $subscriptions */
      $subscriptions = $storage->loadMultiple($chunk);
      foreach ($subscriptions as $subscription) {
        if ($subscription instanceof SubscriptionInterface) {
          yield $subscription;
        }
      }
      $storage->resetCache($chunk);
    }
  }

}
