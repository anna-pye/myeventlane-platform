<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Read-only fulfillment execution orchestration over lifecycle read-models.
 *
 * Delegates lifecycle normalization to {@see FulfillmentLifecycleManager} only.
 * Does not mutate inventory, entitlements, scanners, shipments, or reservations.
 */
final class OperationalFulfillmentExecutionManager {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_operational_fulfillment_execution';

  /**
   * @var list<string>
   */
  public const FORBIDDEN_EXECUTION_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_secret',
    'device_fingerprint',
    'operational_fingerprint',
    'inventory_quantity',
    'stock_level',
    'warehouse_slot',
    'topology_fingerprint',
    'shipment_id',
    'shipment_carrier',
    'shipment_tracking',
    'audit_trace',
    'execution_descriptor',
  ];

  /**
   * @var list<string>
   */
  private const EXECUTION_CONTRACT_KEYS = [
    'execution_id',
    'execution_type',
    'fulfillment_type',
    'execution_state',
    'readiness_state',
    'completion_state',
    'collection_state',
    'redemption_state',
    'execution_mode',
    'continuity_mode',
    'reservation_state',
    'operational_summary',
    'execution_chips',
    'execution_timeline',
    'customer_visibility',
    'execution_readiness',
    'operational_completion',
  ];

  public function __construct(
    private readonly FulfillmentLifecycleManager $fulfillmentLifecycleManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, mixed> $merged
   *   Merged inspection payload (same shape as {@see OperationalWorkspaceBuilder::mergeInspections()}).
   *
   * @return array<string, mixed>
   */
  public function composeExecutionBundleFromMerged(array $merged, int $requestTime): array {
    $model = $this->fulfillmentLifecycleManager->composeFulfillmentReadModel($merged, $requestTime);
    $rows = is_array($model['ticket_projections'] ?? NULL) ? $model['ticket_projections'] : [];
    if ($rows === []) {
      return $this->emptyBundle();
    }

    $guest = is_array($merged['guest_continuity'] ?? NULL) ? $merged['guest_continuity'] : [];
    $continuity_join = implode(', ', is_array($guest['continuity_statuses_observed'] ?? NULL)
      ? $guest['continuity_statuses_observed']
      : []);

    $executions = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $fulfillment_type = (string) ($row['fulfillment_type'] ?? '');
      $normalized = (string) ($row['normalized_lifecycle_state'] ?? '');
      $execution_type = $this->mapToExecutionType($fulfillment_type, $row);
      $redemption_state = $this->truncateSummary(
        $this->stripScannerExecutionProjectionMaterial((string) ($row['redemption_execution_summary'] ?? '')),
      );
      $exec = [
        'execution_id' => $this->stableExecutionId((int) ($row['ticket_ordinal'] ?? 0), $execution_type, $normalized),
        'execution_type' => $execution_type,
        'fulfillment_type' => $fulfillment_type,
        'execution_state' => $normalized,
        'readiness_state' => (string) ($row['readiness_lane'] ?? ''),
        'completion_state' => $this->completionBucket($normalized),
        'collection_state' => (string) ($row['collection_semantics'] ?? ''),
        'redemption_state' => $redemption_state,
        'execution_mode' => 'orchestration_projection',
        'continuity_mode' => $continuity_join !== '' ? $continuity_join : 'unknown',
        'reservation_state' => 'reservation_authority_external',
        'operational_summary' => $this->composeRowSummary($row, $execution_type),
        'execution_chips' => $this->buildExecutionChips($row, $execution_type),
        'execution_timeline' => $this->buildExecutionTimeline($normalized),
        'customer_visibility' => 'visible',
        'execution_readiness' => (string) ($row['readiness_lane'] ?? ''),
        'operational_completion' => $this->completionCustomerLabel($normalized),
      ];
      $executions[] = $this->whitelistExecution($exec);
    }

    $rollup = is_array($model['rollup'] ?? NULL) ? $model['rollup'] : [];
    $readiness = is_array($model['readiness_projection'] ?? NULL) ? $model['readiness_projection'] : [];
    $completion = is_array($model['completion_projection'] ?? NULL) ? $model['completion_projection'] : [];

    $doc = [
      'schema_version' => 1,
      self::CONTRACT_FLAG => TRUE,
      'empty' => FALSE,
      'request_time' => $requestTime,
      'executions' => $executions,
      'rollup' => [
        'readiness_descriptor' => (string) ($readiness['descriptor'] ?? ''),
        'completion_descriptor' => (string) ($completion['descriptor'] ?? ''),
        'sampled_rows' => (int) ($rollup['sampled_ticket_rows'] ?? 0),
        'partial_rows' => (int) ($rollup['partial_fulfillment_rows'] ?? 0),
      ],
    ];
    return $this->sanitizeExecutionDocument($doc);
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  public function sanitizeExecutionDocument(array $data): array {
    $out = [];
    foreach ($data as $key => $value) {
      if (is_string($key) && in_array($key, self::FORBIDDEN_EXECUTION_KEYS, TRUE)) {
        continue;
      }
      if (is_array($value)) {
        $out[$key] = $this->sanitizeExecutionDocument($value);
      }
      elseif (is_scalar($value) || $value === NULL) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyBundle(): array {
    return $this->sanitizeExecutionDocument([
      'schema_version' => 1,
      self::CONTRACT_FLAG => TRUE,
      'empty' => TRUE,
      'request_time' => 0,
      'executions' => [],
      'rollup' => [],
    ]);
  }

  /**
   * @param array<string, mixed> $row
   */
  private function mapToExecutionType(string $fulfillment_type, array $row): string {
    $collection = strtolower((string) ($row['collection_semantics'] ?? ''));
    if ($fulfillment_type === FulfillmentLifecycleManager::TYPE_MERCH_PICKUP && str_contains($collection, 'timed')) {
      return 'timed_collection';
    }
    return match ($fulfillment_type) {
      FulfillmentLifecycleManager::TYPE_MERCH_PICKUP => 'merch_pickup',
      FulfillmentLifecycleManager::TYPE_HOSPITALITY => 'hospitality_redemption',
      FulfillmentLifecycleManager::TYPE_PARKING => 'parking_access',
      FulfillmentLifecycleManager::TYPE_VIP_ACCESS => 'vip_access',
      FulfillmentLifecycleManager::TYPE_CLOAKROOM => 'cloakroom_collection',
      FulfillmentLifecycleManager::TYPE_MULTI_REDEEM => 'food_drink_redemption',
      FulfillmentLifecycleManager::TYPE_DIGITAL_ACCESS => 'digital_redemption',
      FulfillmentLifecycleManager::TYPE_EQUIPMENT => 'timed_collection',
      FulfillmentLifecycleManager::TYPE_ADMISSION => 'admission_access',
      default => 'operational_execution',
    };
  }

  private function stableExecutionId(int $ticket_ordinal, string $execution_type, string $state): string {
    return substr(hash('sha256', $ticket_ordinal . '|' . $execution_type . '|' . $state), 0, 16);
  }

  private function completionBucket(string $normalized): string {
    return match ($normalized) {
      FulfillmentLifecycleManager::STATE_FULFILLED,
      FulfillmentLifecycleManager::STATE_COLLECTED,
      FulfillmentLifecycleManager::STATE_CONSUMED => 'terminal_complete',
      FulfillmentLifecycleManager::STATE_FAILED,
      FulfillmentLifecycleManager::STATE_CANCELLED,
      FulfillmentLifecycleManager::STATE_EXPIRED => 'terminal_closed',
      default => 'in_progress',
    };
  }

  private function truncateSummary(string $summary): string {
    $summary = trim($summary);
    if (strlen($summary) > 220) {
      return substr($summary, 0, 217) . '…';
    }
    return $summary;
  }

  /**
   * Removes scanner-policy tokens from execution projection strings.
   *
   * Lifecycle read-models may include `scanner_action=` diagnostics for staff
   * fulfillment cards; execution orchestration strips those for all execution
   * bundle consumers (customer strips and execution governance projections).
   */
  private function stripScannerExecutionProjectionMaterial(string $text): string {
    if ($text === '') {
      return '';
    }
    $out = preg_replace('/\bscanner_action=[^;]*\s*(;\s*|\s*|$)/', '', $text) ?? $text;
    $out = trim((string) $out);
    $out = preg_replace('/;\s*;/', '; ', $out) ?? $out;
    return trim((string) $out, " \t\n\r\0\x0B;");
  }

  /**
   * @param array<string, mixed> $row
   */
  private function composeRowSummary(array $row, string $execution_type): string {
    $pickup = $this->stripScannerExecutionProjectionMaterial(trim((string) ($row['pickup_lifecycle_summary'] ?? '')));
    $redeem = $this->stripScannerExecutionProjectionMaterial(trim((string) ($row['redemption_execution_summary'] ?? '')));
    $parts = array_filter([$pickup, $redeem]);
    if ($parts !== []) {
      return implode(' — ', $parts);
    }
    return (string) $this->t('Operational execution projection for @type.', ['@type' => $execution_type]);
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return list<array{label: string, tone: string}>
   */
  private function buildExecutionChips(array $row, string $execution_type): array {
    $chips = [];
    $state = (string) ($row['normalized_lifecycle_state'] ?? '');
    if ($state === FulfillmentLifecycleManager::STATE_READY) {
      $chips[] = ['label' => (string) $this->t('Ready for next on-site step'), 'tone' => 'success'];
    }
    if (!empty($row['partial_fulfillment'])) {
      $chips[] = ['label' => (string) $this->t('Partial completion may apply'), 'tone' => 'warning'];
    }
    if ($execution_type === 'merch_pickup') {
      $chips[] = ['label' => (string) $this->t('Merchandise ready for collection when venue allows'), 'tone' => 'info'];
    }
    if ($execution_type === 'hospitality_redemption') {
      $chips[] = ['label' => (string) $this->t('VIP hospitality activates at the venue'), 'tone' => 'info'];
    }
    if ($execution_type === 'parking_access') {
      $chips[] = ['label' => (string) $this->t('Parking access linked to entry rules'), 'tone' => 'muted'];
    }
    if ($execution_type === 'digital_redemption') {
      $chips[] = ['label' => (string) $this->t('Digital redemption available after admission'), 'tone' => 'muted'];
    }
    return $this->dedupeChips($chips);
  }

  /**
   * @return list<array{label: string, state: string}>
   */
  private function buildExecutionTimeline(string $normalized): array {
    $states = $this->fulfillmentLifecycleManager->allCanonicalStates();
    $idx = array_search($normalized, $states, TRUE);
    $idx = $idx === FALSE ? 0 : (int) $idx;
    $slice = array_slice($states, 0, min($idx + 1, count($states)));
    $out = [];
    foreach ($slice as $state) {
      $out[] = [
        'label' => (string) $this->t('Stage: @state', ['@state' => (string) $state]),
        'state' => (string) $state,
      ];
    }
    return $out;
  }

  private function completionCustomerLabel(string $normalized): string {
    return match ($normalized) {
      FulfillmentLifecycleManager::STATE_COLLECTED => (string) $this->t('Collected'),
      FulfillmentLifecycleManager::STATE_CONSUMED => (string) $this->t('Consumed'),
      FulfillmentLifecycleManager::STATE_FULFILLED => (string) $this->t('Fulfilled'),
      FulfillmentLifecycleManager::STATE_READY => (string) $this->t('Ready'),
      FulfillmentLifecycleManager::STATE_FAILED => (string) $this->t('Needs attention'),
      default => (string) $this->t('In progress'),
    };
  }

  /**
   * @param array<string, mixed> $execution
   *
   * @return array<string, mixed>
   */
  private function whitelistExecution(array $execution): array {
    $out = [];
    foreach (self::EXECUTION_CONTRACT_KEYS as $key) {
      if (array_key_exists($key, $execution)) {
        $out[$key] = $execution[$key];
      }
    }
    return $out;
  }

  /**
   * @param list<array{label: string, tone: string}> $chips
   *
   * @return list<array{label: string, tone: string}>
   */
  private function dedupeChips(array $chips): array {
    $seen = [];
    $out = [];
    foreach ($chips as $chip) {
      $label = trim((string) ($chip['label'] ?? ''));
      if ($label === '' || isset($seen[$label])) {
        continue;
      }
      $seen[$label] = TRUE;
      $out[] = [
        'label' => $label,
        'tone' => (string) ($chip['tone'] ?? 'muted'),
      ];
    }
    return $out;
  }

}
