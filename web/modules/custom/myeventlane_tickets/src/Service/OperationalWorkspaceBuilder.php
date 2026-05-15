<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Canonical read-model builder for the staff venue operations workspace.
 *
 * Operational payloads are derived exclusively from policy managers and the
 * {@see OperationalIntegrityInspector}; this class normalizes and merges them
 * into display-safe sections without re-evaluating gate policy in Twig.
 */
final class OperationalWorkspaceBuilder {

  /**
   * Keys removed from any nested array before workspace render variables.
   *
   * @var list<string>
   */
  private const SENSITIVE_KEYS = [
    'replay_token',
    'hmac',
    'hmac_material',
    'device_fingerprint',
    'reconciliation_fingerprint',
    'continuity_descriptor_token',
    'operation_fingerprint',
    'replay_continuity_metadata',
    'deterministic_continuity_descriptor',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly VenueOperationPolicyManager $venueOperationPolicyManager,
    private readonly OperationalIntegrityInspector $operationalIntegrityInspector,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly AccountProxyInterface $currentUser,
    private readonly OperationalEscalationAuditProjector $operationalEscalationAuditProjector,
    private readonly InventoryReservationProjectionBuilder $inventoryReservationProjectionBuilder,
    private readonly InventoryReservationAuditProjector $inventoryReservationAuditProjector,
  ) {}

  /**
   * Builds normalized workspace sections for the operational shell.
   *
   * @return array{
   *   sections: list<array<string, mixed>>,
   *   meta: array<string, mixed>,
   *   cache_tags: list<string>,
   *   cache_contexts: list<string>
   * }
   */
  public function build(?NodeInterface $event): array {
    $cache_tags = ['config:myeventlane_tickets.settings'];
    $cache_contexts = ['user.permissions', 'user'];

    $governance_enabled = $this->currentUser->hasPermission('govern mel operational escalations');
    $reservation_projection_enabled = $this->currentUser->hasPermission('govern mel inventory reservations');

    $meta = [
      'built_at' => $this->time->getRequestTime(),
      'scope' => $event ? 'event' : 'global',
      'event_id' => $event?->id(),
      'event_title' => $event?->getTitle(),
      'sampled_orders' => 0,
      'sampled_tickets' => 0,
      'governance_projection_enabled' => $governance_enabled,
      'reservation_projection_enabled' => $reservation_projection_enabled,
    ];

    if ($event) {
      $cache_tags[] = 'node:' . $event->id();
    }

    $inspections = $event ? $this->collectInspectionsForEvent($event, $meta) : [];
    $merged = $this->mergeInspections($inspections);
    $meta['sampled_orders'] = count($inspections);

    $entitlement_catalog = $this->buildEntitlementCatalogSection();
    $sections = [
      $this->buildIntegritySection($merged),
      $this->buildVenueOperationsSection($merged),
      $this->buildScannerOperationsSection($merged),
      $entitlement_catalog,
    ];

    if ($governance_enabled) {
      $governance = $this->operationalEscalationAuditProjector->buildWorkspaceGovernanceSections(
        $merged,
        (int) $meta['built_at']
      );
      foreach ($governance as $gov_section) {
        $sections[] = $gov_section;
      }
    }

    if ($reservation_projection_enabled) {
      $reservation_sections = $this->inventoryReservationProjectionBuilder->buildWorkspaceReservationSections(
        $merged,
        (int) $meta['built_at']
      );
      foreach ($reservation_sections as $reservation_section) {
        $sections[] = $reservation_section;
      }
      $reservation_audit = $this->inventoryReservationAuditProjector->buildWorkspaceReservationAuditSections(
        $merged,
        (int) $meta['built_at']
      );
      foreach ($reservation_audit as $reservation_audit_section) {
        $sections[] = $reservation_audit_section;
      }
    }

    $sections = $this->stripSensitiveRecursive($sections);

    return [
      'sections' => $sections,
      'meta' => $meta,
      'cache_tags' => $cache_tags,
      'cache_contexts' => $cache_contexts,
    ];
  }

