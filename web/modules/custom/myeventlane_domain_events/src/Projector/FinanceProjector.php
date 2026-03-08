<?php

declare(strict_types=1);

namespace Drupal\myeventlane_domain_events\Projector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects finance read-model rows.
 */
final class FinanceProjector implements DomainEventProjectorInterface {

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
    return 'finance_projector';
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $eventType): bool {
    return in_array($eventType, ['payment.succeeded', 'refund.completed'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function project(array $event): void {
    $payload = is_array($event['payload'] ?? NULL) ? $event['payload'] : [];
    $vendorId = (int) ($payload['vendor_id'] ?? $event['vendor_id'] ?? 0);
    $eventNodeId = (int) ($payload['event_node_id'] ?? $event['event_node_id'] ?? 0);

    if ($vendorId <= 0 || $eventNodeId <= 0) {
      $this->logger->warning('Finance projector skipped event {event_id} due to missing vendor/event IDs.', [
        'event_id' => (int) ($event['id'] ?? 0),
      ]);
      return;
    }

    $row = $this->loadRow($vendorId, $eventNodeId);
    $grossRevenue = (float) ($row['gross_revenue'] ?? 0);
    $melFee = (float) ($row['mel_fee'] ?? 0);
    $stripeFee = (float) ($row['stripe_fee'] ?? 0);
    $netRevenue = (float) ($row['net_revenue'] ?? 0);
    $refundTotal = (float) ($row['refund_total'] ?? 0);

    if ((string) $event['event_type'] === 'payment.succeeded') {
      $grossRevenue += $this->num($payload, 'gross_revenue', 'amount_total');
      $melFee += $this->num($payload, 'mel_fee');
      $stripeFee += $this->num($payload, 'stripe_fee');
      $netRevenue += $this->num($payload, 'net_revenue');
    }
    elseif ((string) $event['event_type'] === 'refund.completed') {
      $refundAmount = $this->num($payload, 'refund_total', 'refund_amount', 'amount_total');
      $refundTotal += $refundAmount;
      $netRevenue -= $refundAmount;
    }

    $this->upsertRow($vendorId, $eventNodeId, [
      'gross_revenue' => $grossRevenue,
      'mel_fee' => $melFee,
      'stripe_fee' => $stripeFee,
      'net_revenue' => $netRevenue,
      'refund_total' => $refundTotal,
      'updated_at' => $this->time->getCurrentTime(),
    ]);
    $this->cacheTagsInvalidator->invalidateTags([sprintf('finance:%d:%d', $vendorId, $eventNodeId)]);
  }

  /**
   * @return array<string, mixed>|null
   *   Existing row.
   */
  private function loadRow(int $vendorId, int $eventNodeId): ?array {
    $row = $this->database->select('myeventlane_projection_finance', 'f')
      ->fields('f')
      ->condition('vendor_id', $vendorId)
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
  private function upsertRow(int $vendorId, int $eventNodeId, array $fields): void {
    $existing = $this->loadRow($vendorId, $eventNodeId);
    if ($existing === NULL) {
      $this->database->insert('myeventlane_projection_finance')
        ->fields($fields + [
          'vendor_id' => $vendorId,
          'event_node_id' => $eventNodeId,
        ])
        ->execute();
      return;
    }

    $this->database->update('myeventlane_projection_finance')
      ->fields($fields)
      ->condition('vendor_id', $vendorId)
      ->condition('event_node_id', $eventNodeId)
      ->execute();
  }

  /**
   * Extracts numeric amount from payload keys.
   *
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

