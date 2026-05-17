<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Customer-safe labels for operational add-on Commerce order line items.
 *
 * Read-only projection for cart, checkout, orders, and My Tickets surfaces.
 */
final class OperationalOrderItemDisplayBuilder {

  use StringTranslationTrait;

  public const CONTRACT_FLAG = 'is_operational_addon';

  /**
   * @var list<string>
   */
  public const FORBIDDEN_DISPLAY_KEYS = [
    'qr_payload',
    'replay_token',
    'scanner_action',
    'scanner_secret',
    'device_fingerprint',
    'inventory_quantity',
    'stock_count',
    'warehouse_ids',
    'shipment_provider',
    'shipment_state',
    'fingerprint',
    'operational_fingerprint',
  ];

  /**
   * Variation titles that must not be used as the primary customer label.
   *
   * @var list<string>
   */
  private const GENERIC_VARIATION_LABELS = [
    'price',
    'unit price',
    'default',
    'variation',
    'product',
  ];

  public function __construct(
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>|null
   *   Sanitized display document, or NULL when the line is not operational.
   */
  public function buildForOrderItem(OrderItemInterface $order_item): ?array {
    $purchased = $order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return NULL;
    }
    $product = $purchased->getProduct();
    if (!$product instanceof ProductInterface) {
      return NULL;
    }
    if (!OperationalMerchandiseManager::isOperationalProductBundle($product->bundle())) {
      return NULL;
    }

    $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);

    $event = $this->resolveEvent($product, $order_item);
    $event_id = $event instanceof NodeInterface ? (int) $event->id() : 0;
    $event_title = $event instanceof NodeInterface ? (string) $event->label() : '';

    $product_title = $this->customerProductTitle((string) $product->label());
    $variation_label = $this->meaningfulVariationLabel((string) $purchased->label(), $product_title);

    $description = trim((string) ($presentation['operational_summary'] ?? ''));
    if ($description === '') {
      $description = $this->descriptionFromPickupMode((string) ($presentation['pickup_mode'] ?? ''));
    }

    $chips = $this->normalizeChips(is_array($presentation['operational_chips'] ?? NULL) ? $presentation['operational_chips'] : []);

    $doc = [
      self::CONTRACT_FLAG => TRUE,
      'title' => $product_title,
      'subtitle' => $event_title !== ''
        ? (string) $this->t('For: @event', ['@event' => $event_title])
        : '',
      'description' => $description,
      'variation_label' => $variation_label,
      'chips' => $chips,
      'pickup_mode' => (string) ($presentation['pickup_mode'] ?? ''),
      'event_id' => $event_id,
      'event_title' => $event_title,
    ];

    return $this->stripForbiddenRecursive($doc);
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
      if (!is_string($key) || in_array($key, self::FORBIDDEN_DISPLAY_KEYS, TRUE)) {
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

  private function resolveEvent(ProductInterface $product, OrderItemInterface $order_item): ?NodeInterface {
    if ($product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
      $event = $product->get('field_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
      $event_id = (int) $order_item->get('field_target_event')->target_id;
      if ($event_id > 0) {
        $loaded = $this->entityTypeManager->getStorage('node')->load($event_id);
        if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
          return $loaded;
        }
      }
    }
    return NULL;
  }

  private function customerProductTitle(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
      return (string) $this->t('Add-on');
    }
    $stripped = preg_replace('/\s*-\s*Node\s+\d+$/i', '', $raw);
    return is_string($stripped) && trim($stripped) !== '' ? trim($stripped) : $raw;
  }

  private function meaningfulVariationLabel(string $variation_label, string $product_title): string {
    $variation_label = trim($variation_label);
    if ($variation_label === '') {
      return '';
    }
    $lower = strtolower($variation_label);
    if (in_array($lower, self::GENERIC_VARIATION_LABELS, TRUE)) {
      return '';
    }
    if (strcasecmp($variation_label, $product_title) === 0) {
      return '';
    }
    if (str_starts_with(strtolower($product_title), strtolower($variation_label))) {
      return '';
    }
    return $variation_label;
  }

  /**
   * @param list<array<string, string>> $chips
   *
   * @return list<array{label: string, tone: string}>
   */
  private function normalizeChips(array $chips): array {
    $out = [];
    $seen = [];
    foreach ($chips as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = trim((string) ($chip['label'] ?? ''));
      if ($label === '' || isset($seen[$label])) {
        continue;
      }
      $seen[$label] = TRUE;
      $tone = strtolower(trim((string) ($chip['tone'] ?? 'muted')));
      if (!preg_match('/^[a-z0-9_-]{1,32}$/', $tone)) {
        $tone = 'muted';
      }
      $out[] = [
        'label' => $label,
        'tone' => $tone,
      ];
    }
    if ($out === []) {
      $out[] = [
        'label' => (string) $this->t('Add-on'),
        'tone' => 'muted',
      ];
    }
    return $out;
  }

  private function descriptionFromPickupMode(string $pickup_mode): string {
    return match ($pickup_mode) {
      'venue_pickup', 'counter', 'timed_window' => (string) $this->t('Collect at the venue after purchase.'),
      'none' => (string) $this->t('Follow organiser instructions for this add-on.'),
      default => (string) $this->t('Collect at the venue after purchase.'),
    };
  }

}
