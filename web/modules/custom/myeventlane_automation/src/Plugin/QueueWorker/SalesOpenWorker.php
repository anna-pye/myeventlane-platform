<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Plugin\QueueWorker;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_automation\Service\AutomationDispatchService;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for sales opening notifications.
 *
 * @QueueWorker(
 *   id = "automation_sales_open",
 *   title = @Translation("Sales opening notification worker"),
 *   cron = {"time" = 30}
 * )
 */
final class SalesOpenWorker extends AutomationWorkerBase {

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
    protected readonly EventStateResolverInterface $stateResolver,
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
      $container->get('myeventlane_event_state.resolver'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $dispatchId = $data['dispatch_id'] ?? NULL;
    $eventId = $data['event_id'] ?? NULL;

    if (!$dispatchId || !$eventId) {
      $this->logger->error('SalesOpenWorker: Missing dispatch_id or event_id');
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event) {
      $this->dispatchService->markFailed($dispatchId, 'Event not found');
      return;
    }

    // Check idempotency.
    $vendorHash = $this->dispatchService->hashRecipient((string) $event->getOwnerId());
    if ($this->dispatchService->isAlreadySent($eventId, AutomationDispatchService::TYPE_SALES_OPEN, $vendorHash)) {
      $this->dispatchService->markSkipped($dispatchId, 'Already sent');
      return;
    }

    // Get vendor email.
    $vendor = $this->entityTypeManager->getStorage('user')->load($event->getOwnerId());
    if (!$vendor || !$vendor->getEmail()) {
      $this->dispatchService->markFailed($dispatchId, 'Vendor email not found');
      return;
    }

    $vendorEmail = $vendor->getEmail();

    // Prepare email context.
    $context = [
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
    ];

    $salesStart = $this->stateResolver->getSalesStart($event);
    if ($salesStart) {
      $context['sales_start'] = $this->dateFormatter->format($salesStart, 'custom', 'F j, Y g:ia T');
    }

    // Send email.
    try {
      $this->messagingManager->queue('sales_open', $vendorEmail, $context);
      $this->dispatchService->markSent($dispatchId);
      $this->auditLogger->log(
        $eventId,
        'notification_sent',
        AutomationDispatchService::TYPE_SALES_OPEN,
        $vendorHash
      );
      $this->logger->info('Sent sales_open notification for event @id', ['@id' => $eventId]);
    }
    catch (\Exception $e) {
      $this->dispatchService->markFailed($dispatchId, $e->getMessage());
      $this->logger->error('Failed to send sales_open notification: @message', ['@message' => $e->getMessage()]);
    }
  }

}
