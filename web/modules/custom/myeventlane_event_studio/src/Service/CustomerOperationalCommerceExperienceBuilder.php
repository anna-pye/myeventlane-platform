<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\NodeInterface;

/**
 * Customer-safe operational + commerce expectation projections (read-only).
 *
 * Presentation payloads only: no inventory, checkout mutation, scanners, QR,
 * issuance, or governance audit material.
 */
final class CustomerOperationalCommerceExperienceBuilder {

  use StringTranslationTrait;

  /**
   * Keys never exposed to customer templates (recursive strip).
   *
   * @var list<string>
   */
  public const FORBIDDEN_CUSTOMER_KEYS = [
    'replay_token',
    'qr_payload',
    'device_fingerprint',
    'scanner_secret',
    'scanner_action',
    'scanner_mode',
    'scanner_visibility',
    'operational_fingerprint',
    'staff_integrity',
    'metadata_json',
    'ticket_code',
    'inventory_quantity',
    'stock_level',
    'warehouse_slot',
    'topology_fingerprint',
    'occupancy_internals',
    'zone_enforcement_internals',
    'audit_trace',
    'execution_descriptor',
  ];

  /**
   * @var list<string>
   */
  private const CONTRACT_ITEM_KEYS = [
    'capability_type',
    'capability_label',
    'fulfillment_summary',
    'reservation_summary',
    'pickup_summary',
    'readiness_label',
    'readiness_state',
    'redemption_summary',
    'customer_visibility',
    'operational_notice',
    'operational_chips',
    'timing_summary',
    'collection_summary',
    'hospitality_summary',
    'parking_summary',
  ];

  public function __construct(
    private readonly OperationalCapabilityCommerceLinkManager $commerceLinkManager,
    private readonly OperationalCapabilityPreviewBuilder $operationalCapabilityPreviewBuilder,
    private readonly OperationalCapabilityStudioManager $operationalCapabilityStudioManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly FulfillmentLifecycleManager $fulfillmentLifecycleManager,
    private readonly InventoryReservationGovernanceManager $inventoryReservationGovernanceManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Canonical customer-safe projection document.
   *
   * @return array<string, mixed>
   */
  public function buildCustomerOperationalExperienceForEvent(NodeInterface $event): array {
    $node = $this->entityTypeManager->getStorage('node')->load($event->id());
    $event = $node instanceof NodeInterface ? $node : $event;
    $document = $this->operationalCapabilityStudioManager->extractFromEvent($event);
    return $this->buildFromOperationalDocument($document);
  }

  /**
   * Builds experience from the first event referenced on a Commerce order.
   *
   * @return array<string, mixed>
   */
  public function buildCustomerOperationalExperienceForOrder(?OrderInterface $order): array {
    if ($order === NULL) {
      return $this->emptyExperience();
    }
    $event = $this->resolveEventFromOrder($order);
    if (!$event instanceof NodeInterface) {
      return $this->emptyExperience();
    }
    return $this->buildCustomerOperationalExperienceForEvent($event);
  }

  /**
   * @param array<string, mixed> $document
   *
   * @return array<string, mixed>
   */
  public function buildFromOperationalDocument(array $document): array {
    $preview = $this->operationalCapabilityPreviewBuilder->buildCustomerPreview($document);
    $capabilities = is_array($document['capabilities'] ?? NULL) ? $document['capabilities'] : [];
    $ui = $this->operationalCapabilityStudioManager->getCapabilityUiMetadata();
    $items = [];
    foreach ($this->operationalCapabilityStudioManager->allAuthoringCapabilityTypes() as $type) {
      $row = is_array($capabilities[$type] ?? NULL) ? $capabilities[$type] : [];
      if (empty($row['enabled'])) {
        continue;
      }
      $visibility = (string) ($row['customer_visibility'] ?? 'after_purchase');
      if ($visibility === 'hidden') {
        continue;
      }
      $semantics = $this->operationalCapabilityStudioManager->projectPolicySemantics($type, $row);
      $entitlement = (string) ($semantics['entitlement_type'] ?? '');
      $map = $entitlement !== '' ? $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement) : [];

      $item = [
        'capability_type' => $type,
        'capability_label' => (string) ($ui[$type]['label'] ?? $type),
        'fulfillment_summary' => $this->fulfillmentCustomerSummary($semantics),
        'reservation_summary' => $this->reservationCustomerSummary($semantics),
        'pickup_summary' => $this->pickupCustomerSummary($row, $map),
        'readiness_label' => $this->readinessLabel((string) ($row['readiness_state'] ?? '')),
        'readiness_state' => (string) ($row['readiness_state'] ?? OperationalCapabilityStudioManager::READINESS_NOT_CONFIGURED),
        'redemption_summary' => $this->redemptionCustomerSummary($map),
        'customer_visibility' => $visibility,
        'operational_notice' => (string) $this->t('Details may vary on the day — follow organiser instructions at the venue.'),
        'operational_chips' => $this->buildOperationalChips($type, $row, $semantics, $map),
        'timing_summary' => !empty($row['timed_entry'])
          ? (string) $this->t('Timed entry or collection may apply.')
          : '',
        'collection_summary' => $this->collectionSummary($row, $map),
        'hospitality_summary' => $type === OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS
          ? (string) $this->t('Hospitality-style access may require check-in at the venue.')
          : '',
        'parking_summary' => $type === OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS
          ? (string) $this->t('Parking access is subject to venue rules shown on the day.')
          : '',
      ];
      $item = $this->whitelistContractItem($item);
      $items[] = $this->sanitizeCustomerOperationalExperience($item);
    }

    $out = [
      'schema_version' => 1,
      'customer_operational_experience' => TRUE,
      'items' => $items,
      'empty' => $items === [],
      'preview_disclaimer' => (string) ($preview['disclaimer'] ?? ''),
    ];
    return $this->sanitizeCustomerOperationalExperience($out);
  }

