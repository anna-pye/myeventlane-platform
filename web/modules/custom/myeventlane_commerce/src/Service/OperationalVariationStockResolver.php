<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Reads and validates operational extra stock on Commerce product variations.
 *
 * Blank stock quantity = unlimited. Zero stock quantity = sold out.
 * Does not decrement stock, reserve inventory, or mutate carts beyond validation.
 */
final class OperationalVariationStockResolver {

  use StringTranslationTrait;

  public const FIELD_STOCK_QUANTITY = 'field_mel_stock_quantity';

  public const FIELD_LIMIT_PER_ORDER = 'field_mel_limit_per_order';

  public const FIELD_SHOW_REMAINING = 'field_mel_show_remaining';

  /**
   * @var list<string>
   */
  public const OPERATIONAL_VARIATION_BUNDLES = [
    'operational_merchandise_var',
    'operational_bundle_var',
    'hospitality_package_var',
    'timed_collection_var',
  ];

  public function __construct(
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Whether a variation bundle supports MEL operational stock fields.
   */
  public function bundleSupportsStock(string $variation_bundle): bool {
    return in_array($variation_bundle, self::OPERATIONAL_VARIATION_BUNDLES, TRUE);
  }

  /**
   * Reads stock quantity: NULL = unlimited, 0 = sold out, positive = finite.
   */
  public function readStockQuantity(ProductVariationInterface $variation): ?int {
    if (!$variation->hasField(self::FIELD_STOCK_QUANTITY) || $variation->get(self::FIELD_STOCK_QUANTITY)->isEmpty()) {
      return NULL;
    }
    return max(0, (int) $variation->get(self::FIELD_STOCK_QUANTITY)->value);
  }

  /**
   * Reads per-order limit; NULL means no limit.
   */
  public function readLimitPerOrder(ProductVariationInterface $variation): ?int {
    if (!$variation->hasField(self::FIELD_LIMIT_PER_ORDER) || $variation->get(self::FIELD_LIMIT_PER_ORDER)->isEmpty()) {
      return NULL;
    }
    $limit = (int) $variation->get(self::FIELD_LIMIT_PER_ORDER)->value;
    return $limit >= 1 ? $limit : NULL;
  }

  public function readShowRemaining(ProductVariationInterface $variation): bool {
    if (!$variation->hasField(self::FIELD_SHOW_REMAINING) || $variation->get(self::FIELD_SHOW_REMAINING)->isEmpty()) {
      return FALSE;
    }
    return (bool) $variation->get(self::FIELD_SHOW_REMAINING)->value;
  }

  public function isSoldOut(ProductVariationInterface $variation): bool {
    $stock = $this->readStockQuantity($variation);
    return $stock !== NULL && $stock === 0;
  }

  public function isUnlimited(ProductVariationInterface $variation): bool {
    return $this->readStockQuantity($variation) === NULL;
  }

  /**
   * Normalizes vendor input: blank = unlimited (NULL), 0 = sold out.
   */
  public function normalizeStockInput(mixed $raw): ?int {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (!is_scalar($raw)) {
      return NULL;
    }
    $text = trim((string) $raw);
    if ($text === '') {
      return NULL;
    }
    if (!is_numeric($text)) {
      return NULL;
    }
    return max(0, (int) $text);
  }

  /**
   * Normalizes limit input: blank = no limit (NULL).
   */
  public function normalizeLimitInput(mixed $raw): ?int {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (!is_scalar($raw)) {
      return NULL;
    }
    $text = trim((string) $raw);
    if ($text === '' || !is_numeric($text)) {
      return NULL;
    }
    $limit = (int) $text;
    return $limit >= 1 ? $limit : NULL;
  }

  public function normalizeShowRemaining(mixed $raw): bool {
    return !empty($raw);
  }

  /**
   * @param array<string, mixed> $stock_input
   *   Keys: stock_quantity, limit_per_order, show_remaining.
   *
   * @return list<string>
   */
  public function validateStockInput(array $stock_input): array {
    $errors = [];
    $stock = $this->normalizeStockInput($stock_input['stock_quantity'] ?? NULL);
    $limit = $this->normalizeLimitInput($stock_input['limit_per_order'] ?? NULL);
    if ($stock !== NULL && $limit !== NULL && $stock > 0 && $limit > $stock) {
      $errors[] = (string) $this->t('Limit per order cannot exceed stock quantity.');
    }
    return $errors;
  }

  /**
   * Applies normalized stock fields to a variation when configured.
   *
   * @param array<string, mixed> $stock_input
   */
  public function applyStockFields(ProductVariationInterface $variation, array $stock_input): void {
    if (!$this->bundleSupportsStock($variation->bundle())) {
      return;
    }
    $stock = $this->normalizeStockInput($stock_input['stock_quantity'] ?? NULL);
    $limit = $this->normalizeLimitInput($stock_input['limit_per_order'] ?? NULL);
    $show = $this->normalizeShowRemaining($stock_input['show_remaining'] ?? FALSE);

    if ($variation->hasField(self::FIELD_STOCK_QUANTITY)) {
      if ($stock === NULL) {
        $variation->set(self::FIELD_STOCK_QUANTITY, NULL);
      }
      else {
        $variation->set(self::FIELD_STOCK_QUANTITY, $stock);
      }
    }
    if ($variation->hasField(self::FIELD_LIMIT_PER_ORDER)) {
      if ($limit === NULL) {
        $variation->set(self::FIELD_LIMIT_PER_ORDER, NULL);
      }
      else {
        $variation->set(self::FIELD_LIMIT_PER_ORDER, $limit);
      }
    }
    if ($variation->hasField(self::FIELD_SHOW_REMAINING)) {
      $variation->set(self::FIELD_SHOW_REMAINING, $show ? 1 : 0);
    }
  }

  /**
   * @return array<string, mixed>
   *   Keys: stock_quantity, limit_per_order, show_remaining.
   */
  public function extractStockFields(ProductVariationInterface $variation): array {
    return [
      'stock_quantity' => $this->readStockQuantity($variation),
      'limit_per_order' => $this->readLimitPerOrder($variation),
      'show_remaining' => $this->readShowRemaining($variation),
    ];
  }

  /**
   * Customer-safe stock metadata for a variation row.
   *
   * @return array<string, mixed>
   */
  public function buildVariationCustomerStock(ProductVariationInterface $variation): array {
    $stock = $this->readStockQuantity($variation);
    $limit = $this->readLimitPerOrder($variation);
    $show_remaining = $this->readShowRemaining($variation);
    $sold_out = $stock !== NULL && $stock === 0;
    $unlimited = $stock === NULL;

    return [
      'stock_quantity' => $stock,
      'limit_per_order' => $limit,
      'show_remaining' => $show_remaining,
      'sold_out' => $sold_out,
      'unlimited' => $unlimited,
      'remaining_label' => $this->buildRemainingLabel($stock, $show_remaining),
      'limit_label' => $this->buildLimitLabel($limit),
      'max_quantity' => $this->maxPurchasableQuantity($variation, NULL),
    ];
  }

  /**
   * Summarizes stock across published variations for Studio cards.
   *
   * @return array<string, mixed>
   */
  public function summarizeProductStock(ProductInterface $product): array {
    $published = [];
    $has_unlimited = FALSE;
    $total_finite = 0;
    $all_sold_out = TRUE;
    $limits = [];

    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        continue;
      }
      $published[] = $variation;
      $stock = $this->readStockQuantity($variation);
      if ($stock === NULL) {
        $has_unlimited = TRUE;
        $all_sold_out = FALSE;
      }
      elseif ($stock > 0) {
        $total_finite += $stock;
        $all_sold_out = FALSE;
      }
      $limit = $this->readLimitPerOrder($variation);
      if ($limit !== NULL) {
        $limits[$limit] = $limit;
      }
    }

