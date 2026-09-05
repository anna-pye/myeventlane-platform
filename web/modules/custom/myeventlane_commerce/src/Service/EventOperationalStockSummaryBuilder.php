<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\Commerce\OperationalProductBundles;
use Drupal\node\NodeInterface;

/**
 * Builds a lightweight, read-only stock summary for an event's add-ons.
 *
 * Commerce Stock remains authoritative. Buyer-available quantities include
 * active checkout holds through OperationalVariationStockResolver. Admission
 * ticket capacity is deliberately outside this service.
 */
final class EventOperationalStockSummaryBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalVariationStockResolver $stockResolver,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * @return array<string, int|string>|null
   */
  public function buildForEvent(NodeInterface $event): ?array {
    if ((int) $event->id() < 1 || $event->bundle() !== 'event') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', OperationalProductBundles::BUNDLES, 'IN')
      ->condition('field_event', (int) $event->id())
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $products = array_filter(
      $storage->loadMultiple($ids),
      static fn (mixed $product): bool => $product instanceof ProductInterface,
    );
    return $this->buildForProducts($products);
  }

  /**
   * @param list<ProductInterface> $products
   *
   * @return array<string, int|string>|null
   */
  public function buildForProducts(array $products): ?array {
    if ($products === []) {
      return NULL;
    }

    $available = 0;
    $unlimited = 0;
    $low = 0;
    $soldOut = 0;
    foreach ($products as $product) {
      $summary = $this->stockResolver->summarizeProductStock($product);
      $state = (string) ($summary['stock_state'] ?? 'unknown');
      if ($state === 'unlimited') {
        $unlimited++;
      }
      elseif ($state !== 'unknown') {
        $available += max(0, (int) ($summary['total_stock'] ?? 0));
      }
      if (!empty($summary['low_stock'])) {
        $low++;
      }
      if (!empty($summary['sold_out'])) {
        $soldOut++;
      }
    }

    if ($soldOut > 0 || $low > 0) {
      $state = $soldOut > 0 ? 'sold_out' : 'low';
      $parts = [];
      if ($soldOut > 0) {
        $parts[] = (string) $this->formatPlural($soldOut, '1 sold out', '@count sold out');
      }
      if ($low > 0) {
        $parts[] = (string) $this->formatPlural($low, '1 low', '@count low');
      }
      $label = implode(' · ', $parts);
    }
    elseif ($unlimited === count($products)) {
      $state = 'unlimited';
      $label = (string) $this->t('Unlimited stock');
    }
    elseif ($unlimited > 0) {
      $state = 'healthy';
      $label = (string) $this->t('@available available · @unlimited unlimited', [
        '@available' => $available,
        '@unlimited' => $unlimited,
      ]);
    }
    else {
      $state = 'healthy';
      $label = (string) $this->t('@count available', ['@count' => $available]);
    }

    return [
      'state' => $state,
      'label' => $label,
      'product_count' => count($products),
      'available_quantity' => $available,
      'unlimited_count' => $unlimited,
      'low_stock_count' => $low,
      'sold_out_count' => $soldOut,
    ];
  }

}
