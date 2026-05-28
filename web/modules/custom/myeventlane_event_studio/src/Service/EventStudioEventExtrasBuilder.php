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
use Drupal\myeventlane_core\Commerce\OperationalProductBundles;
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
    private readonly VendorOperationalProductCreationManager $productCreationManager,
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
   * @return list<array<string, mixed>>
   */
  public function loadExtrasForEventByClassification(NodeInterface $event, string $filter): array {
    $cards = $this->loadExtrasForEvent($event);
    if ($filter === 'all' || $filter === '') {
      return $cards;
    }
    return array_values(array_filter($cards, static function (array $card) use ($filter): bool {
      $classification = (string) ($card['classification'] ?? '');
      return $filter === $classification;
    }));
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
    $classification = $this->classificationForProduct($product);
    $variant_count = $this->countPublishedVariations($product);
    $book_url = $this->buildBookPageUrl($event);
    $editor_defaults = $this->productCreationManager->extractEditorDefaultsFromProduct($product);
    $product_status = (string) ($editor_defaults['product_status'] ?? 'hidden');
    $status_options = $this->productCreationManager->productStatusOptions();

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
      'classification' => $classification,
      'classification_label' => $this->classificationLabel($classification),
      'variant_count' => $variant_count,
      'variant_count_label' => $variant_count === 1
        ? (string) $this->t('1 option')
        : (string) $this->t('@count options', ['@count' => $variant_count]),
      'product_status' => $product_status,
      'product_status_label' => $status_options[$product_status] ?? $product_status,
      'capacity_note' => (string) ($editor_defaults['capacity_note'] ?? ''),
      'booking_visibility_label' => $product->isPublished()
        ? (string) $this->t('Visible on booking page')
        : (string) $this->t('Not on booking page'),
      'status_label' => $product->isPublished()
        ? (string) $this->t('Active on booking')
        : (string) $this->t('Hidden from booking'),
      'image_count' => $this->countProductImages($product),
      'book_page_url' => $book_url,
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
  public function merchandiseTypeChoices(): array {
    return [
      'merchandise' => (string) $this->t('Merchandise (T-shirt, hoodie, poster, digital item)'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public function addonTypeChoices(): array {
    return [
      'parking' => (string) $this->t('Parking'),
      'meal_package' => (string) $this->t('Meal package'),
      'camping' => (string) $this->t('Camping'),
      'vip_extra' => (string) $this->t('VIP extra'),
      'shuttle' => (string) $this->t('Shuttle / transport'),
      'other' => (string) $this->t('Other add-on'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public function extraTypeChoices(): array {
    return $this->merchandiseTypeChoices() + $this->addonTypeChoices();
  }

  /**
   * @return array<string, string>
   */
  public function listFilterOptions(): array {
    return [
      'all' => (string) $this->t('All'),
      'merchandise' => (string) $this->t('Merchandise'),
      'addon' => (string) $this->t('Add-ons'),
    ];
  }

  public function classificationForProduct(ProductInterface $product): string {
    $mapped = OperationalProductBundles::classificationForProductBundle($product->bundle());
    return $mapped !== '' ? $mapped : 'addon';
  }

  private function classificationLabel(string $classification): string {
    return match ($classification) {
      'merchandise' => (string) $this->t('Merchandise'),
      default => (string) $this->t('Add-on'),
    };
  }

  private function countPublishedVariations(ProductInterface $product): int {
    $count = 0;
    foreach ($product->getVariations() as $variation) {
      if ($variation instanceof ProductVariationInterface && $variation->isPublished()) {
        $count++;
      }
    }
    return $count;
  }

  private function buildBookPageUrl(NodeInterface $event): string {
    if ($event->isPublished()) {
      return Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()])->toString();
    }
    return Url::fromRoute('entity.node.canonical', ['node' => $event->id()])->toString();
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

  private function countProductImages(ProductInterface $product): int {
    if (!$product->hasField('field_mel_extra_images') || $product->get('field_mel_extra_images')->isEmpty()) {
      return 0;
    }
    return $product->get('field_mel_extra_images')->count();
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
