<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_automation\Service\AutomationDispatchService;
use Drupal\myeventlane_automation\Service\AutomationAuditLogger;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for export ready notifications.
 *
 * @QueueWorker(
 *   id = "automation_export_ready",
 *   title = @Translation("Export ready notification worker"),
 *   cron = {"time" = 30}
 * )
 */
final class ExportReadyWorker extends AutomationWorkerBase {

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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $dispatchId = $data['dispatch_id'] ?? NULL;
    $eventId = $data['event_id'] ?? NULL;
    // 'csv' or 'ics'
    $exportType = $data['export_type'] ?? NULL;
    $fileUrl = $data['file_url'] ?? NULL;

    if (!$dispatchId || !$eventId || !$exportType || !$fileUrl) {
      $this->logger->error('ExportReadyWorker: Missing required data');
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event) {
      $this->dispatchService->markFailed($dispatchId, 'Event not found');
      return;
    }

    // Get vendor email.
    $vendor = $this->entityTypeManager->getStorage('user')->load($event->getOwnerId());
    if (!$vendor || !$vendor->getEmail()) {
      $this->dispatchService->markFailed($dispatchId, 'Vendor email not found');
      return;
    }

    $vendorEmail = $vendor->getEmail();

    // Check idempotency.
    $vendorHash = $this->dispatchService->hashRecipient($vendorEmail);
    $notificationType = $exportType === 'csv'
      ? AutomationDispatchService::TYPE_EXPORT_READY_CSV
      : AutomationDispatchService::TYPE_EXPORT_READY_ICS;

    if ($this->dispatchService->isAlreadySent($eventId, $notificationType, $vendorHash)) {
      $this->dispatchService->markSkipped($dispatchId, 'Already sent');
      return;
    }

    // Prepare email context.
    $context = [
      'event_title' => $event->label(),
      'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'export_type' => strtoupper($exportType),
      'download_url' => $fileUrl,
    ];

    // Send email.
    try {
      $templateKey = 'export_ready_' . $exportType;
      $this->messagingManager->queue($templateKey, $vendorEmail, $context);
      $this->dispatchService->markSent($dispatchId);
      $this->auditLogger->log(
        $eventId,
        'notification_sent',
        $notificationType,
        $vendorHash,
        ['export_type' => $exportType, 'file_url' => $fileUrl]
      );
      $this->logger->info('Sent export_ready notification for event @id (@type) to vendor', [
        '@id' => $eventId,
        '@type' => $exportType,
      ]);
    }
    catch (\Exception $e) {
      $this->dispatchService->markFailed($dispatchId, $e->getMessage());
      $this->logger->error('Failed to send export_ready notification: @message', ['@message' => $e->getMessage()]);
    }
  }

}
