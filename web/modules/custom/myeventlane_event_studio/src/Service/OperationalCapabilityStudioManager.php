<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_tickets\Service\VenueOperationPolicyManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Canonical Event Studio authoring normalization for operational capabilities.
 *
 * Metadata-only: configures, validates, and projects previews. Does not execute
 * scans, reserve stock, issue entitlements, or mutate operational truth.
 */
final class OperationalCapabilityStudioManager {

  public const SCHEMA_VERSION = 2;

  public const READINESS_NOT_CONFIGURED = 'not_configured';

  public const READINESS_DRAFT = 'draft';

  public const READINESS_CONFIGURED = 'configured';

  public const READINESS_NEEDS_REVIEW = 'needs_review';

  /**
   * Keys stripped from inbound authoring payloads (never persisted).
   *
   * @var list<string>
   */
  private const FORBIDDEN_PAYLOAD_KEYS = [
    'replay_token',
    'qr_payload',
    'device_fingerprint',
    'scanner_secret',
    'operational_fingerprint',
    'staff_integrity',
    'metadata_json',
    'ticket_code',
    'inventory_quantity',
    'stock_level',
    'warehouse_slot',
  ];

  public function __construct(
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly VenueOperationPolicyManager $venueOperationPolicyManager,
    private readonly OperationalEntitlementCapabilityManager $operationalEntitlementCapabilityManager,
    private readonly FulfillmentLifecycleManager $fulfillmentLifecycleManager,
    private readonly InventoryReservationGovernanceManager $inventoryReservationGovernanceManager,
    private readonly LoggerInterface $logger,
    private readonly ?OperationalCapabilityCommerceLinkManager $operationalCapabilityCommerceLinkManager = NULL,
  ) {}

  /**
   * @return list<string>
   */
  public function allAuthoringCapabilityTypes(): array {
    return $this->operationalEntitlementCapabilityManager->allCanonicalTypes();
  }

  /**
   * @return array<string, string>
   */
  public function allowedReservationModeOptions(): array {
    $options = [];
    foreach ($this->allowedReservationModes() as $token) {
      $options[$token] = $token;
    }
    return $options;
  }

