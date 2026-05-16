<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only operational merchandise normalization for Commerce products.
 *
 * Commerce owns catalog, pricing, and carts. This service projects
 * operational semantics from `field_mel_operational_product` and Event Studio
 * authoring fragments without stock, warehouse, shipping, scanner, or issuance
 * execution.
 */
final class OperationalMerchandiseManager {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'mel_operational_product';

  /**
   * Commerce product bundles treated as operational merchandise architecture.
   *
   * @var list<string>
   */
  public const OPERATIONAL_PRODUCT_BUNDLES = [
    'operational_merchandise',
    'operational_bundle',
    'hospitality_package',
    'timed_collection_product',
  ];

  /**
   * @var list<string>
   */
  public const FORBIDDEN_PRODUCT_KEYS = [
    'qr_payload',
    'replay_token',
    'inventory_quantity',
    'stock_level',
    'warehouse_ids',
    'shipment_state',
    'scanner_tokens',
    'scanner_secret',
    'device_fingerprint',
  ];

  /**
   * @var list<string>
   */
  private const PRODUCT_CONTRACT_KEYS = [
    'operational_product_type',
    'fulfillment_mode',
    'reservation_mode',
    'pickup_mode',
    'readiness_mode',
    'continuity_mode',
    'capability_reference',
    'customer_visibility',
    'collection_rules',
    'hospitality_rules',
    'operational_summary',
    'operational_chips',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
    private readonly LoggerInterface $logger,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Default authoring fragment stored under operational_capabilities JSON.
   *
   * @return array<string, mixed>
   */
  public function emptyEventMerchandiseAuthoring(): array {
    return [
      'schema_version' => 1,
      'linked_products' => [],
    ];
  }

  /**
   * @param array<string, mixed> $raw
   *
   * @return array<string, mixed>
   */
  public function normalizeEventMerchandiseAuthoring(array $raw, NodeInterface $event): array {
    $raw = $this->stripForbiddenRecursive($raw);
    $links = is_array($raw['linked_products'] ?? NULL) ? $raw['linked_products'] : [];
    $normalized_links = [];
    foreach ($links as $link) {
      if (!is_array($link)) {
        continue;
      }
      $pid = (int) ($link['product_id'] ?? 0);
      if ($pid < 1) {
        continue;
      }
      $product = $this->entityTypeManager->getStorage('commerce_product')->load($pid);
      if (!$product instanceof ProductInterface) {
        $this->logger->notice('Operational merchandise authoring skipped missing product @pid for event @eid.', [
          '@pid' => (string) $pid,
          '@eid' => (string) $event->id(),
        ]);
        continue;
      }
      if (!in_array($product->bundle(), self::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
        $this->logger->notice('Operational merchandise authoring rejected non-operational product @pid (bundle @bundle) for event @eid.', [
          '@pid' => (string) $pid,
          '@bundle' => $product->bundle(),
          '@eid' => (string) $event->id(),
        ]);
        continue;
      }
      if (!$this->productBelongsToEvent($product, $event)) {
        $this->logger->notice('Operational merchandise authoring rejected product @pid not scoped to event @eid.', [
          '@pid' => (string) $pid,
          '@eid' => (string) $event->id(),
        ]);
        continue;
      }
      $role = $this->normalizeToken((string) ($link['role'] ?? ''), 'merch_pickup', [
        'merch_pickup',
        'operational_bundle',
        'hospitality',
        'timed_collection',
        'parking',
      ]);
      $bundle_group = $this->sanitizeLabel((string) ($link['bundle_group'] ?? ''));
      $normalized_links[] = [
        'product_id' => $pid,
        'role' => $role,
        'bundle_group' => $bundle_group,
        'product_payload' => $this->normalizeProductFieldFromEntity($product),
      ];
    }
    return [
      'schema_version' => 1,
      'linked_products' => $normalized_links,
    ];
  }

  /**
   * @param array<string, mixed>|string|null $raw
   *
   * @return array<string, mixed>
   */
  public function normalizeProductFieldValue(array|string|null $raw): array {
    if ($raw === NULL || $raw === '') {
      return $this->emptyProductPayload();
    }
    if (is_string($raw)) {
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        $this->logger->warning('Operational product JSON invalid: @message', ['@message' => $e->getMessage()]);
        return $this->emptyProductPayload();
      }
      $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
      return $this->emptyProductPayload();
    }
    return $this->whitelistProductPayload($this->stripForbiddenRecursive($raw));
  }

  /**
   * @return array<string, mixed>
   */
  public function normalizeProductFieldFromEntity(ProductInterface $product): array {
    if (!$product->hasField('field_mel_operational_product') || $product->get('field_mel_operational_product')->isEmpty()) {
      return $this->defaultPayloadForBundle($product->bundle());
    }
    return $this->normalizeProductFieldValue($product->get('field_mel_operational_product')->value);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  public function buildCustomerSafeProductPresentation(array $payload): array {
    $payload = $this->normalizeProductFieldValue($payload);
    $chips = is_array($payload['operational_chips'] ?? NULL) ? $payload['operational_chips'] : [];
    $safe_chips = [];
    foreach ($chips as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = $this->sanitizeLabel((string) ($chip['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $safe_chips[] = [
        'label' => $label,
        'tone' => $this->normalizeToken((string) ($chip['tone'] ?? ''), 'muted', ['muted', 'info', 'success', 'warning']),
      ];
    }
    return [
      self::CONTRACT_FLAG => TRUE,
      'operational_product_type' => (string) ($payload['operational_product_type'] ?? ''),
      'operational_summary' => $this->sanitizeLabel((string) ($payload['operational_summary'] ?? ''), 600),
      'operational_chips' => $safe_chips,
      'customer_visibility' => $this->normalizeToken((string) ($payload['customer_visibility'] ?? ''), 'visible', ['visible', 'hidden']),
      'pickup_mode' => (string) ($payload['pickup_mode'] ?? ''),
      'readiness_mode' => (string) ($payload['readiness_mode'] ?? ''),
    ];
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
      if (!is_string($key) || in_array($key, self::FORBIDDEN_PRODUCT_KEYS, TRUE)) {
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
   * @return array<string, mixed>
   */
  private function emptyProductPayload(): array {
    return $this->whitelistProductPayload([
      'operational_product_type' => 'unknown',
      'fulfillment_mode' => 'none',
      'reservation_mode' => 'none',
      'pickup_mode' => 'none',
      'readiness_mode' => 'unknown',
      'continuity_mode' => 'unknown',
      'capability_reference' => '',
      'customer_visibility' => 'visible',
      'collection_rules' => [],
      'hospitality_rules' => [],
      'operational_summary' => '',
      'operational_chips' => [],
    ]);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  private function whitelistProductPayload(array $payload): array {
    $out = [];
    foreach (self::PRODUCT_CONTRACT_KEYS as $key) {
      if (!array_key_exists($key, $payload)) {
        continue;
      }
      $value = $payload[$key];
      if ($key === 'collection_rules' || $key === 'hospitality_rules') {
        $out[$key] = is_array($value) ? $this->stripForbiddenRecursive($value) : [];
      }
      elseif ($key === 'operational_chips') {
        $out[$key] = $this->normalizeChipList($value);
      }
      elseif (is_scalar($value) || $value === NULL) {
        $out[$key] = $value;
      }
    }
    $out[self::CONTRACT_FLAG] = TRUE;
    return $out;
  }

  /**
   * @param mixed $value
   *
   * @return list<array<string, string>>
   */
  private function normalizeChipList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = $this->sanitizeLabel((string) ($chip['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $out[] = [
        'label' => $label,
        'tone' => $this->normalizeToken((string) ($chip['tone'] ?? ''), 'muted', ['muted', 'info', 'success', 'warning']),
      ];
    }
    return $out;
  }

  private function defaultPayloadForBundle(string $bundle): array {
    $type = match ($bundle) {
      'operational_merchandise' => 'merch_pickup',
      'operational_bundle' => 'operational_bundle',
      'hospitality_package' => 'hospitality_package',
      'timed_collection_product' => 'timed_collection_product',
      default => 'unknown',
    };
    $summary = match ($bundle) {
      'operational_merchandise' => (string) $this->t('Merchandise pickup messaging is attached to this product.'),
      'operational_bundle' => (string) $this->t('Operational bundle groups multiple fulfilment-friendly items.'),
      'hospitality_package' => (string) $this->t('Hospitality access is described at the product level for guest expectations.'),
      'timed_collection_product' => (string) $this->t('Timed collection windows are communicated from this product.'),
      default => (string) $this->t('Operational commerce product.'),
    };
    $pickup = match ($bundle) {
      'timed_collection_product' => 'timed_window',
      'operational_merchandise' => 'counter',
      default => 'none',
    };
    return $this->whitelistProductPayload([
      'operational_product_type' => $type,
      'fulfillment_mode' => 'collect',
      'reservation_mode' => 'operational_projection',
      'pickup_mode' => $pickup,
      'readiness_mode' => 'authoring',
      'continuity_mode' => 'collect_after_entry',
      'capability_reference' => $bundle,
      'customer_visibility' => 'visible',
      'collection_rules' => [],
      'hospitality_rules' => [],
      'operational_summary' => $summary,
      'operational_chips' => [
        ['label' => (string) $this->t('Collect after venue check-in when required'), 'tone' => 'info'],
      ],
    ]);
  }

  private function productBelongsToEvent(ProductInterface $product, NodeInterface $event): bool {
    if (!$product->hasField('field_event') || $product->get('field_event')->isEmpty()) {
      return FALSE;
    }
    return (int) $product->get('field_event')->target_id === (int) $event->id();
  }

  private function normalizeToken(string $value, string $fallback, array $allowed): string {
    $value = strtolower(trim($value));
    return in_array($value, $allowed, TRUE) ? $value : $fallback;
  }

  private function sanitizeLabel(string $label, int $max = 128): string {
    $label = trim($label);
    if (mb_strlen($label, 'UTF-8') > $max) {
      return mb_substr($label, 0, $max - 1, 'UTF-8') . '…';
    }
    return $label;
  }

}
