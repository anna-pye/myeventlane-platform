<?php

declare(strict_types=1);

namespace Drupal\myeventlane_webhooks\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Service for managing webhook subscriptions.
 */
final class WebhookSubscriptionService {

  /**
   * Supported event types.
   */
  public const EVENT_TYPES = [
    'event.updated',
    'event.cancelled',
    'ticket.purchased',
    'ticket.refunded',
    'rsvp.created',
    'attendee.checked_in',
    'export.ready',
  ];

  /**
   * Constructs WebhookSubscriptionService.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a webhook subscription for a vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param string $endpoint_url
   *   The webhook endpoint URL.
   * @param array $event_types
   *   Array of enabled event types.
   *
   * @return int
   *   The subscription ID.
   */
  public function createSubscription(Vendor $vendor, string $endpoint_url, array $event_types = []): int {
    $secret = $this->generateSecret();
    $now = $this->time->getRequestTime();

    // Validate event types.
    $valid_types = array_intersect($event_types, self::EVENT_TYPES);
    if (empty($valid_types)) {
      // Default to all event types if none specified.
      $valid_types = self::EVENT_TYPES;
    }

    $subscription_id = $this->database->insert('myeventlane_webhook_subscriptions')
      ->fields([
        'vendor_id' => $vendor->id(),
        'endpoint_url' => $endpoint_url,
        'secret' => $secret,
        'event_types' => serialize($valid_types),
        'enabled' => 1,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    return (int) $subscription_id;
  }

  /**
   * Gets all subscriptions for a vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   * @param bool $enabled_only
   *   If TRUE, only return enabled subscriptions.
   *
   * @return array
   *   Array of subscription records.
   */
  public function getSubscriptions(Vendor $vendor, bool $enabled_only = FALSE): array {
    $query = $this->database->select('myeventlane_webhook_subscriptions', 'ws')
      ->fields('ws')
      ->condition('vendor_id', $vendor->id())
      ->orderBy('created', 'DESC');

    if ($enabled_only) {
      $query->condition('enabled', 1);
    }

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($results as &$result) {
      $result['event_types'] = unserialize($result['event_types'] ?? '');
      $result['id'] = (int) $result['id'];
      $result['vendor_id'] = (int) $result['vendor_id'];
    }

    return $results;
  }

  /**
   * Gets a subscription by ID.
   *
   * @param int $subscription_id
   *   The subscription ID.
   *
   * @return array|null
   *   The subscription record, or NULL if not found.
   */
  public function getSubscription(int $subscription_id): ?array {
    $result = $this->database->select('myeventlane_webhook_subscriptions', 'ws')
      ->fields('ws')
      ->condition('id', $subscription_id)
      ->execute()
      ->fetchAssoc();

    if (!$result) {
      return NULL;
    }

    $result['event_types'] = unserialize($result['event_types'] ?? '');
    $result['id'] = (int) $result['id'];
    $result['vendor_id'] = (int) $result['vendor_id'];

    return $result;
  }

  /**
   * Updates a subscription.
   *
   * @param int $subscription_id
   *   The subscription ID.
   * @param array $updates
   *   Fields to update (endpoint_url, event_types, enabled).
   *
   * @return bool
   *   TRUE if update succeeded.
   */
  public function updateSubscription(int $subscription_id, array $updates): bool {
    $fields = [];

    if (isset($updates['endpoint_url'])) {
      $fields['endpoint_url'] = $updates['endpoint_url'];
    }

    if (isset($updates['event_types'])) {
      $valid_types = array_intersect($updates['event_types'], self::EVENT_TYPES);
      $fields['event_types'] = serialize($valid_types ?: self::EVENT_TYPES);
    }

    if (isset($updates['enabled'])) {
      $fields['enabled'] = (int) (bool) $updates['enabled'];
    }

    if (empty($fields)) {
      return FALSE;
    }

    $fields['updated'] = $this->time->getRequestTime();

    $this->database->update('myeventlane_webhook_subscriptions')
      ->fields($fields)
      ->condition('id', $subscription_id)
      ->execute();

    return TRUE;
  }

  /**
   * Deletes a subscription.
   *
   * @param int $subscription_id
   *   The subscription ID.
   *
   * @return bool
   *   TRUE if deletion succeeded.
   */
  public function deleteSubscription(int $subscription_id): bool {
    $deleted = $this->database->delete('myeventlane_webhook_subscriptions')
      ->condition('id', $subscription_id)
      ->execute();

    return $deleted > 0;
  }

  /**
   * Gets subscriptions that should receive a webhook for an event type.
   *
   * @param string $event_type
   *   The event type.
   * @param int $vendor_id
   *   The vendor ID.
   *
   * @return array
   *   Array of subscription records.
   */
  public function getSubscriptionsForEvent(string $event_type, int $vendor_id): array {
    $query = $this->database->select('myeventlane_webhook_subscriptions', 'ws')
      ->fields('ws')
      ->condition('vendor_id', $vendor_id)
      ->condition('enabled', 1);

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $matching = [];
    foreach ($results as $result) {
      $event_types = unserialize($result['event_types'] ?? '');
      if (in_array($event_type, $event_types, TRUE)) {
        $result['event_types'] = $event_types;
        $result['id'] = (int) $result['id'];
        $result['vendor_id'] = (int) $result['vendor_id'];
        $matching[] = $result;
      }
    }

    return $matching;
  }

  /**
   * Generates a secure secret for webhook signing.
   *
   * @return string
   *   A random secret.
   */
  private function generateSecret(): string {
    return bin2hex(random_bytes(32));
  }

}
