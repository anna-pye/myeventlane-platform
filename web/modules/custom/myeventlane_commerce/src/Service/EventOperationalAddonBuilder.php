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
    'operational_product_type',
    'fulfillment_mode',
    'reservation_mode',
    'readiness_mode',
    'continuity_mode',
    'capability_reference',
    'customer_visibility',
    'collection_rules',
    'hospitality_rules',
    'pickup_mode',
    'readiness_mode',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalExtraVisualPresenter $visualPresenter,
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
      if ($product->getStores() === []) {
        continue;
      }
      if (!$product->hasField('field_event') || $product->get('field_event')->isEmpty()) {
        continue;
      }
      if ((int) $product->get('field_event')->target_id !== $event_id) {
        continue;
      }

      $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
      $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
      $visual = $this->visualPresenter->buildProductVisualDocument($product, $presentation);

      $variations = [];
      $size_options = [];
      $product_title = (string) $product->label();

      foreach ($product->getVariations() as $variation) {
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }
        if (!$variation->isPublished()) {
          continue;
        }
        $price = $variation->getPrice();
        $size = $this->visualPresenter->resolveVariationSize($variation);
        $meaningful = $this->visualPresenter->meaningfulVariationLabel((string) $variation->label(), $product_title);
        $display_label = $size['size_label'] !== '' ? $size['size_label'] : $meaningful;

        $row = [
          'variation_id' => (int) $variation->id(),
          'label' => $display_label,
          'price_number' => $price ? (string) $price->getNumber() : '',
          'price_currency_code' => $price ? (string) $price->getCurrencyCode() : '',
          'size_key' => $size['size_key'],
          'size_label' => $size['size_label'],
        ];
        $variations[] = $row;

        if ($size['size_key'] !== '') {
          $size_options[] = [
            'variation_id' => (int) $variation->id(),
            'size_key' => $size['size_key'],
            'size_label' => $size['size_label'],
            'price_number' => $row['price_number'],
            'price_currency_code' => $row['price_currency_code'],
          ];
        }
      }

      if ($variations === []) {
        continue;
      }

      if ($size_options === [] && count($variations) > 1) {
        foreach ($variations as $v) {
          $label = trim((string) ($v['label'] ?? ''));
          if ($label === '') {
            continue;
          }
          $size_options[] = [
            'variation_id' => (int) $v['variation_id'],
            'size_key' => 'v' . (string) $v['variation_id'],
            'size_label' => $label,
            'price_number' => (string) ($v['price_number'] ?? ''),
            'price_currency_code' => (string) ($v['price_currency_code'] ?? ''),
          ];
        }
      }

      $requires_size = count($size_options) > 1;
      $default_variation_id = count($variations) === 1 ? (int) $variations[0]['variation_id'] : NULL;

      $chips = $this->buildCustomerChips($visual, $presentation);

      $addon_row = [
        'product_id' => (int) $product->id(),
        'bundle' => $product->bundle(),
        'title' => $product_title,
        'variations' => $variations,
        'primary_image' => $visual['primary_image'],
        'gallery_images' => $visual['gallery_images'],
        'image_alt' => $visual['image_alt'],
        'has_images' => (bool) $visual['has_images'],
        'short_description' => $visual['short_description'],
        'pickup_note' => $visual['pickup_note'],
        'size_options' => $size_options,
        'requires_size_selection' => $requires_size,
        'default_variation_id' => $default_variation_id,
        'chips' => $chips,
        'operational' => [
          'operational_summary' => $visual['short_description'],
          'operational_chips' => $chips,
        ],
      ];

      $addons[] = $this->sanitizeAddon($addon_row);
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
   * @param array<string, mixed> $visual
   * @param array<string, mixed> $presentation
   *
   * @return list<array{label: string, tone: string}>
   */
  private function buildCustomerChips(array $visual, array $presentation): array {
    $chips = [];
    $pickup = trim((string) ($visual['pickup_note'] ?? ''));
    if ($pickup !== '') {
      $chips[] = ['label' => $pickup, 'tone' => 'pickup'];
    }

    $from_json = is_array($presentation['operational_chips'] ?? NULL) ? $presentation['operational_chips'] : [];
    foreach ($from_json as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = trim((string) ($chip['label'] ?? ''));
      if ($label === '') {
        continue;
      }
      $tone = strtolower(trim((string) ($chip['tone'] ?? 'muted')));
      if (in_array($tone, ['pickup', 'info'], TRUE) && $pickup !== '' && strcasecmp($label, $pickup) === 0) {
        continue;
      }
      if (!preg_match('/^[a-z0-9_-]{1,32}$/', $tone)) {
        $tone = 'muted';
      }
      $chips[] = ['label' => $label, 'tone' => $tone];
    }

    if ($chips === []) {
      $chips[] = [
        'label' => (string) $this->t('Collect at the event'),
        'tone' => 'pickup',
      ];
    }

    return $chips;
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
