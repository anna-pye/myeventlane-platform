<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Projector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects per-event metrics.
 */
final class EventMetricsProjector implements DomainEventProjectorInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'event_metrics_projector';
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $eventType): bool {
    return in_array($eventType, ['ticket.issued', 'rsvp.created', 'checkin.completed'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function project(array $event): void {
    $payload = is_array($event['payload'] ?? NULL) ? $event['payload'] : [];
    $eventNodeId = (int) ($payload['event_node_id'] ?? $event['event_node_id'] ?? 0);
    if ($eventNodeId <= 0) {
      $this->logger->warning('Event metrics projector skipped event {event_id}: missing event_node_id.', [
        'event_id' => (int) ($event['id'] ?? 0),
      ]);
      return;
    }

    $row = $this->loadRow($eventNodeId);
    $ticketsSold = (int) ($row['tickets_sold'] ?? 0);
    $rsvpCount = (int) ($row['rsvp_count'] ?? 0);
    $revenueTotal = (float) ($row['revenue_total'] ?? 0);
    $refundCount = (int) ($row['refund_count'] ?? 0);
    $checkinCount = (int) ($row['checkin_count'] ?? 0);

    switch ((string) $event['event_type']) {
      case 'ticket.issued':
        $ticketsSold += max(1, (int) ($payload['quantity'] ?? 1));
        $revenueTotal += $this->num($payload, 'amount_total', 'gross_revenue');
        break;

      case 'rsvp.created':
        $rsvpCount += max(1, (int) ($payload['guests'] ?? 1));
        break;

      case 'checkin.completed':
        $checkinCount += max(1, (int) ($payload['quantity'] ?? 1));
        break;
    }

    $this->upsertRow($eventNodeId, [
      'tickets_sold' => $ticketsSold,
      'rsvp_count' => $rsvpCount,
      'revenue_total' => $revenueTotal,
      'refund_count' => $refundCount,
      'checkin_count' => $checkinCount,
      'updated_at' => $this->time->getCurrentTime(),
    ]);
    $this->cacheTagsInvalidator->invalidateTags([sprintf('event_metrics:%d', $eventNodeId)]);
  }

  /**
   * @return array<string, mixed>|null
   *   Existing row.
   */
  private function loadRow(int $eventNodeId): ?array {
    $row = $this->database->select('myeventlane_projection_event_metrics', 'm')
      ->fields('m')
      ->condition('event_node_id', $eventNodeId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * @param array<string, mixed> $fields
   *   Row fields.
   */
  private function upsertRow(int $eventNodeId, array $fields): void {
    $existing = $this->loadRow($eventNodeId);
    if ($existing === NULL) {
      $this->database->insert('myeventlane_projection_event_metrics')
        ->fields($fields + ['event_node_id' => $eventNodeId])
        ->execute();
      return;
    }

    $this->database->update('myeventlane_projection_event_metrics')
      ->fields($fields)
      ->condition('event_node_id', $eventNodeId)
      ->execute();
  }

  /**
   * @param array<string, mixed> $payload
   *   Event payload.
   */
  private function num(array $payload, string ...$keys): float {
    foreach ($keys as $key) {
      if (isset($payload[$key]) && is_numeric($payload[$key])) {
        return (float) $payload[$key];
      }
    }
    return 0.0;
  }

}

