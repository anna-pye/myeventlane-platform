<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;

/**
 * Normalizes and validates Commerce product/variation linkage for operational capabilities.
 *
 * Linking and preview only: no catalog mutation, checkout, inventory, or issuance.
 */
final class OperationalCapabilityCommerceLinkManager {

  public const BINDING_UNBOUND = 'unbound';

  public const BINDING_PARTIAL = 'partial';

  public const BINDING_BOUND = 'bound';

  public const BINDING_INVALID = 'invalid';

  public const LINKAGE_NONE = 'none';

  public const LINKAGE_PRODUCT = 'product';

  public const LINKAGE_VARIATIONS = 'variations';

  /**
   * Capability types that may reference purchasable Commerce entities for Studio linking.
   *
   * @var list<string>
   */
  private const COMMERCE_CAPABLE_TYPES = [
    OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP,
    OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION,
    OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS,
    OperationalEntitlementCapabilityManager::TYPE_TIMED_COLLECTION,
    OperationalEntitlementCapabilityManager::TYPE_CLOAKROOM_RETRIEVAL,
    OperationalEntitlementCapabilityManager::TYPE_DIGITAL_REDEMPTION,
    OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS,
    OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS,
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly OperationalEntitlementCapabilityManager $operationalEntitlementCapabilityManager,
    private readonly FulfillmentLifecycleManager $fulfillmentLifecycleManager,
    private readonly InventoryReservationGovernanceManager $inventoryReservationGovernanceManager,
  ) {}

  public function supportsCommerceLinkage(string $capability_type): bool {
    $type = $this->operationalEntitlementCapabilityManager->normalizeCapabilityType($capability_type);
    return in_array($type, self::COMMERCE_CAPABLE_TYPES, TRUE);
  }

  /**
   * Validates authoring input by re-normalizing commerce linkage (server-side source of truth).
   *
   * @return list<string>
   */
  public function validateAuthoringCapabilityRow(NodeInterface $event, string $capability_type, array $row): array {
    $normalized = $this->normalizeCommerceLinkage(
      $event,
      $capability_type,
      is_array($row['commerce_linkage'] ?? NULL) ? $row['commerce_linkage'] : [],
      !empty($row['enabled']),
    );
    if ($normalized['capability_binding_state'] === self::BINDING_INVALID) {
      return [(string) ($normalized['validation_message'] ?? 'Commerce linkage is invalid.')];
    }
    return [];
  }

  /**
   * @param array<string, mixed> $document
   *
   * @return list<string>
   */
  public function validateAuthoringDocument(NodeInterface $event, array $document): array {
    $errors = [];
    $capabilities = $document['capabilities'] ?? [];
    if (!is_array($capabilities)) {
      return [];
    }
    foreach ($capabilities as $type => $row) {
      if (!is_string($type) || !is_array($row)) {
        continue;
      }
      foreach ($this->validateAuthoringCapabilityRow($event, $type, $row) as $e) {
        $errors[] = $e;
      }
    }
    return $errors;
  }