  /**
   * Recursively removes forbidden keys and non-scalar leakage risks.
   *
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  public function sanitizeCustomerOperationalExperience(array $data): array {
    $out = [];
    foreach ($data as $key => $value) {
      if (is_string($key) && in_array($key, self::FORBIDDEN_CUSTOMER_KEYS, TRUE)) {
        continue;
      }
      if (is_array($value)) {
        $out[$key] = $this->sanitizeCustomerOperationalExperience($value);
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
  private function emptyExperience(): array {
    return [
      'schema_version' => 1,
      'customer_operational_experience' => TRUE,
      'items' => [],
      'empty' => TRUE,
      'preview_disclaimer' => '',
    ];
  }

  /**
   * @param array<string, mixed> $semantics
   */
  private function fulfillmentCustomerSummary(array $semantics): string {
    $mode = (string) ($semantics['fulfillment_mode'] ?? 'none');
    $ftype = (string) ($semantics['fulfillment_type'] ?? '');
    if ($ftype !== '') {
      return match ($ftype) {
        FulfillmentLifecycleManager::TYPE_MERCH_PICKUP => (string) $this->t('Merch or items may be collected at the venue after purchase.'),
        FulfillmentLifecycleManager::TYPE_HOSPITALITY => (string) $this->t('Hospitality benefits are fulfilled at the venue.'),
        default => $mode !== 'none'
          ? (string) $this->t('On-site fulfilment may apply.')
          : '',
      };
    }
    return $mode === 'collect'
      ? (string) $this->t('You may collect something on site.')
      : ($mode === 'redeem' ? (string) $this->t('You may redeem an offer at the venue.') : '');
  }

