<?php

declare(strict_types=1);

namespace Drupal\myeventlane_webhooks\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;

/**
 * Service for delivering webhooks to subscribers.
 */
final class WebhookDeliveryService {

  /**
   * Maximum retry attempts.
   */
  public const MAX_RETRIES = 5;

  /**
   * Retry delays (in seconds) for each attempt.
   */
  // 1min, 5min, 15min, 1hr, 24hr
  public const RETRY_DELAYS = [60, 300, 900, 3600, 86400];

  /**
   * Constructs WebhookDeliveryService.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly WebhookSubscriptionService $subscriptionService,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Queues a webhook for delivery.
   *
   * @param string $event_type
   *   The event type (e.g., 'event.updated').
   * @param int $vendor_id
   *   The vendor ID.
   * @param int|null $event_id
   *   The event node ID (if applicable).
   * @param array $data
   *   Event data to include in payload.
   */
  public function queueWebhook(string $event_type, int $vendor_id, ?int $event_id = NULL, array $data = []): void {
    // Get subscriptions that should receive this webhook.
    $subscriptions = $this->subscriptionService->getSubscriptionsForEvent($event_type, $vendor_id);

    foreach ($subscriptions as $subscription) {
      // Create delivery record.
      $delivery_id = $this->database->insert('myeventlane_webhook_deliveries')
        ->fields([
          'subscription_id' => $subscription['id'],
          'event_type' => $event_type,
          'event_id' => $event_id,
          'vendor_id' => $vendor_id,
          'payload' => serialize($data),
          'status' => 'pending',
          'attempt_count' => 0,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();

      // Queue delivery.
      $queue = $this->queueFactory->get('myeventlane_webhook_delivery');
      $queue->createItem([
        'delivery_id' => (int) $delivery_id,
        'subscription_id' => $subscription['id'],
        'event_type' => $event_type,
        'event_id' => $event_id,
        'vendor_id' => $vendor_id,
        'data' => $data,
      ]);
    }
  }

  /**
   * Delivers a webhook.
   *
   * @param array $delivery_data
   *   Delivery data from queue.
   *
   * @return bool
   *   TRUE if delivery succeeded, FALSE otherwise.
   */
  public function deliverWebhook(array $delivery_data): bool {
    $delivery_id = $delivery_data['delivery_id'];
    $subscription_id = $delivery_data['subscription_id'];
    $event_type = $delivery_data['event_type'];
    $data = $delivery_data['data'] ?? [];

    // Load subscription.
    $subscription = $this->subscriptionService->getSubscription($subscription_id);
    if (!$subscription || !$subscription['enabled']) {
      $this->updateDeliveryStatus($delivery_id, 'failed', 0, 'Subscription not found or disabled');
      return FALSE;
    }

    // Build payload.
    $payload = [
      'event_id' => $delivery_data['event_id'] ?? NULL,
      'vendor_id' => $delivery_data['vendor_id'],
      'timestamp' => $this->time->getRequestTime(),
      'type' => $event_type,
      'data' => $data,
    ];

    // Sign payload with HMAC.
    $payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = $this->signPayload($payload_json, $subscription['secret']);

    // Make HTTP request.
    $response = $this->makeHttpRequest($subscription['endpoint_url'], $payload_json, $signature);

    // Update delivery status.
    $this->updateDeliveryStatus(
      $delivery_id,
      $response['success'] ? 'success' : 'failed',
      $response['code'] ?? 0,
      $response['body'] ?? NULL
    );

    // If failed and retries remain, queue retry.
    if (!$response['success']) {
      $this->scheduleRetry($delivery_id, $subscription_id, $delivery_data);
    }

    return $response['success'];
  }

  /**
   * Signs a payload with HMAC.
   *
   * @param string $payload
   *   The JSON payload.
   * @param string $secret
   *   The HMAC secret.
   *
   * @return string
   *   The HMAC signature.
   */
  private function signPayload(string $payload, string $secret): string {
    return hash_hmac('sha256', $payload, $secret);
  }

  /**
   * Makes HTTP request to webhook endpoint.
   *
   * @param string $url
   *   The endpoint URL.
   * @param string $payload
   *   The JSON payload.
   * @param string $signature
   *   The HMAC signature.
   *
   * @return array
   *   Array with 'success', 'code', 'body' keys.
   */
  private function makeHttpRequest(string $url, string $payload, string $signature): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-MyEventLane-Signature: ' . $signature,
        'X-MyEventLane-Timestamp: ' . $this->time->getRequestTime(),
      ],
      CURLOPT_TIMEOUT => 30,
      CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $success = $code >= 200 && $code < 300 && empty($error);

    return [
      'success' => $success,
      'code' => $code ?: 0,
      'body' => $body ?: $error,
    ];
  }

  /**
   * Updates delivery status in database.
   *
   * @param int $delivery_id
   *   The delivery ID.
   * @param string $status
   *   The status.
   * @param int $response_code
   *   HTTP response code.
   * @param string|null $response_body
   *   Response body.
   */
  private function updateDeliveryStatus(int $delivery_id, string $status, int $response_code = 0, ?string $response_body = NULL): void {
    $fields = [
      'status' => $status,
      'response_code' => $response_code ?: NULL,
      'response_body' => $response_body ? substr($response_body, 0, 65535) : NULL,
    ];

    if ($status === 'success') {
      $fields['delivered_at'] = $this->time->getRequestTime();
    }

    // Increment attempt count.
    $this->database->update('myeventlane_webhook_deliveries')
      ->expression('attempt_count', 'attempt_count + 1')
      ->fields($fields)
      ->condition('id', $delivery_id)
      ->execute();
  }

  /**
   * Schedules a retry for a failed delivery.
   *
   * @param int $delivery_id
   *   The delivery ID.
   * @param int $subscription_id
   *   The subscription ID.
   * @param array $delivery_data
   *   Original delivery data.
   */
  private function scheduleRetry(int $delivery_id, int $subscription_id, array $delivery_data): void {
    // Get current attempt count.
    $current = $this->database->select('myeventlane_webhook_deliveries', 'wd')
      ->fields('wd', ['attempt_count'])
      ->condition('id', $delivery_id)
      ->execute()
      ->fetchField();

    $attempt_count = (int) $current;
    if ($attempt_count >= self::MAX_RETRIES) {
      // Max retries reached, mark as failed permanently.
      $this->database->update('myeventlane_webhook_deliveries')
        ->fields(['status' => 'failed'])
        ->condition('id', $delivery_id)
        ->execute();
      return;
    }

    // Calculate next retry time.
    $delay = self::RETRY_DELAYS[min($attempt_count, count(self::RETRY_DELAYS) - 1)];
    $next_retry_at = $this->time->getRequestTime() + $delay;

    // Update delivery record.
    $this->database->update('myeventlane_webhook_deliveries')
      ->fields([
        'status' => 'retrying',
        'next_retry_at' => $next_retry_at,
      ])
      ->condition('id', $delivery_id)
      ->execute();

    // Queue retry (use a separate queue or cron-based retry).
    // For now, we'll rely on cron to process retries.
  }

}
