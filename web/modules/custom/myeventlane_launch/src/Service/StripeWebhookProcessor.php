<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

/**
 * Handles Stripe webhook idempotency and retry tracking.
 */
final class StripeWebhookProcessor {

  private const COLLECTION = 'myeventlane_webhook_events';

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly LoggerInterface $logger,
    private readonly MELMonitoringService $monitoringService,
  ) {}

  /**
   * Extracts Stripe event ID from the raw request payload.
   */
  public function extractEventId(Request $request): ?string {
    $payload = (string) $request->getContent();
    if ($payload === '') {
      return NULL;
    }

    try {
      $decoded = json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->monitoringService->monitorWebhookFailure([
        'reason' => 'invalid_json_payload',
      ], $e);
      return NULL;
    }

    $eventId = $decoded['id'] ?? NULL;
    return is_string($eventId) && $eventId !== '' ? $eventId : NULL;
  }

  /**
   * Returns TRUE when the webhook event has already been processed.
   */
  public function isProcessed(string $eventId): bool {
    $record = $this->getStore()->get($eventId);
    return is_array($record) && ($record['status'] ?? NULL) === 'processed';
  }

  /**
   * Persists a successful webhook processing marker.
   */
  public function markProcessed(string $eventId, array $context = []): void {
    $this->getStore()->set($eventId, [
      'status' => 'processed',
      'processed_at' => time(),
      'context' => $context,
    ]);
  }

  /**
   * Records a failed attempt so retries are traceable.
   */
  public function recordFailure(string $eventId, int $attempt, string $errorMessage): void {
    $existing = $this->getStore()->get($eventId);
    $failureCount = is_array($existing) ? (int) ($existing['failure_count'] ?? 0) : 0;

    $this->getStore()->set($eventId, [
      'status' => 'failed',
      'failure_count' => $failureCount + 1,
      'last_attempt' => $attempt,
      'last_error' => $errorMessage,
      'failed_at' => time(),
    ]);

    $this->logger->error('Stripe webhook processing attempt failed for event {event_id} on attempt {attempt}: {message}', [
      'event_id' => $eventId,
      'attempt' => $attempt,
      'message' => $errorMessage,
    ]);
  }

  /**
   * Returns internal key-value store.
   */
  private function getStore(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