  /**
   * @param array<string, mixed> $raw
   *
   * @return array<string, mixed>
   */
  public function normalizeCommerceLinkage(NodeInterface $event, string $capability_type, array $raw, bool $capability_enabled): array {
    $type = $this->operationalEntitlementCapabilityManager->normalizeCapabilityType($capability_type);
    $defaults = $this->emptyLinkage();

    if (!$capability_enabled || !in_array($type, self::COMMERCE_CAPABLE_TYPES, TRUE)) {
      return $defaults;
    }

    $product_id = max(0, (int) ($raw['product_id'] ?? 0));
    $linkage_mode = $this->normalizeLinkageMode((string) ($raw['linkage_mode'] ?? ''));
    $store_id = max(0, (int) ($raw['store_id'] ?? 0));
    $variation_ids = $this->normalizeVariationIds($raw['variation_ids'] ?? []);

    if ($product_id < 1 && $linkage_mode === self::LINKAGE_NONE) {
      return $this->decorateLinkageProjection($type, $defaults + [
        'linkage_mode' => self::LINKAGE_NONE,
        'capability_binding_state' => self::BINDING_UNBOUND,
      ]);
    }

    if ($product_id < 1) {
      return $this->invalidateLinkage('A Commerce product must be selected for this capability.');
    }

    $vendor_store_id = $this->resolveVendorStoreIdForEvent($event);
    if ($vendor_store_id === NULL) {
      return $this->invalidateLinkage('Vendor store could not be resolved for this event.');
    }

    if ($store_id > 0 && $store_id !== $vendor_store_id) {
      return $this->invalidateLinkage('Linked store does not match the event vendor store.');
    }

    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $product = $product_storage->load($product_id);
    if (!$product instanceof ProductInterface) {
      return $this->invalidateLinkage('Commerce product was not found.');
    }

    if (!$this->productIsInStore($product, $vendor_store_id)) {
      return $this->invalidateLinkage('This product is not available in your store.');
    }

    if ($linkage_mode === self::LINKAGE_VARIATIONS && $variation_ids !== []) {
      $var_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
      foreach ($variation_ids as $vid) {
        $variation = $var_storage->load($vid);
        if (!$variation instanceof ProductVariationInterface) {
          return $this->invalidateLinkage(sprintf('Variation %d is not valid.', $vid));
        }
        if ((int) $variation->getProductId() !== $product_id) {
          return $this->invalidateLinkage('A selected variation does not belong to the linked product.');
        }
      }
    }
    elseif ($linkage_mode === self::LINKAGE_VARIATIONS) {
      $linkage_mode = self::LINKAGE_PRODUCT;
    }

    $entitlement = $this->operationalEntitlementCapabilityManager->mapCanonicalCapabilityTypeToEntitlement($type);
    $fulfillment_ref = $this->fulfillmentLifecycleManager->mapEntitlementToFulfillmentType($entitlement, 1);
    $reservation_ref = $this->inventoryReservationGovernanceManager->mapEntitlementToReservationType($entitlement, 1, '');

    $binding = $linkage_mode === self::LINKAGE_VARIATIONS && $variation_ids !== []
      ? self::BINDING_BOUND
      : ($linkage_mode === self::LINKAGE_PRODUCT ? self::BINDING_BOUND : self::BINDING_PARTIAL);

    $row = [
      'product_id' => $product_id,
      'variation_ids' => $linkage_mode === self::LINKAGE_VARIATIONS ? array_values(array_unique($variation_ids)) : [],
      'store_id' => $vendor_store_id,
      'linkage_mode' => $linkage_mode,
      'fulfillment_reference' => $fulfillment_ref,
      'reservation_reference' => $this->inventoryReservationGovernanceManager->normalizeReservationType($reservation_ref),
      'readiness_projection' => [],
      'customer_visibility' => $this->normalizeLinkageVisibility((string) ($raw['customer_visibility'] ?? 'inherit')),
      'operational_preview' => '',
      'capability_binding_state' => $binding,
      'validation_message' => '',
    ];

    return $this->decorateLinkageProjection($type, $row);
  }

  /**
   * Customer-safe one-line preview for linkage (no SKUs, stock, or secrets).
   *
   * @param array<string, mixed> $commerce_linkage
   */
  public function buildOperationalPreviewSummary(string $capability_type, array $commerce_linkage): string {
    $state = (string) ($commerce_linkage['capability_binding_state'] ?? self::BINDING_UNBOUND);
    if ($state === self::BINDING_INVALID || $state === self::BINDING_UNBOUND) {
      return '';
    }
    $type = $this->operationalEntitlementCapabilityManager->normalizeCapabilityType($capability_type);
    $mode = (string) ($commerce_linkage['linkage_mode'] ?? self::LINKAGE_NONE);
    $parts = [];
    if (($commerce_linkage['product_id'] ?? 0) > 0) {
      $parts[] = 'Commerce product linked';
    }
    if ($mode === self::LINKAGE_VARIATIONS && ($commerce_linkage['variation_ids'] ?? []) !== []) {
      $parts[] = 'specific variations selected';
    }
    $parts[] = sprintf('%s capability', $type);
    $fulf = (string) ($commerce_linkage['fulfillment_reference'] ?? '');
    if ($fulf !== '' && $fulf !== 'none') {
      $parts[] = 'fulfillment style ' . $fulf;
    }
    $res = (string) ($commerce_linkage['reservation_reference'] ?? '');
    if ($res !== '' && $res !== InventoryReservationGovernanceManager::TYPE_DIGITAL_REDEMPTION) {
      $parts[] = 'reservation profile ' . $res;
    }

    $entitlement = $this->operationalEntitlementCapabilityManager->mapCanonicalCapabilityTypeToEntitlement($type);
    $cap = $this->entitlementCapabilityRegistry->getCapabilityMap($entitlement);
    if (!empty($cap['requires_fulfilment'])) {
      $parts[] = 'venue fulfilment may apply';
    }
    if (!empty($cap['supports_collection'])) {
      $parts[] = 'on-site collection may apply';
    }

    return implode(' · ', array_filter($parts));
  }

