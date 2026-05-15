<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;

/**
 * Builds staff-safe incident, severity, and extended integrity sections.
 *
 * Data is sourced from {@see OperationalIntegrityInspector} read models and a
 * bounded redemption audit tally (deny counts by result token only). Gate
 * policy is not re-evaluated here.
 */
final class OperationalIncidentBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalIntegrityInspector $operationalIntegrityInspector,
    private readonly OperationalIncidentNormalizer $operationalIncidentNormalizer,
    private readonly VenueOperationPolicyManager $venueOperationPolicyManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
  ) {}

  /**
   * Samples tickets once, then loads inspector diagnostics per related order.
   *
   * @return array{inspections: array<int, array<string, mixed>>, ticket_ids: list<int>}
   */
  public function sampleEventOperationalContext(NodeInterface $event): array {
    $ticket_storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $ticket_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event->id())
      ->range(0, 40)
      ->sort('id')
      ->execute();
    if (!$ids) {
      return ['inspections' => [], 'ticket_ids' => []];
    }
    $ticket_ids = array_map('intval', array_values($ids));
    /** @var array<int|string, Ticket> $tickets */
    $tickets = $ticket_storage->loadMultiple($ids);

    $order_ids = [];
    foreach ($tickets as $ticket) {
      $oid = $ticket->get('order_id')->target_id;
      if ($oid) {
        $order_ids[(int) $oid] = (int) $oid;
      }
    }

    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $out = [];
    foreach ($order_ids as $oid) {
      $order = $order_storage->load($oid);
      if ($order instanceof OrderInterface) {
        $out[$oid] = $this->operationalIntegrityInspector->inspectOrder($order);
      }
    }
    return ['inspections' => $out, 'ticket_ids' => $ticket_ids];
  }

  /**
   * Aggregates denied scan attempts from redemption logs (bounded).
   *
   * @param list<int> $ticketIds
   *
   * @return array<string, int>
   *   Map of result token => count.
   */
  public function aggregateDeniedScanResults(array $ticketIds, int $eventNid): array {
    if ($ticketIds === [] || !$this->entityTypeManager->hasDefinition('mel_redemption_log')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('mel_redemption_log');
    $base_table = $storage->getEntityType()->getBaseTable();
    if (!$base_table) {
      return [];
    }
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_id', $ticketIds, 'IN')
      ->condition('event_id', $eventNid)
      ->range(0, 200)
      ->sort('id', 'DESC')
      ->execute();
    if (!$ids) {
      return [];
    }
    /** @var array<int|string, RedemptionLog> $logs */
    $logs = $storage->loadMultiple($ids);
    $counts = [];
    foreach ($logs as $log) {
      $item = $log->get('metadata_json')->first();
      $meta = $item ? $item->getValue() : [];
      if (!is_array($meta)) {
        continue;
      }
      if (!empty($meta['ok'])) {
        continue;
      }
      $result = (string) ($meta['result'] ?? 'unknown');
      $result = $this->operationalIncidentNormalizer->normalizeIncidentType($result);
      $counts[$result] = ($counts[$result] ?? 0) + 1;
    }
    return $counts;
  }

  /**
   * @param array<int, array<string, mixed>> $inspections
   * @param array<string, mixed> $merged
   * @param array<string, int> $denied_scan_counts
   *
   * @return list<array<string, mixed>>
   */
  public function buildSections(array $inspections, array $merged, array $denied_scan_counts): array {
    $issuance = $merged['issuance'] ?? [];
    $recovery = $merged['recovery'] ?? [];
    $guest = $merged['guest_continuity'] ?? [];
    $artifacts = $merged['artifacts'] ?? [];

    $incident_rows = $this->buildIncidentRows($inspections, $issuance, $recovery, $guest, $artifacts, $denied_scan_counts);
    $severity_rollup = $this->rollupSeverity($incident_rows);

    $incidents_section = [
      'id' => 'operational_incidents',
      'title' => 'Operational incidents',
      'cards' => $this->incidentRowsToCards($incident_rows),
    ];

    $severity_section = [
      'id' => 'incident_severity',
      'title' => 'Incident severity',
      'cards' => [
        [
          'label' => 'Critical incidents',
          'value' => (string) ($severity_rollup['critical'] ?? 0),
          'severity' => 'critical',
        ],
        [
          'label' => 'Warning incidents',
          'value' => (string) ($severity_rollup['warning'] ?? 0),
          'severity' => 'warning',
        ],
        [
          'label' => 'Informational incidents',
          'value' => (string) ($severity_rollup['info'] ?? 0),
          'severity' => 'info',
        ],
      ],
    ];

    $integrity_execution = [
      'id' => 'operational_integrity_execution',
      'title' => 'Operational integrity',
      'cards' => $this->buildIntegrityExecutionCards($merged, $inspections),
    ];

    return [$incidents_section, $severity_section, $integrity_execution];
  }

  /**
   * @param array<int, array<string, mixed>> $inspections
   * @param array<string, mixed> $issuance
   * @param array<string, mixed> $recovery
   * @param array<string, mixed> $guest
   * @param array<string, mixed> $artifacts
   * @param array<string, int> $denied_scan_counts
   *
   * @return list<array{type: string, severity: string, count: int, context: array<string, string>}>
   */
  private function buildIncidentRows(
    array $inspections,
    array $issuance,
    array $recovery,
    array $guest,
    array $artifacts,
    array $denied_scan_counts,
  ): array {
    $rows = [];

    $orphanTickets = (int) ($issuance['aggregated_orphan_ticket_count'] ?? 0);
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'orphan_ticket_rows',
      $orphanTickets,
      ['severity' => $orphanTickets > 0 ? 'warning' : 'info'],
    );

    $orphanItems = (int) ($issuance['aggregated_orphan_eligible_order_item_count'] ?? 0);
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'orphan_order_items',
      $orphanItems,
      ['severity' => $orphanItems > 0 ? 'warning' : 'info'],
    );

    $align = (string) ($issuance['worst_quantity_alignment_status'] ?? 'missing');
    $issuanceMismatchCount = 0;
    foreach ($inspections as $row) {
      $i = $row['issuance'] ?? [];
      $st = (string) ($i['quantity_alignment_status'] ?? 'missing');
      if (!in_array($st, ['valid', 'pending'], TRUE)) {
        $issuanceMismatchCount++;
      }
    }
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'issuance_mismatch',
      $issuanceMismatchCount,
      [
        'severity' => $issuanceMismatchCount > 0 ? 'critical' : 'info',
        'worst_alignment' => $align,
      ],
    );

    $recoveryMismatch = !empty($recovery['recovery_mismatch_observed']) ? 1 : 0;
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'recovery_mismatch',
      $recoveryMismatch,
      ['severity' => $recoveryMismatch > 0 ? 'warning' : 'info'],
    );

    $continuityAnomaly = $this->countContinuityAnomalies($guest);
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'continuity_anomaly',
      $continuityAnomaly,
      ['severity' => $continuityAnomaly > 0 ? 'warning' : 'info'],
    );

    $replayAnomaly = $this->countReplayAnomalies($artifacts);
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'replay_anomaly',
      $replayAnomaly,
      ['severity' => $replayAnomaly > 0 ? 'warning' : 'info'],
    );

    $topologyAnomaly = $this->countTopologyAnomalies($artifacts);
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'topology_anomaly',
      $topologyAnomaly,
      ['severity' => $topologyAnomaly > 0 ? 'warning' : 'info'],
    );

    $deniedTotal = array_sum($denied_scan_counts);
    $rows[] = $this->operationalIncidentNormalizer->normalizeIncidentRow(
      'failed_scan_attempt',
      $deniedTotal,
      [
        'severity' => $deniedTotal > 0 ? 'warning' : 'info',
        'distinct_result_tokens' => (string) count($denied_scan_counts),
      ],
    );

    return $rows;
  }

  /**
   * @param array<string, mixed> $guest
   */
  private function countContinuityAnomalies(array $guest): int {
    $statuses = $guest['continuity_statuses_observed'] ?? [];
    if (!is_array($statuses)) {
      return 0;
    }
    return in_array('invalid', $statuses, TRUE) ? 1 : 0;
  }

  /**
   * @param array<string, mixed> $artifacts
   */
  private function countReplayAnomalies(array $artifacts): int {
    $session = is_array($artifacts['session_entitlement_policy'] ?? NULL) ? $artifacts['session_entitlement_policy'] : [];
    $timed = is_array($artifacts['timed_entry_policy'] ?? NULL) ? $artifacts['timed_entry_policy'] : [];
    $ids = [];
    foreach ($session as $tid => $row) {
      $conflicts = $row['conflicts'] ?? [];
      if (is_array($conflicts) && $conflicts !== []) {
        $ids[(string) $tid] = TRUE;
      }
    }
    foreach ($timed as $tid => $row) {
      $conflicts = $row['conflicts'] ?? [];
      if (is_array($conflicts) && $conflicts !== []) {
        $ids[(string) $tid] = TRUE;
      }
    }
    return count($ids);
  }

  /**
   * @param array<string, mixed> $artifacts
   */
  private function countTopologyAnomalies(array $artifacts): int {
    $zones = is_array($artifacts['zone_access_topology'] ?? NULL) ? $artifacts['zone_access_topology'] : [];
    $ids = [];
    foreach ($zones as $tid => $row) {
      if (!is_array($row)) {
        continue;
      }
      $struct = $row['structural_conflicts'] ?? [];
      if (is_array($struct) && $struct !== []) {
        $ids[(string) $tid] = TRUE;
      }
    }
    return count($ids);
  }

  /**
   * @param list<array{type: string, severity: string, count: int, context: array<string, string>}> $rows
   *
   * @return array<string, int>
   */
  private function rollupSeverity(array $rows): array {
    $out = ['info' => 0, 'warning' => 0, 'critical' => 0];
    foreach ($rows as $row) {
      if ((int) ($row['count'] ?? 0) < 1) {
        continue;
      }
      $sev = (string) ($row['severity'] ?? 'info');
      if (!isset($out[$sev])) {
        $sev = 'info';
      }
      $out[$sev]++;
    }
    return $out;
  }

  /**
   * @param list<array{type: string, severity: string, count: int, context: array<string, string>}> $rows
   *
   * @return list<array<string, mixed>>
   */
  private function incidentRowsToCards(array $rows): array {
    $cards = [];
    foreach ($rows as $row) {
      $type = (string) ($row['type'] ?? 'unknown');
      $count = (int) ($row['count'] ?? 0);
      $severity = (string) ($row['severity'] ?? 'info');
      $ctx = $row['context'] ?? [];
      $suffix = '';
      if ($type === 'failed_scan_attempt' && is_array($ctx) && $ctx !== []) {
        $pairs = [];
        foreach ($ctx as $k => $v) {
          if ($k === 'distinct_result_tokens') {
            $pairs[] = $k . '=' . $v;
          }
        }
        if ($pairs !== []) {
          $suffix = ' (' . implode('; ', $pairs) . ')';
        }
      }
      elseif ($type === 'issuance_mismatch' && isset($ctx['worst_alignment'])) {
        $suffix = ' (worst_alignment=' . $ctx['worst_alignment'] . ')';
      }
      $cards[] = [
        'label' => $type,
        'value' => (string) $count . $suffix,
        'severity' => $severity,
      ];
    }
    return $cards;
  }

  /**
   * @param array<string, mixed> $merged
   * @param array<int, array<string, mixed>> $inspections
   *
   * @return list<array<string, mixed>>
   */
  private function buildIntegrityExecutionCards(array $merged, array $inspections): array {
    $artifacts = $merged['artifacts'] ?? [];
    $cap = is_array($artifacts['entitlement_capability_policy'] ?? NULL) ? $artifacts['entitlement_capability_policy'] : [];
    $fulfilmentStates = [];
    foreach ($cap as $type => $map) {
      if (!is_array($map)) {
        continue;
      }
      $fulfilmentStates[(string) ($map['fulfilment_mode'] ?? '')] = TRUE;
    }

    $registry = $this->entitlementCapabilityRegistry->getAllCapabilityMaps();
    $observedTypes = array_keys($cap);
    $registryTypes = array_keys($registry);
    $missingRegistry = array_values(array_diff($observedTypes, $registryTypes));
    $orphanRegistry = array_values(array_diff($registryTypes, $observedTypes));

    $modes = [];
    $occ = is_array($artifacts['occupancy_policy'] ?? NULL) ? $artifacts['occupancy_policy'] : [];
    foreach ($occ as $row) {
      if (!is_array($row)) {
        continue;
      }
      $m = (string) (($row['occupancy_summary']['occupancy_mode'] ?? '') ?: '');
      if ($m !== '') {
        $modes[$m] = TRUE;
      }
    }

    $continuityModes = [];
    $cont = is_array($artifacts['operational_continuity'] ?? NULL) ? $artifacts['operational_continuity'] : [];
    foreach ($cont as $row) {
      if (!is_array($row)) {
        continue;
      }
      $cm = (string) (($row['continuity_summary']['continuity_mode'] ?? '') ?: '');
      if ($cm !== '') {
        $continuityModes[$cm] = TRUE;
      }
    }

    $cards = [
      [
        'label' => 'Entitlement consistency (catalog vs sample)',
        'value' => sprintf(
          'sample_types=%d; registry_types=%d; missing_in_registry=%s; unobserved_registry=%s',
          count($observedTypes),
          count($registryTypes),
          $missingRegistry === [] ? 'none' : implode(',', $missingRegistry),
          $orphanRegistry === [] ? 'none' : 'present',
        ),
      ],
      [
        'label' => 'Fulfillment consistency (distinct modes in sample)',
        'value' => implode(', ', array_keys($fulfilmentStates)) ?: 'n/a',
      ],
      [
        'label' => 'Operational mode summaries (occupancy)',
        'value' => implode(', ', array_keys($modes)) ?: 'n/a',
      ],
      [
        'label' => 'Continuity projections (distinct modes)',
        'value' => implode(', ', array_keys($continuityModes)) ?: 'n/a',
      ],
    ];

    foreach ($cap as $type => $map) {
      if (!is_string($type) || !is_array($map)) {
        continue;
      }
      $gate = $this->venueOperationPolicyManager->describeEntitlementGateSemantics($type);
      $cards[] = [
        'label' => 'Gate family — ' . $type,
        'value' => (string) ($gate['gate_family'] ?? 'n/a'),
      ];
    }

    $cards[] = [
      'label' => 'Sampled orders in workspace',
      'value' => (string) count($inspections),
    ];

    return $cards;
  }

}
