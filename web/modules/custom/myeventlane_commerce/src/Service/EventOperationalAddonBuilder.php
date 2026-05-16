<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Customer read model: operational Commerce products linked to an event.
 *
 * Commerce owns carts, pricing, and inventory. This service returns only
 * render-safe metadata for booking-page add-on selection.
 */
final class EventOperationalAddonBuilder {

  use StringTranslationTrait;

  /**
   * Keys that must never appear in customer-facing add-on payloads.
   *
   * @var list<string>
   */
  public const FORBIDDEN_CUSTOMER_ADDON_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_action',
    'scanner_secret',
    'scanner_tokens',
    'device_fingerprint',
    'payload_sha256',
    'inventory_quantity',
    'stock_count',
    'stock_level',
    'warehouse_ids',
    'shipment_provider',
    'shipment_state',
    'private_notes',
    'internal_cost',
    'vendor_margin',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds add-on rows for every eligible operational product on the event.
   *
   * @return array{addons: list<array<string, mixed>>, product_ids: list<int>}
   *   Render-safe add-on rows plus product IDs for cache tagging (internal).
   */
  public function buildForEvent(NodeInterface $event): array {
    $event_id = (int) $event->id();
    if ($event_id < 1) {
      return ['addons' => [], 'product_ids' => []];
    }

    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, 'IN')
      ->condition('field_event', $event_id)
      ->condition('status', 1)
      ->sort('title', 'ASC')
      ->execute();

    if (!$ids) {
      return ['addons' => [], 'product_ids' => []];
    }

    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $storage->loadMultiple($ids);
    $addons = [];
    $product_ids = [];

    foreach ($products as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }
      if (!in_array($product->bundle(), OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, TRUE)) {
        continue;
      }
      if (!$product->isPublished()) {
        continue;
      }
      if (!$product->hasField('field_event') || $product->get('field_event')->isEmpty()) {
        continue;
      }
      if ((int) $product->get('field_event')->target_id !== $event_id) {
        continue;
      }

      $variations = [];
      foreach ($product->getVariations() as $variation) {
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }
        if (!$variation->isPublished()) {
          continue;
        }
        $price = $variation->getPrice();
        $variations[] = [
          'variation_id' => (int) $variation->id(),
          'label' => (string) $variation->label(),
          'price_number' => $price ? (string) $price->getNumber() : '',
          'price_currency_code' => $price ? (string) $price->getCurrencyCode() : '',
        ];
      }

      if ($variations === []) {
        continue;
      }

      $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
      $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);

      $row = $this->sanitizeAddon([
        'product_id' => (int) $product->id(),
        'bundle' => $product->bundle(),
        'title' => (string) $product->label(),
        'variations' => $variations,
        'operational' => $presentation,
      ]);

      $addons[] = $row;
      $product_ids[] = (int) $product->id();
    }

    return [
      'addons' => $addons,
      'product_ids' => $product_ids,
    ];
  }

  public function hasAddons(NodeInterface $event): bool {
    return $this->buildForEvent($event)['addons'] !== [];
  }

  /**
   * @param array<string, mixed> $addon
   *
   * @return array<string, mixed>
   */
  public function sanitizeAddon(array $addon): array {
    return $this->stripForbiddenKeysRecursive($addon);
  }

  /**
   * @param array<int|string, mixed> $data
   *
   * @return array<int|string, mixed>
   */
  private function stripForbiddenKeysRecursive(array $data): array {
    if (array_is_list($data)) {
      $out = [];
      foreach ($data as $item) {
        if (is_array($item)) {
          $out[] = $this->stripForbiddenKeysRecursive($item);
        }
        elseif (is_scalar($item) || $item === NULL) {
          $out[] = $item;
        }
      }
      return $out;
    }
    $out = [];
    foreach ($data as $key => $value) {
      if (!is_string($key) || in_array($key, self::FORBIDDEN_CUSTOMER_ADDON_KEYS, TRUE)) {
        continue;
      }
      if (is_array($value)) {
        $out[$key] = $this->stripForbiddenKeysRecursive($value);
      }
      elseif (is_scalar($value) || $value === NULL) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

}
