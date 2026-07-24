<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_messaging\Service\AttendeeRecipientResolver;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_vendor_comms\Service\EventRecipientResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for sending organiser event messages.
 *
 * @QueueWorker(
 *   id = "vendor_event_comms",
 *   title = @Translation("Organiser event messages"),
 *   cron = {"time" = 60}
 * )
 */
final class VendorEventCommsWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs VendorEventCommsWorker.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessagingManager $messagingManager,
    private readonly EventRecipientResolver $recipientResolver,
    private readonly AttendeeRecipientResolver $attendeeRecipientResolver,
    private readonly LoggerInterface $logger,
    private readonly Connection $database,
    private readonly TimeInterface $time,
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
      $container->get('myeventlane_messaging.attendee_recipient_resolver'),
      $container->get('logger.factory')->get('myeventlane_vendor_comms'),
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $logId = isset($data['log_id']) ? (int) $data['log_id'] : NULL;
    $eventId = isset($data['event_id']) ? (int) $data['event_id'] : NULL;
    $messageType = $data['message_type'] ?? 'update';
    $audience = (string) ($data['audience'] ?? 'everyone');
    $subject = $data['subject'] ?? '';
    $body = $data['body'] ?? '';

    if (!$logId || !$eventId || empty($subject) || empty($body)) {
      $this->logger->error('VendorEventCommsWorker: Missing required data');
      return;
    }

    $this->database->update('myeventlane_event_comms_log')
      ->fields(['status' => 'sending'])
      ->condition('id', $logId)
      ->execute();

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event) {
      $this->logger->error('VendorEventCommsWorker: Event @id not found', ['@id' => $eventId]);
      $this->markFailed((int) $logId);
      return;
    }

    $recipients = $this->resolveRecipients($event, $audience);
    if (empty($recipients)) {
      $this->logger->warning('VendorEventCommsWorker: No recipients for event @id audience @audience', [
        '@id' => $eventId,
        '@audience' => $audience,
      ]);
      $this->markCompleted($logId, 0, 0);
      return;
    }

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
          'subject' => $subject,
          'custom_subject' => $subject,
        ];

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

    $this->markCompleted((int) $logId, $sentCount, $failedCount);

    $this->logger->info('message_sent log=@log_id sent=@sent failed=@failed', [
      '@sent' => $sentCount,
      '@failed' => $failedCount,
      '@log_id' => $logId,
    ]);
  }

  /**
   * Resolves recipient emails for the selected audience.
   *
   * @return list<string>
   *   Emails.
   */
  private function resolveRecipients($event, string $audience): array {
    return match ($audience) {
      'ticket_holders' => $this->recipientResolver->getRecipientEmails($event),
      'rsvp' => $this->attendeeRecipientResolver->resolveRsvpEmails($event),
      default => $this->attendeeRecipientResolver->resolveEmails($event),
    };
  }

  /**
   * Marks log entry as completed.
   */
  private function markCompleted(int $logId, int $sentCount, int $failedCount): void {
    $now = $this->time->getRequestTime();
    // Total queue failure must not look like a successful send in history/KPIs.
    $status = ($sentCount === 0 && $failedCount > 0) ? 'failed' : 'completed';
    $this->database->update('myeventlane_event_comms_log')
      ->fields([
        'status' => $status,
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
    $now = $this->time->getRequestTime();
    $this->database->update('myeventlane_event_comms_log')
      ->fields([
        'status' => 'failed',
        'completed_at' => $now,
      ])
      ->condition('id', $logId)
      ->execute();
  }

}