  /**
   * @param array<string, mixed> $semantics
   */
  private function reservationCustomerSummary(array $semantics): string {
    $token = (string) ($semantics['reservation_mode'] ?? '');
    if ($token === '' || $token === InventoryReservationGovernanceManager::TYPE_DIGITAL_REDEMPTION) {
      return '';
    }
    return (string) $this->t('A reservation style (@style) may apply for this benefit.', ['@style' => $token]);
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $map
   */
  private function pickupCustomerSummary(array $row, array $map): string {
    $pickup = (string) ($row['pickup_mode'] ?? 'none');
    if ($pickup === 'none' && empty($map['supports_collection'])) {
      return '';
    }
    if (!empty($map['supports_collection'])) {
      return (string) $this->t('Collection at the venue may be required for some items.');
    }
    return (string) $this->t('Pickup options may be offered at the venue.');
  }

  /**
   * @param array<string, mixed> $map
   */
  private function redemptionCustomerSummary(array $map): string {
    if (empty($map['redeemable'])) {
      return '';
    }
    return (string) $this->t('Some benefits may be redeemed at the venue.');
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $map
   */
  private function collectionSummary(array $row, array $map): string {
    if (empty($map['supports_collection'])) {
      return '';
    }
    if (!empty($row['timed_entry'])) {
      return (string) $this->t('Timed collection windows may apply.');
    }
    return (string) $this->t('On-site collection may be coordinated by the organiser.');
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $semantics
   * @param array<string, mixed> $map
   *
   * @return list<array{label: string, tone: string}>
   */
  private function buildOperationalChips(string $capability_type, array $row, array $semantics, array $map): array {
    $chips = [];
    if (!empty($semantics['scan_required'])) {
      $chips[] = ['label' => (string) $this->t('Venue check-in'), 'tone' => 'info'];
    }
    if (!empty($map['supports_collection'])) {
      $chips[] = ['label' => (string) $this->t('Collection'), 'tone' => 'success'];
    }
    elseif (!empty($map['redeemable'])) {
      $chips[] = ['label' => (string) $this->t('Redeem on site'), 'tone' => 'success'];
    }
    if (!empty($row['timed_entry'])) {
      $chips[] = ['label' => (string) $this->t('Timed'), 'tone' => 'warning'];
    }
    $link = is_array($row['commerce_linkage'] ?? NULL) ? $row['commerce_linkage'] : [];
    $preview = trim($this->commerceLinkManager->buildOperationalPreviewSummary($capability_type, $link));
    if ($preview !== '') {
      $chips[] = ['label' => $this->truncateChipLabel($preview), 'tone' => 'muted'];
    }
    return $chips;
  }

  private function truncateChipLabel(string $text): string {
    $text = trim($text);
    if (strlen($text) <= 72) {
      return $text;
    }
    return substr($text, 0, 69) . '…';
  }

  private function readinessLabel(string $state): string {
    return match ($state) {
      OperationalCapabilityStudioManager::READINESS_CONFIGURED => (string) $this->t('Ready'),
      OperationalCapabilityStudioManager::READINESS_NEEDS_REVIEW => (string) $this->t('Review suggested'),
      OperationalCapabilityStudioManager::READINESS_DRAFT => (string) $this->t('In progress'),
      default => (string) $this->t('Not configured'),
    };
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function whitelistContractItem(array $item): array {
    $out = [];
    foreach (self::CONTRACT_ITEM_KEYS as $key) {
      if (array_key_exists($key, $item)) {
        $out[$key] = $item[$key];
      }
    }
    return $out;
  }

  private function resolveEventFromOrder(OrderInterface $order): ?NodeInterface {
    foreach ($order->getItems() as $item) {
      if (in_array($item->bundle(), ['checkout_donation', 'platform_donation', 'rsvp_donation', 'boost'], TRUE)) {
        continue;
      }
      $event = NULL;
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
      }
      if (!$event) {
        $pe = $item->getPurchasedEntity();
        if ($pe instanceof ProductVariationInterface && $pe->hasField('field_event') && !$pe->get('field_event')->isEmpty()) {
          $event = $pe->get('field_event')->entity;
        }
      }
      if (!$event) {
        $pe = $item->getPurchasedEntity();
        if ($pe instanceof ProductVariationInterface) {
          $product = $pe->getProduct();
          if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
            $event = $product->get('field_event')->entity;
          }
        }
      }
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }
    return NULL;
  }

}
