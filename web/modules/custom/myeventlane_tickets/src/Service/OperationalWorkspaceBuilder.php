<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Canonical read-model builder for the staff venue operations workspace.
 *
 * Operational payloads are composed from policy managers and
 * {@see OperationalIntegrityInspector} read models (sampled via
 * {@see OperationalIncidentBuilder}), then normalized into display-safe sections
 * without re-evaluating gate policy in Twig.
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
    'payload_sha256',
    'venue_operation_integrity',
  ];

  public function __construct(
    private readonly TimeInterface $time,
    private readonly VenueOperationPolicyManager $venueOperationPolicyManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly OperationalIncidentBuilder $operationalIncidentBuilder,
    private readonly OperationalRecoverySummaryBuilder $operationalRecoverySummaryBuilder,
    private readonly OperationalIncidentProjectionBuilder $operationalIncidentProjectionBuilder,
    private readonly AccountProxyInterface $currentUser,
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
  public function build(?NodeInterface $event, ?string $incident_lifecycle_filter = NULL): array {
    $cache_tags = ['config:myeventlane_tickets.settings'];
    $cache_contexts = ['user.permissions', 'user'];

    $meta = [
      'built_at' => $this->time->getRequestTime(),
      'scope' => $event ? 'event' : 'global',
      'event_id' => $event?->id(),
      'event_title' => $event?->getTitle(),
      'sampled_orders' => 0,
      'sampled_tickets' => 0,
    ];

    if ($event) {
      $cache_tags[] = 'node:' . $event->id();
    }

    $inspections = [];
    $ticket_ids = [];
    if ($event) {
      $ctx = $this->operationalIncidentBuilder->sampleEventOperationalContext($event);
      $inspections = $ctx['inspections'];
      $ticket_ids = $ctx['ticket_ids'];
      $meta['sampled_tickets'] = count($ticket_ids);
    }

    $merged = $this->mergeInspections($inspections);
    $meta['sampled_orders'] = count($inspections);

    $denied_scan_counts = [];
    if ($event && $ticket_ids !== []) {
      $denied_scan_counts = $this->operationalIncidentBuilder->aggregateDeniedScanResults(
        $ticket_ids,
        (int) $event->id(),
      );
    }

    $incident_bundle = $this->operationalIncidentBuilder->buildSections(
      $inspections,
      $merged,
      $denied_scan_counts,
    );
    $recovery_section = $this->operationalRecoverySummaryBuilder->buildSection($inspections, $merged);

    $entitlement_catalog = $this->buildEntitlementCatalogSection();
    $sections = [
      $incident_bundle[0],
      $recovery_section,
      $incident_bundle[2],
      $incident_bundle[1],
      $this->buildIntegritySection($merged),
      $this->buildVenueOperationsSection($merged),
      $this->buildScannerOperationsSection($merged),
      $entitlement_catalog,
    ];

    $coordination = $this->operationalIncidentProjectionBuilder->buildWorkspaceSection(
      $event,
      $incident_lifecycle_filter,
      $this->currentUser->getAccount(),
    );
    array_splice($sections, 1, 0, [$coordination]);

    $sections = $this->stripSensitiveRecursive($sections);

    return [
      'sections' => $sections,
      'meta' => $meta,
      'cache_tags' => $cache_tags,
      'cache_contexts' => $cache_contexts,
    ];
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
        'compatibility' => [],
      ];
    }

    $merged_issuance = $this->mergeIssuanceRollup($inspections);
    $merged_recovery = $this->mergeRecoveryRollup($inspections);
    $merged_guest = $this->mergeGuestContinuityRollup($inspections);
    $merged_artifacts = $this->mergeArtifactDomains($inspections);
    $merged_compatibility = $this->mergeCompatibilityRollup($inspections);

    return [
      'issuance' => $merged_issuance,
      'artifacts' => $merged_artifacts,
      'recovery' => $merged_recovery,
      'guest_continuity' => $merged_guest,
      'compatibility' => $merged_compatibility,
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
  private function mergeCompatibilityRollup(array $inspections): array {
    $rank = [
      'canonical' => 0,
      'valid' => 0,
      'unknown' => 1,
      'pending' => 2,
      'mixed' => 3,
      'legacy' => 3,
      'invalid' => 4,
      'missing' => 4,
    ];
    $keys = [
      'ticket_pdf_operational_path',
      'order_item_pdf_surface',
      'wallet_resolution_surface',
    ];
    $worst = [];
    foreach ($keys as $key) {
      $worst[$key] = 'unknown';
      $worst_score = -1;
      foreach ($inspections as $row) {
        $c = $row['compatibility'] ?? [];
        $val = (string) ($c[$key] ?? 'unknown');
        $score = $rank[$val] ?? 2;
        if ($score > $worst_score) {
          $worst_score = $score;
          $worst[$key] = $val;
        }
      }
    }
    return $worst;
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