  /**
   * @param array<string, mixed> $meta
   *
   * @return array<int|string, array<string, mixed>>
   */
  private function collectInspectionsForEvent(NodeInterface $event, array &$meta): array {
    $ticket_storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $ticket_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event->id())
      ->range(0, 40)
      ->sort('id')
      ->execute();
    if (!$ids) {
      return [];
    }
    /** @var array<int|string, \Drupal\myeventlane_tickets\Entity\Ticket> $tickets */
    $tickets = $ticket_storage->loadMultiple($ids);
    $meta['sampled_tickets'] = count($tickets);

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
    return $out;
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   *
   * @return array<string, mixed>
   */
  private function mergeInspections(array $inspections): array {
    if ($inspections === []) {
      return [
        'issuance' => [],
        'artifacts' => [],
        'recovery' => [],
        'guest_continuity' => [],
        'fulfillment_signals' => [
          'by_ticket_id' => [],
        ],
      ];
    }

    $merged_issuance = $this->mergeIssuanceRollup($inspections);
    $merged_recovery = $this->mergeRecoveryRollup($inspections);
    $merged_guest = $this->mergeGuestContinuityRollup($inspections);
    $merged_artifacts = $this->mergeArtifactDomains($inspections);
    $merged_fulfillment = $this->mergeFulfillmentSignals($inspections);

    return [
      'issuance' => $merged_issuance,
      'artifacts' => $merged_artifacts,
      'recovery' => $merged_recovery,
      'guest_continuity' => $merged_guest,
      'fulfillment_signals' => $merged_fulfillment,
    ];
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   *
   * @return array<string, mixed>
   */
  private function mergeFulfillmentSignals(array $inspections): array {
    $by = [];
    foreach ($inspections as $row) {
      $sig = $row['fulfillment_operational_signals'] ?? [];
      $chunk = is_array($sig['by_ticket_id'] ?? NULL) ? $sig['by_ticket_id'] : [];
      foreach ($chunk as $tid => $slice) {
        if (is_array($slice)) {
          $by[(string) $tid] = $slice;
        }
      }
    }
    return [
      'by_ticket_id' => $by,
    ];
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   *
   * @return array<string, mixed>
   */
  private function mergeIssuanceRollup(array $inspections): array {
    $expected = 0;
    $issued = 0;
    $orphan_tickets = 0;
    $orphan_items = 0;
    $rank = ['valid' => 0, 'pending' => 1, 'missing' => 2, 'invalid' => 3];
    $worst = 'valid';
    $worst_score = -1;
    foreach ($inspections as $row) {
      $i = $row['issuance'] ?? [];
      $expected += (int) ($i['expected_quantity'] ?? 0);
      $issued += (int) ($i['issued_ticket_count'] ?? 0);
      $orphan_tickets += (int) ($i['orphan_ticket_count'] ?? 0);
      $orphan_items += (int) ($i['orphan_eligible_order_item_count'] ?? 0);
      $align = (string) ($i['quantity_alignment_status'] ?? 'missing');
      $score = $rank[$align] ?? 2;
      if ($score > $worst_score) {
        $worst_score = $score;
        $worst = $align;
      }
    }
    return [
      'aggregated_expected_quantity' => $expected,
      'aggregated_issued_ticket_count' => $issued,
      'worst_quantity_alignment_status' => $worst,
      'aggregated_orphan_ticket_count' => $orphan_tickets,
      'aggregated_orphan_eligible_order_item_count' => $orphan_items,
    ];
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   *
   * @return array<string, mixed>
   */
  private function mergeRecoveryRollup(array $inspections): array {
    $completion_states = [];
    $recovery_mismatch = FALSE;
    foreach ($inspections as $row) {
      $r = $row['recovery'] ?? [];
      $completion_states[] = (string) ($r['completion_state'] ?? 'unknown');
      if (!empty($r['recovery_mismatch'])) {
        $recovery_mismatch = TRUE;
      }
    }
    return [
      'completion_states_observed' => array_values(array_unique($completion_states)),
      'recovery_mismatch_observed' => $recovery_mismatch,
    ];
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   *
   * @return array<string, mixed>
   */
  private function mergeGuestContinuityRollup(array $inspections): array {
    $statuses = [];
    foreach ($inspections as $row) {
      $g = $row['guest_continuity'] ?? [];
      $statuses[] = (string) ($g['continuity_status'] ?? 'unknown');
    }
    return [
      'continuity_statuses_observed' => array_values(array_unique($statuses)),
    ];
  }

  /**
   * @param array<int|string, array<string, mixed>> $inspections
   *
   * @return array<string, mixed>
   */
  private function mergeArtifactDomains(array $inspections): array {
    $keys = [
      'canonical_pdf_readiness',
      'wallet_route_scaffold',
      'qr_payload_operational',
      'attachment_continuity',
      'entitlement_capability_policy',
      'venue_operation_policy',
      'timed_entry_policy',
      'session_entitlement_policy',
      'zone_access_topology',
      'operational_identity',
      'operational_continuity',
      'occupancy_policy',
    ];
    $merged = [];
    foreach ($keys as $key) {
      $merged[$key] = [];
    }
    $readiness_rank = ['valid' => 0, 'canonical' => 0, 'legacy' => 1, 'mixed' => 2, 'pending' => 2, 'missing' => 3];
    $worst = [];
    foreach (['canonical_pdf_readiness', 'wallet_route_scaffold', 'qr_payload_operational', 'attachment_continuity'] as $readiness_key) {
      $worst[$readiness_key] = 'valid';
      $worst_score = -1;
      foreach ($inspections as $row) {
        $val = (string) (($row['artifacts'] ?? [])[$readiness_key] ?? 'missing');
        $score = $readiness_rank[$val] ?? 2;
        if ($score > $worst_score) {
          $worst_score = $score;
          $worst[$readiness_key] = $val;
        }
      }
      $merged[$readiness_key] = $worst[$readiness_key];
    }

    foreach ($inspections as $row) {
      $art = $row['artifacts'] ?? [];
      foreach (['entitlement_capability_policy', 'venue_operation_policy', 'timed_entry_policy', 'session_entitlement_policy', 'zone_access_topology', 'operational_identity', 'operational_continuity', 'occupancy_policy'] as $map_key) {
        if (!empty($art[$map_key]) && is_array($art[$map_key])) {
          /** @var array<string, mixed> $slice */
          $slice = $art[$map_key];
          foreach ($slice as $subKey => $value) {
            if (!isset($merged[$map_key][$subKey])) {
              $merged[$map_key][$subKey] = $value;
            }
          }
        }
      }
    }
    return $merged;
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return array<string, mixed>
   */
  private function buildIntegritySection(array $merged): array {
    $issuance = $merged['issuance'] ?? [];
    $recovery = $merged['recovery'] ?? [];
    $guest = $merged['guest_continuity'] ?? [];
    $artifacts = $merged['artifacts'] ?? [];

    return [
      'id' => 'operational_integrity',
      'title' => 'Operational integrity',
      'cards' => [
        [
          'label' => 'Issuance alignment (worst observed)',
          'value' => (string) ($issuance['worst_quantity_alignment_status'] ?? 'n/a'),
        ],
        [
          'label' => 'Aggregated expected tickets',
          'value' => (string) (int) ($issuance['aggregated_expected_quantity'] ?? 0),
        ],
        [
          'label' => 'Aggregated issued tickets',
          'value' => (string) (int) ($issuance['aggregated_issued_ticket_count'] ?? 0),
        ],
        [
          'label' => 'Orphan ticket rows (sum)',
          'value' => (string) (int) ($issuance['aggregated_orphan_ticket_count'] ?? 0),
        ],
        [
          'label' => 'Orphan eligible order items (sum)',
          'value' => (string) (int) ($issuance['aggregated_orphan_eligible_order_item_count'] ?? 0),
        ],
        [
          'label' => 'Canonical PDF readiness (worst)',
          'value' => (string) ($artifacts['canonical_pdf_readiness'] ?? 'n/a'),
        ],
        [
          'label' => 'Attachment continuity (worst)',
          'value' => (string) ($artifacts['attachment_continuity'] ?? 'n/a'),
        ],
        [
          'label' => 'Recovery completion states',
          'value' => implode(', ', $recovery['completion_states_observed'] ?? []),
        ],
        [
          'label' => 'Recovery mismatch observed',
          'value' => !empty($recovery['recovery_mismatch_observed']) ? 'yes' : 'no',
        ],
        [
          'label' => 'Guest continuity statuses',
          'value' => implode(', ', $guest['continuity_statuses_observed'] ?? []),
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return array<string, mixed>
   */
  private function buildVenueOperationsSection(array $merged): array {
    $artifacts = $merged['artifacts'] ?? [];
    $venue = is_array($artifacts['venue_operation_policy'] ?? NULL) ? $artifacts['venue_operation_policy'] : [];
    $zones = is_array($artifacts['zone_access_topology'] ?? NULL) ? $artifacts['zone_access_topology'] : [];
    $occ = is_array($artifacts['occupancy_policy'] ?? NULL) ? $artifacts['occupancy_policy'] : [];

    $cards = [];
    $cards[] = [
      'label' => 'Entitlement gate profiles (sampled types)',
      'value' => (string) count($venue),
    ];
    foreach ($venue as $type => $row) {
      if (!is_array($row)) {
        continue;
      }
      $gf = (string) (($row['gate_semantics']['gate_family'] ?? '') ?: 'n/a');
      $cards[] = [
        'label' => 'Gate family — ' . $type,
        'value' => $gf,
      ];
    }

    $zone_summaries = 0;
    foreach ($zones as $z) {
      if (is_array($z)) {
        $zone_summaries++;
      }
    }
    $cards[] = [
      'label' => 'Zone topology rows (sampled tickets)',
      'value' => (string) $zone_summaries,
    ];

    $ap_modes = [];
    foreach ($occ as $row) {
      if (!is_array($row)) {
        continue;
      }
      $m = (string) (($row['occupancy_summary']['anti_passback_mode'] ?? '') ?: '');
      if ($m !== '') {
        $ap_modes[$m] = TRUE;
      }
    }
    $cards[] = [
      'label' => 'Anti-passback modes (distinct)',
      'value' => implode(', ', array_keys($ap_modes)) ?: 'n/a',
    ];

    return [
      'id' => 'venue_operations',
      'title' => 'Venue operations',
      'cards' => $cards,
    ];
  }

  /**
   * @param array<string, mixed> $merged
   *
   * @return array<string, mixed>
   */
  private function buildScannerOperationsSection(array $merged): array {
    $artifacts = $merged['artifacts'] ?? [];
    $ident = is_array($artifacts['operational_identity'] ?? NULL) ? $artifacts['operational_identity'] : [];
    $cont = is_array($artifacts['operational_continuity'] ?? NULL) ? $artifacts['operational_continuity'] : [];
    $timed = is_array($artifacts['timed_entry_policy'] ?? NULL) ? $artifacts['timed_entry_policy'] : [];
    $session = is_array($artifacts['session_entitlement_policy'] ?? NULL) ? $artifacts['session_entitlement_policy'] : [];

    $offline_eligible = 0;
    foreach ($ident as $row) {
      if (is_array($row) && !empty($row['offline_eligible'])) {
        $offline_eligible++;
      }
    }

    $continuity_modes = [];
    foreach ($cont as $row) {
      if (!is_array($row)) {
        continue;
      }
      $mode = (string) (($row['continuity_summary']['continuity_mode'] ?? '') ?: '');
      if ($mode !== '') {
        $continuity_modes[$mode] = TRUE;
      }
    }

    $timing_states = [];
    foreach ($timed as $row) {
      $p = is_array($row['policy'] ?? NULL) ? $row['policy'] : [];
      $s = (string) (($p['scanner']['state'] ?? '') ?: '');
      if ($s !== '') {
        $timing_states[$s] = TRUE;
      }
    }

    $session_states = [];
    foreach ($session as $row) {
      $p = is_array($row['policy'] ?? NULL) ? $row['policy'] : [];
      $s = (string) (($p['scanner']['state'] ?? '') ?: '');
      if ($s !== '') {
        $session_states[$s] = TRUE;
      }
    }

    return [
      'id' => 'scanner_operations',
      'title' => 'Scanner operations',
      'cards' => [
        [
          'label' => 'Sampled ticket rows (identity)',
          'value' => (string) count($ident),
        ],
        [
          'label' => 'Offline-eligible tickets (descriptor)',
          'value' => (string) $offline_eligible,
        ],
        [
          'label' => 'Continuity modes (distinct)',
          'value' => implode(', ', array_keys($continuity_modes)) ?: 'n/a',
        ],
        [
          'label' => 'Timed-entry scanner states (distinct)',
          'value' => implode(', ', array_keys($timing_states)) ?: 'n/a',
        ],
        [
          'label' => 'Session scanner states (distinct)',
          'value' => implode(', ', array_keys($session_states)) ?: 'n/a',
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEntitlementCatalogSection(): array {
    $maps = $this->entitlementCapabilityRegistry->getAllCapabilityMaps();
    $cards = [];
    foreach ($maps as $type => $map) {
      $gate = $this->venueOperationPolicyManager->describeEntitlementGateSemantics($type);
      $action = $this->entitlementCapabilityRegistry->getScannerOperationAction($type);
      $cards[] = [
        'label' => 'Type — ' . $type,
        'value' => sprintf(
          'fulfilment=%s; scanner_action=%s; gate_family=%s',
          (string) ($map['fulfilment_mode'] ?? ''),
          $action,
          (string) ($gate['gate_family'] ?? ''),
        ),
      ];
    }

    return [
      'id' => 'entitlement_operations',
      'title' => 'Entitlement operations (type catalog)',
      'cards' => $cards,
    ];
  }

  /**
   * @param mixed $value
   *
   * @return mixed
   */
  private function stripSensitiveRecursive(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
      $key = (string) $k;
      if ($this->isSensitiveKey($key)) {
        continue;
      }
      $out[$k] = $this->stripSensitiveRecursive($v);
    }
    return $out;
  }

  private function isSensitiveKey(string $key): bool {
    foreach (self::SENSITIVE_KEYS as $blocked) {
      if ($key === $blocked) {
        return TRUE;
      }
    }
    if (str_contains(strtolower($key), 'fingerprint')) {
      return TRUE;
    }
    if (str_contains(strtolower($key), 'replay_token')) {
      return TRUE;
    }
    return FALSE;
  }

}
