<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Normalizes event media into role-based view models for templates.
 *
 * Hero rendering stays on field_event_image + existing image styles; this
 * presenter does not replace hero render arrays — it supplies gallery payloads.
 */
final class EventMediaPresenter {

  public const ROLE_HERO = 'hero';

  public const ROLE_GALLERY = 'gallery';

  public const COMPOSITION_SINGLE = 'single';

  public const COMPOSITION_DUO = 'duo';

  public const COMPOSITION_TRIO = 'trio';

  public const COMPOSITION_EDITORIAL = 'editorial';

  private const STYLE_GALLERY_CARD = 'mel_event_gallery_card';

  private const STYLE_GALLERY_LIGHTBOX = 'mel_event_gallery_lightbox';

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gallery view model for public event pages.
   *
   * @return array{
   *   role: string,
   *   count: int,
   *   composition: string,
   *   items: list<array{
   *     index: int,
   *     mid: int,
   *     alt: string,
   *     role: string,
   *     emphasis: string,
   *     layout_class: string,
   *     urls: array{card: string, lightbox: string},
   *     width: int,
   *     height: int,
   *     srcset: string,
   *     sizes: string,
   *   }>,
   * }
   */
  public function buildGalleryViewModel(NodeInterface $event): array {
    $empty = [
      'role' => self::ROLE_GALLERY,
      'count' => 0,
      'composition' => self::COMPOSITION_SINGLE,
      'items' => [],
    ];

    if (!$event->hasField('field_mel_event_gallery') || $event->get('field_mel_event_gallery')->isEmpty()) {
      return $empty;
    }

    $card_style = $this->loadImageStyle(self::STYLE_GALLERY_CARD);
    $lightbox_style = $this->loadImageStyle(self::STYLE_GALLERY_LIGHTBOX);
    if ($card_style === NULL || $lightbox_style === NULL) {
      $this->logger->error('Event gallery image styles are missing; cannot build gallery for node @nid.', [
        '@nid' => (string) $event->id(),
      ]);
      return $empty;
    }

    $items = [];
    $index = 0;
    foreach ($event->get('field_mel_event_gallery')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface || $media->bundle() !== 'image') {
        continue;
      }
      if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        continue;
      }
      $file = $media->get('field_media_image')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $uri = $file->getFileUri();
      if ($uri === '') {
        continue;
      }

      $image_item = $media->get('field_media_image')->first();
      $alt = trim((string) ($image_item?->alt ?? ''));
      if ($alt === '') {
        $alt = trim((string) $media->label());
      }

      $width = (int) ($image_item?->width ?? 0);
      $height = (int) ($image_item?->height ?? 0);

      try {
        $card_url = $card_style->buildUrl($uri);
        $lightbox_url = $lightbox_style->buildUrl($uri);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Skipping gallery media @mid on node @nid: @message', [
          '@mid' => (string) $media->id(),
          '@nid' => (string) $event->id(),
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      $editorial = $this->editorialMetadataForIndex($index);
      $items[] = [
        'index' => $index,
        'mid' => (int) $media->id(),
        'alt' => $alt,
        'role' => $editorial['role'],
        'emphasis' => $editorial['emphasis'],
        'layout_class' => $editorial['layout_class'],
        'urls' => [
          'card' => $card_url,
          'lightbox' => $lightbox_url,
        ],
        'width' => $width,
        'height' => $height,
        'srcset' => $card_url . ' 960w, ' . $lightbox_url . ' 1600w',
        'sizes' => $editorial['sizes'],
      ];
      $index++;
    }

    $count = count($items);

    return [
      'role' => self::ROLE_GALLERY,
      'count' => $count,
      'composition' => $this->resolveComposition($count),
      'items' => $items,
    ];
  }

  /**
   * Combined media payload for preprocess (extensible for future roles).
   *
   * @return array<string, mixed>
   */
  public function buildEventMediaViewModel(NodeInterface $event, string $view_mode = 'full'): array {
    $gallery = $view_mode === 'full'
      ? $this->buildGalleryViewModel($event)
      : [
        'role' => self::ROLE_GALLERY,
        'count' => 0,
        'composition' => self::COMPOSITION_SINGLE,
        'items' => [],
      ];

    return [
      'gallery' => $gallery,
      'has_gallery' => $gallery['count'] > 0,
    ];
  }

  /**
   * Deterministic composition id from image count (automatic, no vendor config).
   */
  public function resolveComposition(int $count): string {
    return match (TRUE) {
      $count <= 0 => self::COMPOSITION_SINGLE,
      $count === 1 => self::COMPOSITION_SINGLE,
      $count === 2 => self::COMPOSITION_DUO,
      $count === 3 => self::COMPOSITION_TRIO,
      default => self::COMPOSITION_EDITORIAL,
    };
  }

  /**
   * Editorial role, emphasis, layout class, and responsive sizes per index.
   *
   * @return array{role: string, emphasis: string, layout_class: string, sizes: string}
   */
  private function editorialMetadataForIndex(int $index): array {
    if ($index === 0) {
      return [
        'role' => 'lead',
        'emphasis' => 'primary',
        'layout_class' => 'mel-event-gallery__item--lead',
        'sizes' => '(min-width: 900px) 58vw, 92vw',
      ];
    }
    if ($index <= 2) {
      return [
        'role' => 'support',
        'emphasis' => 'secondary',
        'layout_class' => 'mel-event-gallery__item--support',
        'sizes' => '(min-width: 900px) 28vw, 78vw',
      ];
    }
    return [
      'role' => 'detail',
      'emphasis' => 'tertiary',
      'layout_class' => 'mel-event-gallery__item--detail',
      'sizes' => '(min-width: 900px) 22vw, 72vw',
    ];
  }

  private function loadImageStyle(string $id): ?ImageStyleInterface {
    $style = ImageStyle::load($id);
    return $style instanceof ImageStyleInterface ? $style : NULL;
  }

}