    if ($published === []) {
      return [
        'stock_state' => 'unknown',
        'stock_label' => '',
        'total_stock' => NULL,
        'limit_per_order' => NULL,
        'limit_label' => '',
        'sold_out' => FALSE,
        'low_stock' => FALSE,
        'unlimited' => TRUE,
      ];
    }

    if ($all_sold_out) {
      $state = 'sold_out';
      $label = (string) $this->t('Sold out');
    }
    elseif ($has_unlimited) {
      $state = 'unlimited';
      $label = (string) $this->t('Unlimited');
    }
    elseif ($total_finite <= 5) {
      $state = 'low';
      $label = (string) $this->t('@count in stock', ['@count' => $total_finite]);
    }
    else {
      $state = 'finite';
      $label = (string) $this->t('@count in stock', ['@count' => $total_finite]);
    }

    $limit = count($limits) === 1 ? (int) reset($limits) : NULL;

    return [
      'stock_state' => $state,
      'stock_label' => $label,
      'total_stock' => $has_unlimited ? NULL : $total_finite,
      'limit_per_order' => $limit,
      'limit_label' => $this->buildLimitLabel($limit),
      'sold_out' => $state === 'sold_out',
      'low_stock' => $state === 'low',
      'unlimited' => $state === 'unlimited',
    ];
  }

  /**
   * Counts quantity already in cart for a variation.
   */
  public function countCartQuantityForVariation(OrderInterface $cart, int $variation_id): int {
    $total = 0;
    foreach ($cart->getItems() as $order_item) {
      $purchased = $order_item->getPurchasedEntity();
      if (!$purchased instanceof ProductVariationInterface) {
        continue;
      }
      if ((int) $purchased->id() === $variation_id) {
        $total += (int) round((float) $order_item->getQuantity());
      }
    }
    return $total;
  }

  /**
   * Maximum quantity a customer may add in one request (NULL = unlimited).
   */
  public function maxPurchasableQuantity(ProductVariationInterface $variation, ?OrderInterface $cart): ?int {
    if ($this->isSoldOut($variation)) {
      return 0;
    }

    $max = NULL;
    $stock = $this->readStockQuantity($variation);
    if ($stock !== NULL) {
      $max = $stock;
    }

    $limit = $this->readLimitPerOrder($variation);
    if ($limit !== NULL) {
      $max = $max === NULL ? $limit : min($max, $limit);
    }

    if ($cart instanceof OrderInterface) {
      $in_cart = $this->countCartQuantityForVariation($cart, (int) $variation->id());
      if ($max === NULL) {
        return NULL;
      }
      return max(0, $max - $in_cart);
    }

    return $max;
  }

  /**
   * Validates add-to-cart quantity against stock and per-order limits.
   *
   * @return list<string>
   */
  public function validateAddToCartQuantity(
    ProductVariationInterface $variation,
    int $requested_qty,
    ?OrderInterface $cart,
  ): array {
    if ($requested_qty < 1) {
      return [(string) $this->t('Choose a quantity of at least 1.')];
    }

    if ($this->isSoldOut($variation)) {
      return [(string) $this->t('This option is sold out.')];
    }

    $stock = $this->readStockQuantity($variation);
    $in_cart = $cart instanceof OrderInterface
      ? $this->countCartQuantityForVariation($cart, (int) $variation->id())
      : 0;
    $total_requested = $in_cart + $requested_qty;

    if ($stock !== NULL && $total_requested > $stock) {
      if ($in_cart > 0) {
        return [(string) $this->t('You already have @count in your cart. Only @remaining more can be added.', [
          '@count' => $in_cart,
          '@remaining' => max(0, $stock - $in_cart),
        ])];
      }
      return [(string) $this->t('Only @count available for this option.', ['@count' => $stock])];
    }

    $limit = $this->readLimitPerOrder($variation);
    if ($limit !== NULL && $total_requested > $limit) {
      if ($in_cart > 0) {
        return [(string) $this->t('Limit @limit per order. You already have @count in your cart.', [
          '@limit' => $limit,
          '@count' => $in_cart,
        ])];
      }
      return [(string) $this->t('Limit @limit per order.', ['@limit' => $limit])];
    }

    return [];
  }

  private function buildRemainingLabel(?int $stock, bool $show_remaining): string {
    if (!$show_remaining || $stock === NULL || $stock === 0) {
      return '';
    }
    return (string) $this->t('@count available', ['@count' => $stock]);
  }

  private function buildLimitLabel(?int $limit): string {
    if ($limit === NULL) {
      return '';
    }
    return (string) $this->t('Limit @limit per order', ['@limit' => $limit]);
  }

}
