<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\EventOperationalAddonBuilder;
use Drupal\myeventlane_commerce\Service\OperationalExtraVisualPresenter;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\node\NodeInterface;

/**
 * Presentation builder for the unified Event Extras Studio editor.
 */
final class EventStudioEventExtrasBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalMerchandiseManager $operationalMerchandiseManager,
    private readonly OperationalExtraVisualPresenter $visualPresenter,
    private readonly EventOperationalAddonBuilder $eventOperationalAddonBuilder,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function loadExtrasForEvent(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', OperationalMerchandiseManager::OPERATIONAL_PRODUCT_BUNDLES, 'IN')
      ->condition('field_event', (int) $event->id())
      ->sort('title', 'ASC')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $cards = [];
    foreach ($storage->loadMultiple($ids) as $product) {
      if ($product instanceof ProductInterface) {
        $cards[] = $this->buildExtraCard($product, $event);
      }
    }
    return $cards;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildExtraCard(ProductInterface $product, NodeInterface $event): array {
    $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
    $visual = $this->visualPresenter->buildProductVisualDocument($product, $presentation);
    $sizes = $this->summarizeSizes($product);
    $price_label = $this->formatPriceRange($product);

    return [
      'product_id' => (int) $product->id(),
      'title' => $product->label(),
      'short_description' => (string) ($visual['short_description'] ?? ''),
      'pickup_note' => (string) ($visual['pickup_note'] ?? ''),
      'primary_image' => $visual['primary_image'] ?? [],
      'sizes_summary' => $sizes,
      'price_label' => $price_label,
      'show_on_booking' => $product->isPublished(),
      'bundle' => $product->bundle(),
      'edit_url' => Url::fromRoute('myeventlane_event_studio.workspace_extras', [
        'node' => $event->id(),
      ], [
        'query' => ['extra' => (int) $product->id()],
      ])->toString(),
      'preview' => $this->buildPreviewRow($product, $event),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function buildPreviewRow(ProductInterface $product, NodeInterface $event): array {
    $built = $this->eventOperationalAddonBuilder->buildForEvent($event);
    foreach ($built['addons'] as $addon) {
      if ((int) ($addon['product_id'] ?? 0) === (int) $product->id()) {
        return $addon;
      }
    }
    $payload = $this->operationalMerchandiseManager->normalizeProductFieldFromEntity($product);
    $presentation = $this->operationalMerchandiseManager->buildCustomerSafeProductPresentation($payload);
    $visual = $this->visualPresenter->buildProductVisualDocument($product, $presentation);
    return [
      'product_id' => (int) $product->id(),
      'title' => $product->label(),
      'short_description' => (string) ($visual['short_description'] ?? ''),
      'pickup_note' => (string) ($visual['pickup_note'] ?? ''),
      'primary_image' => $visual['primary_image'] ?? [],
      'variations' => [],
    ];
  }

  /**
   * @return array<string, string>
   */
  public function extraTypeChoices(): array {
    return [
      'merchandise' => (string) $this->t('Merchandise'),
      'food_drink' => (string) $this->t('Food & drink'),
      'vip_hospitality' => (string) $this->t('VIP / hospitality'),
      'pickup_item' => (string) $this->t('Pickup item'),
      'bundle' => (string) $this->t('Bundle / package'),
    ];
  }

  /**
   * @return list<string>
   */
  private function summarizeSizes(ProductInterface $product): array {
    $out = [];
    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        continue;
      }
      if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty()) {
        $key = strtolower((string) $variation->get('field_mel_size')->value);
        $out[] = OperationalExtraVisualPresenter::SIZE_LABELS[$key] ?? strtoupper($key);
      }
    }
    return array_values(array_unique($out));
  }

  private function formatPriceRange(ProductInterface $product): string {
    $amounts = [];
    foreach ($product->getVariations() as $variation) {
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        continue;
      }
      $price = $variation->getPrice();
      if ($price !== NULL) {
        $amounts[] = (float) $price->getNumber();
      }
    }
    if ($amounts === []) {
      return '';
    }
    $min = min($amounts);
    $max = max($amounts);
    $currency = '';
    foreach ($product->getVariations() as $variation) {
      if ($variation instanceof ProductVariationInterface && $variation->getPrice() !== NULL) {
        $currency = $variation->getPrice()->getCurrencyCode();
        break;
      }
    }
    $symbol = $currency !== '' ? $currency . ' ' : '';
    if ($min === $max) {
      return $symbol . number_format($min, 2);
    }
    return $symbol . number_format($min, 2) . ' – ' . number_format($max, 2);
  }

}
