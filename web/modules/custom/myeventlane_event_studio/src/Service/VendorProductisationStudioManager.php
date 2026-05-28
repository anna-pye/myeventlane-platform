<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\node\NodeInterface;

/**
 * Vendor-facing operational productisation authoring (metadata + linkage only).
 *
 * Delegates Commerce product semantics to {@see OperationalMerchandiseManager},
 * Commerce linkage validation to {@see OperationalCapabilityCommerceLinkManager},
 * and keeps execution boundaries (no stock, warehouse, shipping, scanners, QR,
 * entitlements, checkout mutation) out of this layer.
 */
final class VendorProductisationStudioManager {

  public const SCHEMA_VERSION = 1;

  public const TYPE_MERCHANDISE = 'merchandise';

  public const TYPE_HOSPITALITY_PACKAGE = 'hospitality_package';

  public const TYPE_TIMED_COLLECTION = 'timed_collection';

  public const TYPE_PARKING_ADDON = 'parking_addon';

  public const TYPE_OPERATIONAL_BUNDLE = 'operational_bundle';

  /**
   * @var list<string>
   */
  public const PRODUCTISATION_TYPES = [
    self::TYPE_MERCHANDISE,
    self::TYPE_HOSPITALITY_PACKAGE,
    self::TYPE_TIMED_COLLECTION,
    self::TYPE_PARKING_ADDON,
    self::TYPE_OPERATIONAL_BUNDLE,
  ];

