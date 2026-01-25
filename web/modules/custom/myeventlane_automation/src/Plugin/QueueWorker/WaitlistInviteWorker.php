<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_automation\Service\AutomationDispatchService;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for waitlist invitation notifications.
 *
 * @QueueWorker(
 *   id = "automation_waitlist_invite",
 *   title = @Translation("Waitlist invitation notification worker"),
 *   cron = {"time" = 30}
 * )
 */
final class WaitlistInviteWorker extends AutomationWorkerBase {

  /**
   * Constructs the worker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AutomationDispatchService $dispatchService,
    AutomationAuditLogger $auditLogger,
    LoggerInterface $logger,
    protected readonly MessagingManager $messagingManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AttendanceWaitlistManager $waitlistManager,
    protected readonly TimeInterface $time,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $dispatchService, $auditLogger, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_automation.dispatch'),
      $container->get('myeventlane_automation.audit_logger'),
      $container->get('logger.factory')->get('myeventlane_automation'),
      $container->get('myeventlane_messaging.manager'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_event_attendees.waitlist'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $dispatchId = $data['dispatch_id'] ?? NULL;
    $eventId = $data['event_id'] ?? NULL;
    $attendeeId = $data['attendee_id'] ?? NULL;

    if (!$dispatchId || !$eventId || !$attendeeId) {
      $this->logger->error('WaitlistInviteWorker: Missing required data');
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event) {
      $this->dispatchService->markFailed($dispatchId, 'Event not found');
      return;
    }

    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
    $attendee = $attendeeStorage->load($attendeeId);
    if (!$attendee) {
      $this->dispatchService->markFailed($dispatchId, 'Attendee not found');
      return;
    }

    $email = $attendee->getEmail();
    if (empty($email)) {
      $this->dispatchService->markFailed($dispatchId, 'Attendee email not found');
      return;
    }

    // Check idempotency.
    $recipientHash = $this->dispatchService->hashRecipient($email);
    if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_WAITLIST_INVITE, $recipientHash)) {
      $this->dispatchService->markSkipped($dispatchId, 'Already sent');
      return;
    }

    // Skip if event is cancelled or ended, or not live.
    if ($event->hasField('field_event_state')) {
      $state = $event->get('field_event_state')->value;
      if (in_array($state, ['cancelled', 'ended'], TRUE)) {
        $this->dispatchService->markSkipped($dispatchId, 'Event is ' . $state);
        return;
      }
    }

    // Generate time-limited invite link (2 hours).
    // @todo Implement secure token generation for waitlist invite links.
    $expiresAt = $this->time->getRequestTime() + (2 * 3600);
    $siteUuid = (string) ($this->configFactory->get('system.site')->get('uuid') ?? '');
    if ($siteUuid === '') {
      // Defensive check: keep existing behaviour (still generate token) but log loudly.
      $this->logger->error('WaitlistInviteWorker: system.site UUID is missing; invite token entropy reduced.', [
        'event_id' => (int) $eventId,
      ]);
    }
    $inviteToken = hash('sha256', $attendeeId . $eventId . $expiresAt . $siteUuid);

    $inviteUrl = Url::fromRoute('myeventlane_automation.waitlist_claim', [
      'event' => $eventId,
      'token' => $inviteToken,
    ], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();

    // Prepare email context.
    $context = [
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'invite_url' => $inviteUrl,
      'expires_at' => $this->dateFormatter->format($expiresAt, 'custom', 'g:ia T'),
    ];

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $startDate = $event->get('field_event_start')->date;
      if ($startDate) {
        $context['event_start'] = $this->dateFormatter->format($startDate->getTimestamp(), 'custom', 'F j, Y g:ia T');
      }
    }

    // Send email.
    try {
      $this->messagingManager->queue('waitlist_invite', $email, $context);
      $this->dispatchService->markSent($dispatchId);
      $this->auditLogger->log(
        $eventId,
        'notification_sent',
        AutomationDispatchService::TYPE_WAITLIST_INVITE,
        $recipientHash,
        ['attendee_id' => $attendeeId, 'token' => $inviteToken]
      );
      $this->logger->info('Sent waitlist invite for event @id to @email', [
        '@id' => $eventId,
        '@email' => $email,
      ]);
    }
    catch (\Exception $e) {
      $this->dispatchService->markFailed($dispatchId, $e->getMessage());
      $this->logger->error('Failed to send waitlist invite: @message', ['@message' => $e->getMessage()]);
    }
  }

}
