<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;

/**
 * Field-first visual metadata for Event Extras (customer-safe, no stock/shipping).
 */
class OperationalExtraVisualPresenter {

  use StringTranslationTrait;

  public const IMAGE_STYLE = 'mel_extra_square';

  /**
   * @var array<string, string>
   */
  public const SIZE_LABELS = [
    'xs' => 'XS',
    's' => 'S',
    'm' => 'M',
    'l' => 'L',
    'xl' => 'XL',
    '2xl' => '2XL',
    '3xl' => '3XL',
  ];

  /**
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ExtensionPathResolver $extensionPathResolver,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildProductVisualDocument(ProductInterface $product, array $presentation = []): array {
    $images = $this->buildGalleryFromProduct($product);
    $primary = $images[0] ?? $this->buildPlaceholderImage();
    $short = $this->resolveShortDescription($product, $presentation);
    $pickup = $this->resolvePickupNote($product, $presentation);

    return [
      'primary_image' => $primary,
      'gallery_images' => $images !== [] ? $images : [$primary],
      'image_alt' => (string) ($primary['alt'] ?? $product->label()),
      'has_images' => $images !== [],
      'short_description' => $short,
      'pickup_note' => $pickup,
      'placeholder_url' => $this->placeholderUrl(),
    ];
  }

  /**
   * @param array<string, mixed> $presentation
   */
  public function resolveShortDescription(ProductInterface $product, array $presentation): string {
    if ($product->hasField('field_mel_extra_short_desc') && !$product->get('field_mel_extra_short_desc')->isEmpty()) {
      $text = trim((string) $product->get('field_mel_extra_short_desc')->value);
      if ($text !== '') {
        return $this->sanitizePlainText($text, 600);
      }
    }
    $from_json = trim((string) ($presentation['operational_summary'] ?? ''));
    return $from_json !== '' ? $this->sanitizePlainText($from_json, 600) : '';
  }

  /**
   * @param array<string, mixed> $presentation
   */
  public function resolvePickupNote(ProductInterface $product, array $presentation): string {
    if ($product->hasField('field_mel_extra_pickup_note') && !$product->get('field_mel_extra_pickup_note')->isEmpty()) {
      $text = trim((string) $product->get('field_mel_extra_pickup_note')->value);
      if ($text !== '') {
        return $this->sanitizePlainText($text, 400);
      }
    }
    $chips = is_array($presentation['operational_chips'] ?? NULL) ? $presentation['operational_chips'] : [];
    foreach ($chips as $chip) {
      if (!is_array($chip)) {
        continue;
      }
      $label = trim((string) ($chip['label'] ?? ''));
      $tone = strtolower(trim((string) ($chip['tone'] ?? '')));
      if ($label !== '' && in_array($tone, ['pickup', 'info'], TRUE)) {
        return $label;
      }
    }
    return $this->pickupNoteFromMode((string) ($presentation['pickup_mode'] ?? ''));
  }

  /**
   * @return array{size_key: string, size_label: string}
   */
  public function resolveVariationSize(ProductVariationInterface $variation): array {
    if ($variation->hasField('field_mel_size') && !$variation->get('field_mel_size')->isEmpty()) {
      $key = strtolower(trim((string) $variation->get('field_mel_size')->value));
      if ($key !== '' && isset(self::SIZE_LABELS[$key])) {
        return ['size_key' => $key, 'size_label' => self::SIZE_LABELS[$key]];
      }
    }
    $label = trim((string) $variation->label());
    $lower = strtolower($label);
    if (isset(self::SIZE_LABELS[$lower])) {
      return ['size_key' => $lower, 'size_label' => self::SIZE_LABELS[$lower]];
    }
    foreach (self::SIZE_LABELS as $key => $human) {
      if (strcasecmp($label, $human) === 0) {
        return ['size_key' => $key, 'size_label' => $human];
      }
    }
    return ['size_key' => '', 'size_label' => ''];
  }

  public function meaningfulVariationLabel(string $variation_label, string $product_title): string {
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
   * @return list<array<string, mixed>>
   */
  public function buildGalleryFromProduct(ProductInterface $product): array {
    if (!$product->hasField('field_mel_extra_images') || $product->get('field_mel_extra_images')->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($product->get('field_mel_extra_images')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      $built = $this->buildImageFromMedia($media, (string) $product->label());
      if ($built !== NULL) {
        $out[] = $built;
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildPrimaryThumbnailForProduct(ProductInterface $product): ?array {
    $gallery = $this->buildGalleryFromProduct($product);
    if ($gallery !== []) {
      return $gallery[0];
    }
    return $this->buildPlaceholderImage();
  }

  /**
   * @return array<string, mixed>|null
   */
  public function buildPrimaryThumbnailForVariation(ProductVariationInterface $variation): ?array {
    $product = $variation->getProduct();
    if ($product instanceof ProductInterface) {
      return $this->buildPrimaryThumbnailForProduct($product);
    }
    return $this->buildPlaceholderImage();
  }

  /**
   * @return array<string, mixed>
   */
  public function buildPlaceholderImage(): array {
    return [
      'url' => $this->placeholderUrl(),
      'alt' => (string) $this->t('Event extra'),
      'is_placeholder' => TRUE,
      'media_id' => 0,
    ];
  }

  public function placeholderUrl(): string {
    $theme_path = $this->extensionPathResolver->getPath('theme', 'myeventlane_theme');
    $relative = $theme_path . '/images/mel/placeholders/mel-placeholder-default.svg';
    return $this->fileUrlGenerator->generateAbsoluteString($relative);
  }

  public function formatPrice(?Price $price): string {
    if (!$price instanceof Price) {
      return '';
    }
    $number = $price->getNumber();
    $currency = $price->getCurrencyCode();
    if ($number === '' || $currency === '') {
      return '';
    }
    return $currency . $number;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildImageFromMedia(MediaInterface $media, string $fallback_alt): ?array {
    if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }
    $item = $media->get('field_media_image')->first();
    if ($item === NULL) {
      return NULL;
    }
    $file = $item->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $alt = trim((string) ($item->alt ?? ''));
    if ($alt === '') {
      $alt = $fallback_alt;
    }
    $style = $this->loadImageStyle();
    $uri = $file->getFileUri();
    $url = $style instanceof ImageStyleInterface
      ? $style->buildUrl($uri)
      : $this->fileUrlGenerator->generateAbsoluteString($uri);

    return [
      'url' => $url,
      'alt' => $this->sanitizePlainText($alt, 255),
      'is_placeholder' => FALSE,
      'media_id' => (int) $media->id(),
    ];
  }

  private function loadImageStyle(): ?ImageStyleInterface {
    $style = $this->entityTypeManager->getStorage('image_style')->load(self::IMAGE_STYLE);
    return $style instanceof ImageStyleInterface ? $style : NULL;
  }

  private function pickupNoteFromMode(string $pickup_mode): string {
    return match ($pickup_mode) {
      'venue_pickup', 'counter', 'timed_window' => (string) $this->t('Collect at the venue'),
      default => '',
    };
  }

  private function sanitizePlainText(string $text, int $max): string {
    $text = trim(strip_tags($text));
    if (mb_strlen($text, 'UTF-8') > $max) {
      return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
    return $text;
  }

}
