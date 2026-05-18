<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Customer-safe labels for Event Extra Commerce order line items.
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
    'pickup_mode',
    'readiness_mode',
    'operational_product_type',
  ];

  public function __construct(
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalExtraVisualPresenter $visualPresenter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrencyFormatter $currencyFormatter,
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
    $visual = $this->visualPresenter->buildProductVisualDocument($product, $presentation);

    $event = $this->resolveEvent($product, $order_item);
    $event_id = $event instanceof NodeInterface ? (int) $event->id() : 0;
    $event_title = $event instanceof NodeInterface ? (string) $event->label() : '';

    $product_title = $this->customerProductTitle((string) $product->label());
    $size = $this->visualPresenter->resolveVariationSize($purchased);
    $size_label = $size['size_label'] !== ''
      ? $size['size_label']
      : $this->visualPresenter->meaningfulVariationLabel((string) $purchased->label(), $product_title);

    $short_description = trim((string) ($visual['short_description'] ?? ''));
    $pickup_note = trim((string) ($visual['pickup_note'] ?? ''));
    $description = $short_description !== '' ? $short_description : $pickup_note;
    if ($description === '') {
      $description = trim((string) ($presentation['operational_summary'] ?? ''));
    }
    if ($description === '') {
      $description = $this->descriptionFromPickupMode((string) ($presentation['pickup_mode'] ?? ''));
    }

    $thumbnail = $this->visualPresenter->buildPrimaryThumbnailForProduct($product);
    $thumb_url = is_array($thumbnail) ? (string) ($thumbnail['url'] ?? '') : '';
    $thumb_alt = is_array($thumbnail) ? (string) ($thumbnail['alt'] ?? $product_title) : $product_title;

    $price = $order_item->getTotalPrice() ?? $purchased->getPrice();
    $price_display = '';
    if ($price !== NULL) {
      $price_display = $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
    }

    $chips = $this->normalizeChips(is_array($presentation['operational_chips'] ?? NULL) ? $presentation['operational_chips'] : []);
    if ($pickup_note !== '') {
      array_unshift($chips, ['label' => $pickup_note, 'tone' => 'pickup']);
      $chips = $this->dedupeChips($chips);
    }

    $doc = [
      self::CONTRACT_FLAG => TRUE,
      'title' => $product_title,
      'subtitle' => $event_title !== ''
        ? (string) $this->t('For: @event', ['@event' => $event_title])
        : '',
      'description' => $description,
      'short_description' => $short_description,
      'pickup_note' => $pickup_note,
      'variation_label' => $size_label,
      'size_label' => $size_label,
      'thumbnail_url' => $thumb_url,
      'thumbnail_alt' => $thumb_alt,
      'price_display' => $price_display,
      'chips' => $chips,
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
      return (string) $this->t('Event extra');
    }
    $stripped = preg_replace('/\s*-\s*Node\s+\d+$/i', '', $raw);
    return is_string($stripped) && trim($stripped) !== '' ? trim($stripped) : $raw;
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
        'label' => (string) $this->t('Event extra'),
        'tone' => 'muted',
      ];
    }
    return $out;
  }

  /**
   * @param list<array{label: string, tone: string}> $chips
   *
   * @return list<array{label: string, tone: string}>
   */
  private function dedupeChips(array $chips): array {
    $out = [];
    $seen = [];
    foreach ($chips as $chip) {
      $label = $chip['label'] ?? '';
      if ($label === '' || isset($seen[$label])) {
        continue;
      }
      $seen[$label] = TRUE;
      $out[] = $chip;
    }
    return $out;
  }

  private function descriptionFromPickupMode(string $pickup_mode): string {
    return match ($pickup_mode) {
      'venue_pickup', 'counter', 'timed_window' => (string) $this->t('Collect at the venue after purchase.'),
      'none' => (string) $this->t('Follow organiser instructions for this extra.'),
      default => (string) $this->t('Collect at the venue after purchase.'),
    };
  }

}
