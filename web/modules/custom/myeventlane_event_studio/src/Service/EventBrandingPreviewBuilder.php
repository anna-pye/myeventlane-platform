<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_event\Service\EventMediaPresenter;
use Drupal\node\NodeInterface;

/**
 * Builds a customer-safe public event page preview for Event Studio branding.
 */
final class EventBrandingPreviewBuilder {

  use StringTranslationTrait;

  private const HERO_STYLE = 'mel_event_hero_featured';

  private const GALLERY_PREVIEW_MAX = 5;

  /**
   * Approved accent hex values (mirrors public theme branding map).
   *
   * @var array<string, string>
   */
  private const ACCENT_HEX = [
    EventPageStyleResolver::COLOUR_CORAL => '#F26D5B',
    EventPageStyleResolver::COLOUR_PURPLE => '#7C83FD',
    EventPageStyleResolver::COLOUR_MINT => '#3D9B7A',
    EventPageStyleResolver::COLOUR_GOLD => '#F5A04C',
    EventPageStyleResolver::COLOUR_BLUE => '#5B8DEF',
  ];

  public function __construct(
    private readonly EventPageStyleResolver $eventPageStyleResolver,
    private readonly EventMediaPresenter $eventMediaPresenter,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly AccountProxyInterface $currentUser,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Render array for the branding tab preview panel.
   *
   * @return array<string, mixed>
   */
  public function build(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [];
    }

    $resolved = $this->eventPageStyleResolver->resolveForPublicRender($event);
    $style = $resolved['style'];
    $colour = $resolved['colour'];
    $colour_labels = $this->eventPageStyleResolver->colourOptions();
    $style_labels = $this->eventPageStyleResolver->styleOptions();

    $hero = $this->buildHeroPayload($event);
    $gallery = $this->buildGalleryThumbnails($event);

    $wrapper_classes = array_merge(
      ['mel-event-branding-preview'],
      $resolved['classes'],
      [
        'mel-event-branding-preview--' . $style,
      ],
    );

    $public_page_link = $this->buildPublicPageLink($event);

    return [
      '#theme' => 'mel_event_branding_preview',
      '#title' => $event->label(),
      '#hero' => $hero,
      '#gallery' => $gallery,
      '#style' => $style,
      '#colour' => $colour,
      '#saved_style' => $style,
      '#saved_colour' => $colour,
      '#style_label' => $style_labels[$style] ?? $style,
      '#colour_label' => $colour_labels[$colour] ?? $colour,
      '#accent_hex' => self::ACCENT_HEX[$colour] ?? self::ACCENT_HEX[EventPageStyleResolver::COLOUR_CORAL],
      '#wrapper_classes' => $wrapper_classes,
      '#public_page_link' => $public_page_link,
      '#sample_meta' => [
        'date' => (string) $this->t('Sat 14 Jun 2026'),
        'time' => (string) $this->t('7:00 pm – 10:00 pm'),
        'location' => (string) $this->t('Sample venue, Sydney'),
      ],
      '#sample_cta' => (string) $this->t('Get tickets'),
      '#trust_rows' => [
        (string) $this->t('Secure checkout'),
        (string) $this->t('Instant confirmation'),
        (string) $this->t('Mobile tickets'),
      ],
      '#attached' => [
        'library' => ['myeventlane_event_studio/mel_branding_preview'],
        'drupalSettings' => [
          'melEventBrandingPreview' => [
            'savedStyle' => $style,
            'savedColour' => $colour,
            'styleLabels' => $style_labels,
            'colourLabels' => $colour_labels,
            'stateSavedMedia' => (string) $this->t('Preview based on saved event media.'),
            'stateUnsavedStyleColour' => (string) $this->t('Unsaved style and colour changes are shown here before saving.'),
          ],
        ],
      ],
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Canonical public event page link when the current user may view it.
   *
   * @return array<string, mixed>|null
   */
  private function buildPublicPageLink(NodeInterface $event): ?array {
    if ($event->isNew() || $event->id() === NULL || (int) $event->id() < 1) {
      return NULL;
    }

    try {
      $url = Url::fromRoute('entity.node.canonical', ['node' => (int) $event->id()]);
      if (!$url->access($this->currentUser)) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    return [
      '#type' => 'link',
      '#title' => $this->t('View public event page'),
      '#url' => $url,
      '#attributes' => [
        'class' => [
          'button',
          'button--secondary',
          'mel-event-branding-preview__public-link',
        ],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ];
  }

  /**
   * @return array{url: string, alt: string}|null
   */
  private function buildHeroPayload(NodeInterface $event): ?array {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $file = $event->get('field_event_image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    $uri = $file->getFileUri();
    if ($uri === '') {
      return NULL;
    }

    $style = $this->loadImageStyle(self::HERO_STYLE);
    try {
      $url = $style instanceof ImageStyleInterface
        ? $style->buildUrl($uri)
        : $this->fileUrlGenerator->generateString($uri);
    }
    catch (\Throwable) {
      return NULL;
    }

    $image_item = $event->get('field_event_image')->first();
    $alt = trim((string) ($image_item?->alt ?? ''));
    if ($alt === '') {
      $alt = (string) $event->label();
    }

    return [
      'url' => $url,
      'alt' => $alt,
    ];
  }

  /**
   * @return list<array{url: string, alt: string}>
   */
  private function buildGalleryThumbnails(NodeInterface $event): array {
    $gallery = $this->eventMediaPresenter->buildGalleryViewModel($event);
    $items = is_array($gallery['items'] ?? NULL) ? $gallery['items'] : [];
    $out = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $urls = is_array($item['urls'] ?? NULL) ? $item['urls'] : [];
      $url = trim((string) ($urls['card'] ?? ''));
      if ($url === '') {
        continue;
      }
      $out[] = [
        'url' => $url,
        'alt' => trim((string) ($item['alt'] ?? '')),
      ];
      if (count($out) >= self::GALLERY_PREVIEW_MAX) {
        break;
      }
    }
    return $out;
  }

  private function loadImageStyle(string $id): ?ImageStyleInterface {
    $style = ImageStyle::load($id);
    return $style instanceof ImageStyleInterface ? $style : NULL;
  }

}
