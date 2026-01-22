<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Plugin\QueueWorker;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_automation\Service\AutomationDispatchService;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_attendee\Service\AttendeeRepositoryResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for 24-hour reminder notifications.
 *
 * @QueueWorker(
 *   id = "automation_reminder_24h",
 *   title = @Translation("24-hour reminder notification worker"),
 *   cron = {"time" = 30}
 * )
 */
final class Reminder24hWorker extends AutomationWorkerBase {

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
    protected readonly AttendeeRepositoryResolver $attendeeRepositoryResolver,
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
      $container->get('myeventlane_attendee.repository_resolver'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $dispatchId = $data['dispatch_id'] ?? NULL;
    $eventId = $data['event_id'] ?? NULL;
    $recipientEmail = $data['recipient_email'] ?? NULL;

    if (!$dispatchId || !$eventId || !$recipientEmail) {
      $this->logger->error('Reminder24hWorker: Missing required data');
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event) {
      $this->dispatchService->markFailed($dispatchId, 'Event not found');
      return;
    }

    // Check idempotency.
    $recipientHash = $this->dispatchService->hashRecipient($recipientEmail);
    if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_REMINDER_24H, $recipientHash)) {
      $this->dispatchService->markSkipped($dispatchId, 'Already sent');
      return;
    }

    // Skip if event is cancelled or ended.
    if ($event->hasField('field_event_state')) {
      $state = $event->get('field_event_state')->value;
      if (in_array($state, ['cancelled', 'ended'], TRUE)) {
        $this->dispatchService->markSkipped($dispatchId, 'Event is ' . $state);
        return;
      }
    }

    // Prepare email context.
    $context = [
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'reminder_type' => '24h',
    ];

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $startDate = $event->get('field_event_start')->date;
      if ($startDate) {
        $context['event_start'] = $this->dateFormatter->format($startDate->getTimestamp(), 'custom', 'F j, Y g:ia T');
        $context['event_start_date'] = $this->dateFormatter->format($startDate->getTimestamp(), 'custom', 'F j, Y');
        $context['event_start_time'] = $this->dateFormatter->format($startDate->getTimestamp(), 'custom', 'g:ia T');
      }
    }

    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $context['venue'] = $event->get('field_venue_name')->value;
    }

    // Send email.
    try {
      $this->messagingManager->queue('event_reminder_24h', $recipientEmail, $context);
      $this->dispatchService->markSent($dispatchId);
      $this->auditLogger->log(
        $eventId,
        'notification_sent',
        AutomationDispatchService::TYPE_REMINDER_24H,
        $recipientHash
      );
      $this->logger->info('Sent 24h reminder for event @id to @email', [
        '@id' => $eventId,
        '@email' => $recipientEmail,
      ]);
    }
    catch (\Exception $e) {
      $this->dispatchService->markFailed($dispatchId, $e->getMessage());
      $this->logger->error('Failed to send 24h reminder: @message', ['@message' => $e->getMessage()]);
    }
  }

}