  /**
   * Keys never stored on productisation authoring rows (recursive strip).
   *
   * @var list<string>
   */
  public const FORBIDDEN_PRODUCTISATION_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_action',
    'scanner_tokens',
    'scanner_secret',
    'inventory_quantity',
    'stock_count',
    'stock_level',
    'warehouse_ids',
    'warehouse_slot',
    'shipment_provider',
    'shipment_state',
    'device_fingerprint',
    'entitlement_token',
    'ticket_code',
    'entitlement_id',
    'ticket_id',
  ];

  public function __construct(
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalCapabilityCommerceLinkManager $commerceLinkManager,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function emptyItem(string $productisation_type): array {
    $type = $this->normalizeProductisationType($productisation_type);
    return [
      'item_id' => $type,
      'productisation_type' => $type,
      'commerce' => [
        'product_id' => 0,
        'variation_ids' => [],
        'linkage_mode' => OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
      ],
    ] + $this->defaultFieldsForType($type);
  }

  /**
   * @param array<string, mixed> $merch
   *   `operational_merchandise` fragment (may include legacy `linked_products` only).
   *
   * @return array<string, mixed>
   *   Authoring state for Event Studio (no entity loads required).
   */
  public function buildAuthoringStateFromMerchandise(array $merch, NodeInterface $event): array {
    $rawItems = is_array($merch['productisation_items'] ?? NULL) ? $merch['productisation_items'] : [];
    if ($rawItems === [] && !empty($merch['linked_products'])) {
      $normalized = $this->normalizeProductisationItems($this->seedItemsFromLegacyLinkedProducts(is_array($merch['linked_products']) ? $merch['linked_products'] : []));
    }
    else {
      $normalized = $this->normalizeProductisationItems($rawItems);
    }
    $validation = $this->validateMerchandiseFragment($event, ['productisation_items' => $normalized, 'linked_products' => $merch['linked_products'] ?? []]);
    return [
      'schema_version' => self::SCHEMA_VERSION,
      'items' => $normalized,
      'validation_messages' => $validation,
      'event_id' => (int) $event->id(),
    ];
  }

  /**
   * Merges derived Commerce links from productisation rows before Commerce normalization.
   *
   * @param array<string, mixed> $merch_in
   *
   * @return array<string, mixed>
   */
  public function prepareMerchandiseInputForCommerceNormalization(array $merch_in, NodeInterface $event): array {
    $items = $this->normalizeProductisationItems(is_array($merch_in['productisation_items'] ?? NULL) ? $merch_in['productisation_items'] : []);
    $merch_in['productisation_items'] = $items;
    $derived = $this->deriveLinkedProductsFromItems($items);
    $existing = is_array($merch_in['linked_products'] ?? NULL) ? $merch_in['linked_products'] : [];
    $merch_in['linked_products'] = $this->mergeLinkedProductLists($existing, $derived);
    return $merch_in;
  }

  /**
   * @param array<string, mixed> $merch
   *
   * @return list<string>
   */
  public function validateMerchandiseFragment(NodeInterface $event, array $merch): array {
    $errors = [];
    $items = is_array($merch['productisation_items'] ?? NULL) ? $merch['productisation_items'] : [];
    if (count($items) > 25) {
      $errors[] = 'Too many productisation rows for one event.';
    }
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      foreach ($this->validateProductisationItem($event, $item) as $e) {
        $errors[] = $e;
      }
    }
    return $errors;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return list<string>
   */
  public function validateProductisationItem(NodeInterface $event, array $item): array {
    $type = $this->normalizeProductisationType((string) ($item['productisation_type'] ?? ''));
    $commerce = is_array($item['commerce'] ?? NULL) ? $item['commerce'] : [];
    $product_id = (int) ($commerce['product_id'] ?? 0);
    if ($product_id < 1) {
      return [];
    }
    $cap = $this->mapProductisationTypeToCapabilityType($type);
    $row = [
      'enabled' => TRUE,
      'commerce_linkage' => [
        'product_id' => $product_id,
        'variation_ids' => $this->normalizeVariationIdsList($commerce['variation_ids'] ?? []),
        'linkage_mode' => $this->normalizeLinkageModeToken((string) ($commerce['linkage_mode'] ?? OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT)),
        'customer_visibility' => 'inherit',
      ],
    ];
    return $this->commerceLinkManager->validateAuthoringCapabilityRow($event, $cap, $row);
  }

  /**
   * @param array<int|string, mixed> $data
   *
   * @return array<int|string, mixed>
   */
  public function stripForbiddenRecursive(array $data): array {
    if (array_is_list($data)) {
      $out = [];
      foreach ($data as $item) {
        if (is_array($item)) {
          $out[] = $this->stripForbiddenRecursive($item);
        }
        elseif (is_scalar($item) || $item === NULL) {
          $out[] = $item;
        }
      }
      return $out;
    }
    $out = [];
    foreach ($data as $key => $value) {
      if (!is_string($key) || in_array($key, self::FORBIDDEN_PRODUCTISATION_KEYS, TRUE)) {
        continue;
      }
      if (is_array($value)) {
        $out[$key] = $this->stripForbiddenRecursive($value);
      }
      elseif (is_scalar($value) || $value === NULL) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $linked
   *
   * @return list<array<string, mixed>>
   */
  private function seedItemsFromLegacyLinkedProducts(array $linked): array {
    $out = [];
    foreach ($linked as $row) {
      if (!is_array($row)) {
        continue;
      }
      $role = (string) ($row['role'] ?? '');
      $type = match ($role) {
        'hospitality' => self::TYPE_HOSPITALITY_PACKAGE,
        'timed_collection' => self::TYPE_TIMED_COLLECTION,
        'parking' => self::TYPE_PARKING_ADDON,
        'operational_bundle' => self::TYPE_OPERATIONAL_BUNDLE,
        default => self::TYPE_MERCHANDISE,
      };
      $item = $this->emptyItem($type);
      $item['commerce']['product_id'] = (int) ($row['product_id'] ?? 0);
      $out[] = $item;
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return list<array<string, mixed>>
   */
  private function normalizeProductisationItems(array $items): array {
    $out = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $type = $this->normalizeProductisationType((string) ($item['productisation_type'] ?? ''));
      $base = $this->emptyItem($type);
      $merged = $this->stripForbiddenRecursive($item + $base);
      $merged['productisation_type'] = $type;
      $merged['item_id'] = trim((string) ($merged['item_id'] ?? $type)) !== '' ? trim((string) $merged['item_id']) : $type;
      $merged['commerce'] = $this->normalizeCommerceFragment(is_array($merged['commerce'] ?? NULL) ? $merged['commerce'] : []);
      $out[] = $this->applyTypeFieldLimits($type, $merged);
    }
    if ($out === []) {
      foreach (self::PRODUCTISATION_TYPES as $t) {
        $out[] = $this->emptyItem($t);
      }
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $commerce
   *
   * @return array<string, mixed>
   */
  private function normalizeCommerceFragment(array $commerce): array {
    $mode = $this->normalizeLinkageModeToken((string) ($commerce['linkage_mode'] ?? OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT));
    $ids = $this->normalizeVariationIdsList($commerce['variation_ids'] ?? []);
    return [
      'product_id' => max(0, (int) ($commerce['product_id'] ?? 0)),
      'variation_ids' => $ids,
      'linkage_mode' => $mode,
    ];
  }

  /**
   * @param array<int|string, mixed> $raw
   *
   * @return list<int>
   */
  private function normalizeVariationIdsList(mixed $raw): array {
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $vid) {
      $id = (int) $vid;
      if ($id > 0) {
        $out[] = $id;
      }
    }
    return array_values(array_unique($out));
  }

  private function normalizeLinkageModeToken(string $mode): string {
    $mode = strtolower(trim($mode));
    return in_array($mode, [
      OperationalCapabilityCommerceLinkManager::LINKAGE_NONE,
      OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
      OperationalCapabilityCommerceLinkManager::LINKAGE_VARIATIONS,
    ], TRUE) ? $mode : OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT;
  }

  /**
   * @return array<string, mixed>
   */
  private function defaultFieldsForType(string $type): array {
    return match ($type) {
      self::TYPE_MERCHANDISE => [
        'title' => '',
        'customer_summary' => '',
        'customer_visibility' => 'visible',
        'pickup_mode' => 'counter',
      ],
      self::TYPE_HOSPITALITY_PACKAGE => [
        'package_name' => '',
        'benefits_summary' => '',
        'customer_visibility' => 'visible',
        'fulfillment_mode' => 'redeem',
      ],
      self::TYPE_TIMED_COLLECTION => [
        'collection_label' => '',
        'collection_guidance' => '',
        'pickup_window_copy' => '',
        'customer_visibility' => 'visible',
      ],
      self::TYPE_PARKING_ADDON => [
        'parking_guidance' => '',
        'access_timing_copy' => '',
        'customer_visibility' => 'visible',
      ],
      self::TYPE_OPERATIONAL_BUNDLE => [
        'bundle_label' => '',
        'grouped_capability_types' => [],
        'customer_summary' => '',
        'visibility' => 'visible',
      ],
      default => [],
    };
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function applyTypeFieldLimits(string $type, array $item): array {
    $s = static function (string $v, int $max): string {
      $v = trim($v);
      return strlen($v) > $max ? substr($v, 0, $max - 1) . '…' : $v;
    };
    if ($type === self::TYPE_MERCHANDISE) {
      $item['title'] = $s((string) ($item['title'] ?? ''), 180);
      $item['customer_summary'] = $s((string) ($item['customer_summary'] ?? ''), 600);
      $item['customer_visibility'] = $this->normalizeVisibility((string) ($item['customer_visibility'] ?? 'visible'), TRUE);
      $item['pickup_mode'] = $s((string) ($item['pickup_mode'] ?? 'counter'), 64);
    }
    elseif ($type === self::TYPE_HOSPITALITY_PACKAGE) {
      $item['package_name'] = $s((string) ($item['package_name'] ?? ''), 180);
      $item['benefits_summary'] = $s((string) ($item['benefits_summary'] ?? ''), 600);
      $item['customer_visibility'] = $this->normalizeVisibility((string) ($item['customer_visibility'] ?? 'visible'), TRUE);
      $item['fulfillment_mode'] = $s((string) ($item['fulfillment_mode'] ?? 'redeem'), 64);
    }
    elseif ($type === self::TYPE_TIMED_COLLECTION) {
      $item['collection_label'] = $s((string) ($item['collection_label'] ?? ''), 180);
      $item['collection_guidance'] = $s((string) ($item['collection_guidance'] ?? ''), 600);
      $item['pickup_window_copy'] = $s((string) ($item['pickup_window_copy'] ?? ''), 400);
      $item['customer_visibility'] = $this->normalizeVisibility((string) ($item['customer_visibility'] ?? 'visible'), TRUE);
    }
    elseif ($type === self::TYPE_PARKING_ADDON) {
      $item['parking_guidance'] = $s((string) ($item['parking_guidance'] ?? ''), 600);
      $item['access_timing_copy'] = $s((string) ($item['access_timing_copy'] ?? ''), 400);
      $item['customer_visibility'] = $this->normalizeVisibility((string) ($item['customer_visibility'] ?? 'visible'), TRUE);
    }
    elseif ($type === self::TYPE_OPERATIONAL_BUNDLE) {
      $item['bundle_label'] = $s((string) ($item['bundle_label'] ?? ''), 180);
      $item['customer_summary'] = $s((string) ($item['customer_summary'] ?? ''), 600);
      $item['visibility'] = $this->normalizeVisibility((string) ($item['visibility'] ?? 'visible'), FALSE);
      $item['grouped_capability_types'] = $this->normalizeGroupedCapabilityTypes($item['grouped_capability_types'] ?? []);
    }
    return $item;
  }

  /**
   * @param list<string>|mixed $raw
   *
   * @return list<string>
   */
  private function normalizeGroupedCapabilityTypes(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $allowed = [
      OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP,
      OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS,
      OperationalEntitlementCapabilityManager::TYPE_TIMED_COLLECTION,
      OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS,
      OperationalEntitlementCapabilityManager::TYPE_FOOD_DRINK_REDEMPTION,
    ];
    $out = [];
    foreach ($raw as $t) {
      $t = (string) $t;
      if (in_array($t, $allowed, TRUE)) {
        $out[] = $t;
      }
    }
    return array_values(array_unique($out));
  }

  private function normalizeVisibility(string $visibility, bool $include_after_purchase): string {
    $v = strtolower(trim($visibility));
    $allowed = $include_after_purchase
      ? ['visible', 'hidden', 'after_purchase']
      : ['visible', 'hidden'];
    return in_array($v, $allowed, TRUE) ? $v : 'visible';
  }

  private function normalizeProductisationType(string $type): string {
    $type = strtolower(trim($type));
    return in_array($type, self::PRODUCTISATION_TYPES, TRUE) ? $type : self::TYPE_MERCHANDISE;
  }

  /**
   * Maps productisation authoring type to entitlement capability type for linkage validation.
   */
  private function mapProductisationTypeToCapabilityType(string $type): string {
    return match ($type) {
      self::TYPE_HOSPITALITY_PACKAGE => OperationalEntitlementCapabilityManager::TYPE_HOSPITALITY_ACCESS,
      self::TYPE_TIMED_COLLECTION => OperationalEntitlementCapabilityManager::TYPE_TIMED_COLLECTION,
      self::TYPE_PARKING_ADDON => OperationalEntitlementCapabilityManager::TYPE_PARKING_ACCESS,
      self::TYPE_OPERATIONAL_BUNDLE => OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS,
      default => OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP,
    };
  }

  /**
   * @param list<array<string, mixed>> $items
   *
   * @return list<array<string, mixed>>
   */
  private function deriveLinkedProductsFromItems(array $items): array {
    $out = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $type = (string) ($item['productisation_type'] ?? '');
      $commerce = is_array($item['commerce'] ?? NULL) ? $item['commerce'] : [];
      $pid = (int) ($commerce['product_id'] ?? 0);
      if ($pid < 1) {
        continue;
      }
      $role = match ($type) {
        self::TYPE_HOSPITALITY_PACKAGE => 'hospitality',
        self::TYPE_TIMED_COLLECTION => 'timed_collection',
        self::TYPE_PARKING_ADDON => 'parking',
        self::TYPE_OPERATIONAL_BUNDLE => 'operational_bundle',
        default => 'merch_pickup',
      };
      $bundle_group = match ($type) {
        self::TYPE_OPERATIONAL_BUNDLE => 'operational_bundle',
        self::TYPE_HOSPITALITY_PACKAGE => 'hospitality',
        default => '',
      };
      $out[] = [
        'product_id' => $pid,
        'role' => $role,
        'bundle_group' => $bundle_group,
      ];
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $a
   * @param list<array<string, mixed>> $b
   *
   * @return list<array<string, mixed>>
   */
  private function mergeLinkedProductLists(array $a, array $b): array {
    $seen = [];
    $out = [];
    foreach ([$a, $b] as $chunk) {
      foreach ($chunk as $row) {
        if (!is_array($row)) {
          continue;
        }
        $pid = (int) ($row['product_id'] ?? 0);
        $role = (string) ($row['role'] ?? '');
        if ($pid < 1) {
          continue;
        }
        $key = $pid . ':' . $role;
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        $out[] = [
          'product_id' => $pid,
          'role' => $role !== '' ? $role : 'merch_pickup',
          'bundle_group' => (string) ($row['bundle_group'] ?? ''),
        ];
      }
    }
    return $out;
  }

}