  /**
   * UI metadata for capability cards (labels, groups, entitlement hints).
   *
   * @return array<string, array<string, mixed>>
   */
  public function getCapabilityUiMetadata(): array {
    $labels = [
      OperationalEntitlementCapabilityManager::TYPE_ADMISSION => 'Admission',
      OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => 'Merch pickup',
      OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION => 'Food & drink',
      OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS => 'VIP',
      OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS => 'Parking',
      OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS => 'Hospitality',
      OperationalEntitlementCapabilityManager::TYPE_TIMED_COLLECTION => 'Timed collection',
      OperationalEntitlementCapabilityManager::TYPE_CLOAKROOM_RETRIEVAL => 'Cloakroom',
      OperationalEntitlementCapabilityManager::TYPE_DIGITAL_REDEMPTION => 'Digital redemption',
    ];
    $groups = [
      OperationalEntitlementCapabilityManager::TYPE_ADMISSION => 'entry',
      OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => 'fulfilment',
      OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION => 'fulfilment',
      OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS => 'access',
      OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS => 'access',
      OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS => 'access',
      OperationalEntitlementCapabilityManager::TYPE_TIMED_COLLECTION => 'fulfilment',
      OperationalEntitlementCapabilityManager::TYPE_CLOAKROOM_RETRIEVAL => 'fulfilment',
      OperationalEntitlementCapabilityManager::TYPE_DIGITAL_REDEMPTION => 'fulfilment',
    ];

    $out = [];
    foreach ($this->allAuthoringCapabilityTypes() as $type) {
      $entitlement = $this->capabilityEntitlementType($type);
      $map = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);
      $gate = $this->venueOperationPolicyManager->describeEntitlementGateSemantics($entitlement);
      $out[$type] = [
        'capability_type' => $type,
        'label' => $labels[$type] ?? $type,
        'group' => $groups[$type] ?? 'fulfilment',
        'default_fulfillment_mode' => (string) ($map['fulfilment_mode'] ?? 'none'),
        'default_gate_family' => (string) ($gate['gate_family'] ?? ''),
        'scan_required' => (bool) ($map['scan_required'] ?? FALSE),
        'supports_collection' => (bool) ($map['supports_collection'] ?? FALSE),
      ];
    }
    return $out;
  }

  /**
   * Loads normalized mel_operational_capabilities from an event node.
   *
   * @return array<string, mixed>
   */
  public function extractFromEvent(NodeInterface $event): array {
    if (!$event->hasField('field_mel_op_capabilities')
      || $event->get('field_mel_op_capabilities')->isEmpty()) {
      return $this->emptyDocument();
    }
    $raw = trim((string) $event->get('field_mel_op_capabilities')->value);
    if ($raw === '') {
      return $this->emptyDocument();
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->warning('Operational capability extract failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyDocument();
    }
    if (!is_array($decoded)) {
      return $this->emptyDocument();
    }
    return $this->normalizeDocument($decoded, $event);
  }

  /**
   * Normalizes a `mel` fragment or raw document into mel_operational_capabilities.
   *
   * @param array<string, mixed> $raw
   */
  public function normalizeMelFragment(array $raw, ?NodeInterface $event = NULL): array {
    if (isset($raw['mel_operational_capabilities']) && is_array($raw['mel_operational_capabilities'])) {
      return $this->normalizeDocument($raw['mel_operational_capabilities'], $event);
    }
    if (isset($raw['operational_capabilities']) && is_array($raw['operational_capabilities'])) {
      $inner = $raw['operational_capabilities'];
      if (isset($inner['items_state']) && is_string($inner['items_state'])) {
        return $this->normalizeItemsStateJson($inner['items_state'], $event);
      }
      return $this->normalizeDocument($inner, $event);
    }
    if (isset($raw['items_state']) && is_string($raw['items_state'])) {
      return $this->normalizeItemsStateJson($raw['items_state'], $event);
    }
    if (isset($raw['capabilities']) && is_array($raw['capabilities'])) {
      return $this->normalizeDocument($raw, $event);
    }
    return $this->emptyDocument();
  }

  /**
   * @return list<string>
   */
  public function validateDocument(NodeInterface $event, array $document): array {
    $errors = [];
    $capabilities = $document['capabilities'] ?? [];
    if (!is_array($capabilities)) {
      return ['Operational capabilities data was invalid.'];
    }

    foreach ($capabilities as $type => $row) {
      if (!is_string($type) || !is_array($row)) {
        $errors[] = 'Each operational capability entry must be valid.';
        continue;
      }
      $normalized_type = $this->operationalEntitlementCapabilityManager->normalizeCapabilityType($type);
      if ($normalized_type !== $type) {
        $errors[] = sprintf('Unknown capability type "%s".', $type);
      }
      $fulfillment_mode = (string) ($row['fulfillment_mode'] ?? '');
      if ($fulfillment_mode !== '' && !in_array($fulfillment_mode, $this->allowedFulfillmentModes(), TRUE)) {
        $errors[] = sprintf('Invalid fulfillment mode for %s.', $normalized_type);
      }
      $reservation_mode = (string) ($row['reservation_mode'] ?? '');
      if ($reservation_mode !== '' && !in_array($reservation_mode, $this->allowedReservationModes(), TRUE)) {
        $errors[] = sprintf('Invalid reservation mode for %s.', $normalized_type);
      }
      $visibility = (string) ($row['customer_visibility'] ?? '');
      if ($visibility !== '' && !in_array($visibility, $this->allowedCustomerVisibility(), TRUE)) {
        $errors[] = sprintf('Invalid customer visibility for %s.', $normalized_type);
      }
    }

    if ($this->operationalCapabilityCommerceLinkManager !== NULL) {
      foreach ($this->operationalCapabilityCommerceLinkManager->validateAuthoringDocument($event, $document) as $e) {
        $errors[] = $e;
      }
    }

    return $errors;
  }

  /**
   * Authoring readiness projection (configuration completeness only).
   *
   * @return array<string, mixed>
   */
  public function projectOperationalReadiness(array $document): array {
    $capabilities = is_array($document['capabilities'] ?? NULL) ? $document['capabilities'] : [];
    $enabled = 0;
    $configured = 0;
    $needs_review = 0;
    foreach ($this->allAuthoringCapabilityTypes() as $type) {
      $row = is_array($capabilities[$type] ?? NULL) ? $capabilities[$type] : [];
      if (empty($row['enabled'])) {
        continue;
      }
      $enabled++;
      $state = (string) ($row['readiness_state'] ?? self::READINESS_NOT_CONFIGURED);
      if ($state === self::READINESS_CONFIGURED) {
        $configured++;
      }
      elseif ($state === self::READINESS_NEEDS_REVIEW) {
        $needs_review++;
      }
    }

    $descriptor = 'not_started';
    if ($enabled > 0 && $configured === $enabled) {
      $descriptor = 'configured';
    }
    elseif ($enabled > 0 && $needs_review > 0) {
      $descriptor = 'needs_review';
    }
    elseif ($enabled > 0) {
      $descriptor = 'in_progress';
    }

    return [
      'descriptor' => $descriptor,
      'enabled_count' => $enabled,
      'configured_count' => $configured,
      'needs_review_count' => $needs_review,
      'execution_note' => 'authoring_projection_only',
    ];
  }

  /**
   * Policy projection for one capability row (semantics delegation only).
   *
   * @return array<string, mixed>
   */
  public function projectPolicySemantics(string $capability_type, array $row): array {
    $type = $this->operationalEntitlementCapabilityManager->normalizeCapabilityType($capability_type);
    $entitlement = $this->capabilityEntitlementType($type);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);
    $gate = $this->venueOperationPolicyManager->describeEntitlementGateSemantics($entitlement);
    $fulfillment_type = $this->fulfillmentLifecycleManager->mapEntitlementToFulfillmentType($entitlement, 1);
    $reservation_type = $this->inventoryReservationGovernanceManager->mapEntitlementToReservationType($entitlement, 1, '');

    $fulfillment_mode = (string) ($row['fulfillment_mode'] ?? ($map['fulfilment_mode'] ?? 'none'));
    $reservation_mode = (string) ($row['reservation_mode'] ?? $reservation_type);

    return [
      'capability_type' => $type,
      'entitlement_type' => $entitlement,
      'fulfillment_mode' => $fulfillment_mode,
      'reservation_mode' => $this->inventoryReservationGovernanceManager->normalizeReservationType($reservation_mode),
      'gate_family' => (string) ($gate['gate_family'] ?? ''),
      'gate_action' => (string) ($gate['gate_action'] ?? ''),
      'fulfillment_type' => $fulfillment_type,
      'scan_required' => (bool) ($map['scan_required'] ?? FALSE),
      'redeemable' => (bool) ($map['redeemable'] ?? FALSE),
      'supports_collection' => (bool) ($map['supports_collection'] ?? FALSE),
      'timed_entry' => (bool) ($row['timed_entry'] ?? FALSE),
      'session_rules' => (string) ($row['session_rules'] ?? 'none'),
      'zone_rules' => (string) ($row['zone_rules'] ?? 'none'),
      'occupancy_rules' => (string) ($row['occupancy_rules'] ?? 'none'),
      'continuity_mode' => (string) ($row['continuity_mode'] ?? 'online'),
      'pickup_mode' => (string) ($row['pickup_mode'] ?? 'none'),
    ];
  }

  /**
   * Customer-safe preview summary for one capability (no secrets).
   */
  public function buildCustomerSafePreviewSummary(string $capability_type, array $row): string {
    if (empty($row['enabled'])) {
      return '';
    }
    $semantics = $this->projectPolicySemantics($capability_type, $row);
    $visibility = (string) ($row['customer_visibility'] ?? 'after_purchase');
    if ($visibility === 'hidden') {
      return '';
    }
    $parts = [];
    $parts[] = (string) ($this->getCapabilityUiMetadata()[$semantics['capability_type']]['label'] ?? $capability_type);
    if ($semantics['scan_required']) {
      $parts[] = 'scan at venue';
    }
    if ($semantics['supports_collection']) {
      $parts[] = 'collect on site';
    }
    elseif ($semantics['redeemable']) {
      $parts[] = 'redeem at venue';
    }
    if (!empty($row['timed_entry'])) {
      $parts[] = 'timed entry';
    }
    $cl = is_array($row['commerce_linkage'] ?? NULL) ? $row['commerce_linkage'] : [];
    $op = trim((string) ($cl['operational_preview'] ?? ''));
    if ($op !== '') {
      $parts[] = $op;
    }
    return implode(' · ', array_filter($parts));
  }

  /**
   * Persists normalized document on the event (metadata field only).
   */
  public function persistToEvent(NodeInterface $event, array $document): void {
    if (!$event->hasField('field_mel_op_capabilities')) {
      $this->logger->error('Operational capability persist skipped: field missing on event @nid.', [
        '@nid' => (string) $event->id(),
      ]);
      return;
    }
    $normalized = $this->normalizeDocument($document, $event);
    $errors = $this->validateDocument($event, $normalized);
    if ($errors !== []) {
      throw new \InvalidArgumentException(implode(' ', $errors));
    }
    $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $event->set('field_mel_op_capabilities', $encoded === '[]' ? NULL : $encoded);
  }

  /**
   * @return array<string, mixed>
   */
  public function emptyDocument(): array {
    $capabilities = [];
    foreach ($this->allAuthoringCapabilityTypes() as $type) {
      $capabilities[$type] = $this->defaultCapabilityRow($type, FALSE);
    }
    return [
      'schema_version' => self::SCHEMA_VERSION,
      'capabilities' => $capabilities,
    ];
  }

  /**
   * @param array<string, mixed> $document
   *
   * @return array<string, mixed>
   */
  public function normalizeDocument(array $document, ?NodeInterface $event = NULL): array {
    $document = $this->stripUnsafeKeys($document);
    $capabilities_in = is_array($document['capabilities'] ?? NULL) ? $document['capabilities'] : [];
    $capabilities = [];
    foreach ($this->allAuthoringCapabilityTypes() as $type) {
      $incoming = is_array($capabilities_in[$type] ?? NULL) ? $capabilities_in[$type] : [];
      $capabilities[$type] = $this->normalizeCapabilityRow($type, $incoming, $event);
    }
    return [
      'schema_version' => self::SCHEMA_VERSION,
      'capabilities' => $capabilities,
    ];
  }

  /**
   * @param array<string, mixed> $incoming
   *
   * @return array<string, mixed>
   */
  private function normalizeCapabilityRow(string $capability_type, array $incoming, ?NodeInterface $event = NULL): array {
    $type = $this->operationalEntitlementCapabilityManager->normalizeCapabilityType($capability_type);
    $entitlement = $this->capabilityEntitlementType($type);
    $map = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);
    $default_reservation = $this->inventoryReservationGovernanceManager->mapEntitlementToReservationType($entitlement, 1, '');

    $enabled = !empty($incoming['enabled']);
    $fulfillment_default = (string) ($map['fulfilment_mode'] ?? 'none');
    $fulfillment_mode = trim((string) ($incoming['fulfillment_mode'] ?? $fulfillment_default));
    if (!in_array($fulfillment_mode, $this->allowedFulfillmentModes(), TRUE)) {
      $fulfillment_mode = $fulfillment_default;
    }

    $reservation_mode = trim((string) ($incoming['reservation_mode'] ?? $default_reservation));
    $reservation_mode = $this->inventoryReservationGovernanceManager->normalizeReservationType($reservation_mode);

    $readiness = trim((string) ($incoming['readiness_state'] ?? ''));
    if (!in_array($readiness, $this->allowedAuthoringReadinessStates(), TRUE)) {
      $readiness = $enabled ? self::READINESS_DRAFT : self::READINESS_NOT_CONFIGURED;
    }

    $visibility = trim((string) ($incoming['customer_visibility'] ?? 'after_purchase'));
    if (!in_array($visibility, $this->allowedCustomerVisibility(), TRUE)) {
      $visibility = 'after_purchase';
    }

    $row = [
      'capability_type' => $type,
      'enabled' => $enabled,
      'fulfillment_mode' => $fulfillment_mode,
      'reservation_mode' => $reservation_mode,
      'timed_entry' => !empty($incoming['timed_entry']),
      'session_rules' => $this->normalizeRuleToken((string) ($incoming['session_rules'] ?? 'none'), $this->allowedSessionRules()),
      'zone_rules' => $this->normalizeRuleToken((string) ($incoming['zone_rules'] ?? 'none'), $this->allowedZoneRules()),
      'occupancy_rules' => $this->normalizeRuleToken((string) ($incoming['occupancy_rules'] ?? 'none'), $this->allowedOccupancyRules()),
      'continuity_mode' => $this->normalizeRuleToken((string) ($incoming['continuity_mode'] ?? 'online'), $this->allowedContinuityModes()),
      'pickup_mode' => $this->normalizeRuleToken((string) ($incoming['pickup_mode'] ?? 'none'), $this->allowedPickupModes()),
      'readiness_state' => $readiness,
      'customer_visibility' => $visibility,
      'preview_summary' => '',
    ];

    $row['commerce_linkage'] = $this->normalizeCommerceLinkageForRow($event, $type, $incoming, $enabled);

    $preview = trim((string) ($incoming['preview_summary'] ?? ''));
    if ($preview === '' && $enabled) {
      $preview = $this->buildCustomerSafePreviewSummary($type, ['enabled' => TRUE] + $incoming + ['commerce_linkage' => $row['commerce_linkage']]);
    }
    $row['preview_summary'] = $this->sanitizeCustomerText($preview);

    if ($enabled && $readiness === self::READINESS_DRAFT) {
      $row['readiness_state'] = $this->inferReadinessFromRow($row);
    }

    return $row;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function inferReadinessFromRow(array $row): string {
    if (empty($row['enabled'])) {
      return self::READINESS_NOT_CONFIGURED;
    }
    $link = is_array($row['commerce_linkage'] ?? NULL) ? $row['commerce_linkage'] : [];
    if (($link['capability_binding_state'] ?? '') === OperationalCapabilityCommerceLinkManager::BINDING_INVALID) {
      return self::READINESS_NEEDS_REVIEW;
    }
    if ((string) ($row['customer_visibility'] ?? '') === 'hidden'
      && (string) ($row['fulfillment_mode'] ?? '') === 'none'
      && empty($row['timed_entry'])) {
      return self::READINESS_NEEDS_REVIEW;
    }
    return self::READINESS_CONFIGURED;
  }

  /**
   * @return array<string, mixed>
   */
  private function defaultCapabilityRow(string $capability_type, bool $enabled): array {
    return $this->normalizeCapabilityRow($capability_type, ['enabled' => $enabled], NULL);
  }

  private function normalizeItemsStateJson(string $raw, ?NodeInterface $event = NULL): array {
    $trimmed = trim($raw);
    if ($trimmed === '') {
      return $this->emptyDocument();
    }
    try {
      $decoded = json_decode($trimmed, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->emptyDocument();
    }
    if (!is_array($decoded)) {
      return $this->emptyDocument();
    }
    return $this->normalizeDocument($decoded, $event);
  }

  private function normalizeCommerceLinkageForRow(?NodeInterface $event, string $type, array $incoming, bool $enabled): array {
    if ($this->operationalCapabilityCommerceLinkManager !== NULL && $event !== NULL) {
      return $this->operationalCapabilityCommerceLinkManager->normalizeCommerceLinkage(
        $event,
        $type,
        is_array($incoming['commerce_linkage'] ?? NULL) ? $incoming['commerce_linkage'] : [],
        $enabled,
      );
    }
    return $this->mergeCommerceLinkageFallback(is_array($incoming['commerce_linkage'] ?? NULL) ? $incoming['commerce_linkage'] : []);
  }

  /**
   * @param array<string, mixed> $raw
   *
   * @return array<string, mixed>
   */
  private function mergeCommerceLinkageFallback(array $raw): array {
    $t = OperationalCapabilityCommerceLinkManager::emptyLinkageTemplate();
    foreach (self::FORBIDDEN_PAYLOAD_KEYS as $key) {
      unset($raw[$key]);
    }
    $t['product_id'] = max(0, (int) ($raw['product_id'] ?? 0));
    $t['variation_ids'] = [];
    foreach ((array) ($raw['variation_ids'] ?? []) as $vid) {
      $id = (int) $vid;
      if ($id > 0) {
        $t['variation_ids'][] = $id;
      }
    }
    $t['variation_ids'] = array_values(array_unique($t['variation_ids']));
    $t['store_id'] = max(0, (int) ($raw['store_id'] ?? 0));
    $lm = strtolower(trim((string) ($raw['linkage_mode'] ?? '')));
    $t['linkage_mode'] = in_array($lm, [OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT, OperationalCapabilityCommerceLinkManager::LINKAGE_VARIATIONS, OperationalCapabilityCommerceLinkManager::LINKAGE_NONE], TRUE)
      ? $lm
      : OperationalCapabilityCommerceLinkManager::LINKAGE_NONE;
    $t['fulfillment_reference'] = trim((string) ($raw['fulfillment_reference'] ?? ''));
    $t['reservation_reference'] = trim((string) ($raw['reservation_reference'] ?? ''));
    $t['operational_preview'] = trim((string) ($raw['operational_preview'] ?? ''));
    $cv = strtolower(trim((string) ($raw['customer_visibility'] ?? 'inherit')));
    $t['customer_visibility'] = in_array($cv, ['inherit', 'hidden', 'visible', 'after_purchase'], TRUE) ? $cv : 'inherit';
    $binding = strtolower(trim((string) ($raw['capability_binding_state'] ?? OperationalCapabilityCommerceLinkManager::BINDING_UNBOUND)));
    $t['capability_binding_state'] = in_array($binding, [
      OperationalCapabilityCommerceLinkManager::BINDING_UNBOUND,
      OperationalCapabilityCommerceLinkManager::BINDING_PARTIAL,
      OperationalCapabilityCommerceLinkManager::BINDING_BOUND,
      OperationalCapabilityCommerceLinkManager::BINDING_INVALID,
    ], TRUE) ? $binding : OperationalCapabilityCommerceLinkManager::BINDING_UNBOUND;
    $t['readiness_projection'] = is_array($raw['readiness_projection'] ?? NULL)
      ? $raw['readiness_projection']
      : OperationalCapabilityCommerceLinkManager::emptyLinkageTemplate()['readiness_projection'];
    $t['validation_message'] = trim((string) ($raw['validation_message'] ?? ''));
    return $t;
  }

  private function capabilityEntitlementType(string $capability_type): string {
    return $this->operationalEntitlementCapabilityManager->mapCanonicalCapabilityTypeToEntitlement($capability_type);
  }

  /**
   * @param list<string> $allowed
   */
  private function normalizeRuleToken(string $raw, array $allowed): string {
    $token = strtolower(trim($raw));
    return in_array($token, $allowed, TRUE) ? $token : $allowed[0];
  }

  /**
   * @return list<string>
   */
  private function allowedFulfillmentModes(): array {
    return ['none', 'collect', 'redeem', 'optional'];
  }

  /**
   * @return list<string>
   */
  private function allowedReservationModes(): array {
    return $this->inventoryReservationGovernanceManager->allReservationTypes();
  }

  /**
   * @return list<string>
   */
  private function allowedAuthoringReadinessStates(): array {
    return [
      self::READINESS_NOT_CONFIGURED,
      self::READINESS_DRAFT,
      self::READINESS_CONFIGURED,
      self::READINESS_NEEDS_REVIEW,
    ];
  }

  /**
   * @return list<string>
   */
  private function allowedCustomerVisibility(): array {
    return ['hidden', 'visible', 'after_purchase'];
  }

  /**
   * @return list<string>
   */
  private function allowedSessionRules(): array {
    return ['none', 'optional', 'required'];
  }

  /**
   * @return list<string>
   */
  private function allowedZoneRules(): array {
    return ['none', 'optional', 'strict'];
  }

  /**
   * @return list<string>
   */
  private function allowedOccupancyRules(): array {
    return ['none', 'policy_only', 'strict'];
  }

  /**
   * @return list<string>
   */
  private function allowedContinuityModes(): array {
    return ['online', 'offline_eligible', 'degraded'];
  }

  /**
   * @return list<string>
   */
  private function allowedPickupModes(): array {
    return ['none', 'counter', 'locker', 'staff_escort'];
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  private function stripUnsafeKeys(array $data): array {
    foreach (self::FORBIDDEN_PAYLOAD_KEYS as $key) {
      unset($data[$key]);
    }
    if (isset($data['capabilities']) && is_array($data['capabilities'])) {
      foreach ($data['capabilities'] as $i => $row) {
        if (!is_array($row)) {
          continue;
        }
        foreach (self::FORBIDDEN_PAYLOAD_KEYS as $key) {
          unset($data['capabilities'][$i][$key]);
        }
        if (isset($row['commerce_linkage']) && is_array($row['commerce_linkage'])) {
          foreach (self::FORBIDDEN_PAYLOAD_KEYS as $key) {
            unset($data['capabilities'][$i]['commerce_linkage'][$key]);
          }
        }
      }
    }
    return $data;
  }

  private function sanitizeCustomerText(string $text): string {
    $text = preg_replace('/\b(replay_token|qr_payload|device_fingerprint|scanner_secret)\b/i', '', $text) ?? $text;
    return trim(substr($text, 0, 512));
  }

}
