<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Queues and tracks reliable email delivery.
 */
final class ReliableEmailSender {

  private const QUEUE_NAME = 'myeventlane_reliable_mail';
  private const STATUS_COLLECTION = 'myeventlane_email_delivery_status';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly LoggerInterface $logger,
    private readonly MELMonitoringService $monitoringService,
  ) {}

  /**
   * Queues an email delivery request.
   */
  public function queueEmail(
    string $eventType,
    string $to,
    string $subject,
    string $body,
    array $context = [],
    array $headers = []
  ): string {
    $messageId = $this->generateMessageId();
    $payload = [
      'message_id' => $messageId,
      'event_type' => $eventType,
      'to' => $to,
      'subject' => $subject,
      'body' => $body,
      'context' => $context,
      'headers' => $headers,
      'attempts' => 0,
      'created_at' => time(),
    ];

    $this->queueFactory->get(self::QUEUE_NAME)->createItem($payload);
    $this->setStatus($messageId, [
      'status' => 'queued',
      'payload' => $payload,
      'updated_at' => time(),
    ]);

    return $messageId;
  }

  /**
   * Stores status update for a message.
   */
  public function setStatus(string $messageId, array $status): void {
    $this->getStatusStore()->set($messageId, $status);
  }

  /**
   * Returns status for a queued message, if known.
   */
  public function getStatus(string $messageId): ?array {
    $status = $this->getStatusStore()->get($messageId);
    return is_array($status) ? $status : NULL;
  }

  /**
   * Marks an email send failure and logs monitoring details.
   */
  public function markFailure(string $messageId, array $payload, string $errorMessage, int $attempt): void {
    $this->setStatus($messageId, [
      'status' => 'failed',
      'attempt' => $attempt,
      'error' => $errorMessage,
      'payload' => $payload,
      'updated_at' => time(),
    ]);

    $this->logger->error('Reliable email send failed for message {message_id} on attempt {attempt}: {error}', [
      'message_id' => $messageId,
      'attempt' => $attempt,
      'error' => $errorMessage,
    ]);
    $this->monitoringService->monitorCriticalFailure('email_delivery_failure', [
      'message_id' => $messageId,
      'attempt' => $attempt,
      'recipient' => $payload['to'] ?? '',
      'event_type' => $payload['event_type'] ?? '',
    ]);
  }

  /**
   * Returns store for email delivery statuses.
   */
  private function getStatusStore(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::STATUS_COLLECTION);
  }

  /**
   * Generates a random UUID-like ID.
   */
  private function generateMessageId(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

}
