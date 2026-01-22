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
 * Queue worker for event cancellation notifications.
 *
 * @QueueWorker(
 *   id = "automation_event_cancelled",
 *   title = @Translation("Event cancellation notification worker"),
 *   cron = {"time" = 30}
 * )
 */
final class EventCancelledWorker extends AutomationWorkerBase {

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
    $cancelReason = $data['cancel_reason'] ?? NULL;

    if (!$dispatchId || !$eventId || !$recipientEmail) {
      $this->logger->error('EventCancelledWorker: Missing required data');
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event) {
      $this->dispatchService->markFailed($dispatchId, 'Event not found');
      return;
    }

    // Check idempotency.
    $recipientHash = $this->dispatchService->hashRecipient($recipientEmail);
    if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_EVENT_CANCELLED, $recipientHash)) {
      $this->dispatchService->markSkipped($dispatchId, 'Already sent');
      return;
    }

    // Prepare email context.
    $context = [
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'cancel_reason' => $cancelReason ?? 'This event has been cancelled.',
    ];

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $startDate = $event->get('field_event_start')->date;
      if ($startDate) {
        $context['event_start'] = $this->dateFormatter->format($startDate->getTimestamp(), 'custom', 'F j, Y g:ia T');
      }
    }

    // Check if event has paid tickets (needs refund info).
    // @todo Check if event has paid tickets and include refund process text.
    $context['has_paid_tickets'] = FALSE;
    $context['refund_info'] = 'If you purchased tickets, refunds will be processed automatically.';

    // Send email.
    try {
      $this->messagingManager->queue('event_cancelled', $recipientEmail, $context);
      $this->dispatchService->markSent($dispatchId);
      $this->auditLogger->log(
        $eventId,
        'notification_sent',
        AutomationDispatchService::TYPE_EVENT_CANCELLED,
        $recipientHash,
        ['cancel_reason' => $cancelReason]
      );
      $this->logger->info('Sent cancellation notification for event @id to @email', [
        '@id' => $eventId,
        '@email' => $recipientEmail,
      ]);
    }
    catch (\Exception $e) {
      $this->dispatchService->markFailed($dispatchId, $e->getMessage());
      $this->logger->error('Failed to send cancellation notification: @message', ['@message' => $e->getMessage()]);
    }
  }

}
