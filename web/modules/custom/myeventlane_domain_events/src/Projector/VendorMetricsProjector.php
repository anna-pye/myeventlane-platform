<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Projector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects per-vendor metrics.
 */
final class VendorMetricsProjector implements DomainEventProjectorInterface {

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
    return 'vendor_metrics_projector';
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $eventType): bool {
    return in_array($eventType, ['payment.succeeded', 'ticket.issued', 'refund.completed'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function project(array $event): void {
    $payload = is_array($event['payload'] ?? NULL) ? $event['payload'] : [];
    $vendorId = (int) ($payload['vendor_id'] ?? $event['vendor_id'] ?? 0);

    if ($vendorId <= 0) {
      $this->logger->warning('Vendor metrics projector skipped event {event_id}: missing vendor_id.', [
        'event_id' => (int) ($event['id'] ?? 0),
      ]);
      return;
    }

    $row = $this->loadRow($vendorId);
    $totalRevenue = (float) ($row['total_revenue'] ?? 0);
    $ticketsSold = (int) ($row['tickets_sold'] ?? 0);
    $activeEvents = (int) ($row['active_events'] ?? 0);
    $refundTotal = (float) ($row['refund_total'] ?? 0);

    $eventNodeId = (int) ($payload['event_node_id'] ?? $event['event_node_id'] ?? 0);

    switch ((string) $event['event_type']) {
      case 'payment.succeeded':
        $totalRevenue += $this->num($payload, 'net_revenue', 'amount_total', 'gross_revenue');
        if ($eventNodeId > 0) {
          $activeEvents = max($activeEvents, $this->countVendorActiveEvents($vendorId));
        }
        break;

      case 'ticket.issued':
        $ticketsSold += max(1, (int) ($payload['quantity'] ?? 1));
        break;

      case 'refund.completed':
        $amount = $this->num($payload, 'refund_total', 'refund_amount', 'amount_total');
        $refundTotal += $amount;
        $totalRevenue -= $amount;
        break;
    }

    $this->upsertRow($vendorId, [
      'total_revenue' => $totalRevenue,
      'tickets_sold' => $ticketsSold,
      'active_events' => $activeEvents,
      'refund_total' => $refundTotal,
      'updated_at' => $this->time->getCurrentTime(),
    ]);
    $this->cacheTagsInvalidator->invalidateTags([sprintf('vendor_metrics:%d', $vendorId)]);
  }

  /**
   * @return array<string, mixed>|null
   *   Existing row.
   */
  private function loadRow(int $vendorId): ?array {
    $row = $this->database->select('myeventlane_projection_vendor_metrics', 'm')
      ->fields('m')
      ->condition('vendor_id', $vendorId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * @param array<string, mixed> $fields
   *   Row fields.
   */
  private function upsertRow(int $vendorId, array $fields): void {
    $existing = $this->loadRow($vendorId);
    if ($existing === NULL) {
      $this->database->insert('myeventlane_projection_vendor_metrics')
        ->fields($fields + ['vendor_id' => $vendorId])
        ->execute();
      return;
    }

    $this->database->update('myeventlane_projection_vendor_metrics')
      ->fields($fields)
      ->condition('vendor_id', $vendorId)
      ->execute();
  }

  /**
   * Counts unique active event IDs with finance rows for this vendor.
   */
  private function countVendorActiveEvents(int $vendorId): int {
    $query = $this->database->select('myeventlane_projection_finance', 'f');
    $query->addExpression('COUNT(DISTINCT f.event_node_id)', 'active_events');
    $query->condition('f.vendor_id', $vendorId);
    $query->condition('f.gross_revenue', 0, '>');
    return (int) $query->execute()->fetchField();
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

