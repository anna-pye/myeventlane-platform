<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for sending vendor event communications.
 *
 * @QueueWorker(
 *   id = "vendor_event_comms",
 *   title = @Translation("Vendor Event Communications"),
 *   cron = {"time" = 60}
 * )
 */
final class VendorEventCommsWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs VendorEventCommsWorker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   * @param \Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver $recipientResolver
   *   The recipient resolver.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessagingManager $messagingManager,
    private readonly EventRecipientResolver $recipientResolver,
    private readonly LoggerInterface $logger,
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
      $container->get('myeventlane_messaging.manager'),
      $container->get('myeventlane_vendor_comms.recipient_resolver'),
      $container->get('logger.factory')->get('myeventlane_vendor_comms')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $logId = isset($data['log_id']) ? (int) $data['log_id'] : NULL;
    $eventId = isset($data['event_id']) ? (int) $data['event_id'] : NULL;
    $messageType = $data['message_type'] ?? 'update';
    $subject = $data['subject'] ?? '';
    $body = $data['body'] ?? '';

    if (!$logId || !$eventId || empty($subject) || empty($body)) {
      $this->logger->error('VendorEventCommsWorker: Missing required data');
      return;
    }

    // Update status to sending.
    \Drupal::database()->update('myeventlane_event_comms_log')
      ->fields(['status' => 'sending'])
      ->condition('id', $logId)
      ->execute();

    // Load event.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event) {
      $this->logger->error('VendorEventCommsWorker: Event @id not found', ['@id' => $eventId]);
      $this->markFailed((int) $logId);
      return;
    }

    // Get recipients.
    $recipients = $this->recipientResolver->getRecipientEmails($event);
    if (empty($recipients)) {
      $this->logger->warning('VendorEventCommsWorker: No recipients for event @id', ['@id' => $eventId]);
      $this->markCompleted($logId, 0, 0);
      return;
    }

    // Send emails.
    $sentCount = 0;
    $failedCount = 0;
    $templateKey = "vendor_event_{$messageType}";

    foreach ($recipients as $email) {
      try {
        $context = [
          'event' => $event,
          'event_title' => $event->label(),
          'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
          'message_body' => $body,
          'message_type' => $messageType,
        ];

        // Add custom subject to context for template override.
        $context['subject'] = $subject;
        $context['custom_subject'] = $subject;

        $this->messagingManager->queue($templateKey, $email, $context, [
          'langcode' => $event->language()->getId(),
        ]);

        $sentCount++;
      }
      catch (\Exception $e) {
        $failedCount++;
        $this->logger->error('VendorEventCommsWorker: Failed to queue email to @email: @message', [
          '@email' => $email,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Mark as completed.
    $this->markCompleted((int) $logId, $sentCount, $failedCount);

    $this->logger->info('VendorEventCommsWorker: Sent @sent, failed @failed for log @log_id', [
      '@sent' => $sentCount,
      '@failed' => $failedCount,
      '@log_id' => $logId,
    ]);
  }

  /**
   * Marks log entry as completed.
   */
  private function markCompleted(int $logId, int $sentCount, int $failedCount): void {
    $now = \Drupal::time()->getRequestTime();
    \Drupal::database()->update('myeventlane_event_comms_log')
      ->fields([
        'status' => 'completed',
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'completed_at' => $now,
      ])
      ->condition('id', $logId)
      ->execute();
  }

  /**
   * Marks log entry as failed.
   */
  private function markFailed(int $logId): void {
    $now = \Drupal::time()->getRequestTime();
    \Drupal::database()->update('myeventlane_event_comms_log')
      ->fields([
        'status' => 'failed',
        'completed_at' => $now,
      ])
      ->condition('id', $logId)
      ->execute();
  }

}