  /**
   * @return array<string, mixed>
   */
  private function decorateLinkageProjection(string $capability_type, array $row): array {
    $preview = $this->buildOperationalPreviewSummary($capability_type, $row);
    $row['operational_preview'] = $preview;
    $row['readiness_projection'] = [
      'descriptor' => (string) ($row['capability_binding_state'] ?? self::BINDING_UNBOUND),
      'commerce_link_ready' => (($row['capability_binding_state'] ?? '') === self::BINDING_BOUND),
      'authoring_only' => TRUE,
    ];
    return $row;
  }

  /**
   * @return array<string, mixed>
   */
  public static function emptyLinkageTemplate(): array {
    return [
      'product_id' => 0,
      'variation_ids' => [],
      'store_id' => 0,
      'linkage_mode' => self::LINKAGE_NONE,
      'fulfillment_reference' => '',
      'reservation_reference' => '',
      'readiness_projection' => [
        'descriptor' => self::BINDING_UNBOUND,
        'commerce_link_ready' => FALSE,
        'authoring_only' => TRUE,
      ],
      'customer_visibility' => 'inherit',
      'operational_preview' => '',
      'capability_binding_state' => self::BINDING_UNBOUND,
      'validation_message' => '',
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyLinkage(): array {
    return self::emptyLinkageTemplate();
  }

  /**
   * @return array<string, mixed>
   */
  private function invalidateLinkage(string $message): array {
    return [
      'product_id' => 0,
      'variation_ids' => [],
      'store_id' => 0,
      'linkage_mode' => self::LINKAGE_NONE,
      'fulfillment_reference' => '',
      'reservation_reference' => '',
      'readiness_projection' => [
        'descriptor' => self::BINDING_INVALID,
        'commerce_link_ready' => FALSE,
        'authoring_only' => TRUE,
      ],
      'customer_visibility' => 'inherit',
      'operational_preview' => '',
      'capability_binding_state' => self::BINDING_INVALID,
      'validation_message' => $message,
    ];
  }

  private function normalizeLinkageMode(string $raw): string {
    $t = strtolower(trim($raw));
    return match ($t) {
      self::LINKAGE_PRODUCT, self::LINKAGE_VARIATIONS => $t,
      default => self::LINKAGE_PRODUCT,
    };
  }

  /**
   * @param mixed $raw
   *
   * @return list<int>
   */
  private function normalizeVariationIds(mixed $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (is_int($raw) || (is_string($raw) && is_numeric($raw))) {
      $id = (int) $raw;
      return $id > 0 ? [$id] : [];
    }
    if (!is_array($raw)) {
      return [];
    }
    $out = [];
    foreach ($raw as $v) {
      $id = (int) $v;
      if ($id > 0) {
        $out[] = $id;
      }
    }
    return array_values(array_unique($out));
  }

  private function normalizeLinkageVisibility(string $raw): string {
    $t = strtolower(trim($raw));
    return in_array($t, ['inherit', 'hidden', 'visible', 'after_purchase'], TRUE) ? $t : 'inherit';
  }

  /**
   * Resolves the vendor Commerce store ID for an event (vendor entity store).
   */
  public function resolveVendorStoreIdForEvent(NodeInterface $event): ?int {
    return $this->resolveEventVendorStoreId($event);
  }

  /**
   * Resolves a store for operational product authoring (vendor store, then event store field).
   */
  public function resolveStoreIdForOperationalProductCreation(NodeInterface $event): ?int {
    $id = $this->resolveEventVendorStoreId($event);
    if ($id !== NULL) {
      return $id;
    }
    if ($event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
      return (int) $event->get('field_event_store')->target_id;
    }
    return NULL;
  }

  private function resolveEventVendorStoreId(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return $this->resolveActingVendorStoreId();
    }
    $vendor = $event->get('field_event_vendor')->entity;
    if (!$vendor instanceof Vendor) {
      return $this->resolveActingVendorStoreId();
    }
    if (!$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }
    return (int) $vendor->get('field_vendor_store')->target_id;
  }

  private function resolveActingVendorStoreId(): ?int {
    $uid = (int) $this->currentUser->id();
    if ($uid < 1) {
      return NULL;
    }
    if (!$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $or = $query->orConditionGroup()
      ->condition('uid', $uid)
      ->condition('field_vendor_users', $uid);
    $query->condition($or)->sort('id', 'ASC');
    $ids = $query->range(0, 1)->execute();
    if ($ids === []) {
      return NULL;
    }
    $vendor = $storage->load(reset($ids));
    if (!$vendor instanceof Vendor) {
      return NULL;
    }
    if (!$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }
    return (int) $vendor->get('field_vendor_store')->target_id;
  }

  private function productIsInStore(ProductInterface $product, int $store_id): bool {
    if (!$product->hasField('stores') || $product->get('stores')->isEmpty()) {
      return FALSE;
    }
    foreach ($product->get('stores')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $store_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
