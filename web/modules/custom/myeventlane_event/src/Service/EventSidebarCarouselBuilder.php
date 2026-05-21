<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_commerce\Service\EventOperationalAddonTeaserBuilder;
use Drupal\node\NodeInterface;

/**
 * Builds the slide manifest for the unified event full-page sidebar carousel.
 *
 * Uses only data already available on the event full preprocess — no new entities.
 */
final class EventSidebarCarouselBuilder {

  use StringTranslationTrait;

  private const SIDEBAR_GALLERY_MAX = 3;

  public function __construct(
    TranslationInterface $string_translation,
    private readonly ?EventOperationalAddonTeaserBuilder $addonTeaserBuilder = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds carousel metadata from event full template variables.
   *
   * @param array<string, mixed> $variables
   *   Variables from event full node preprocess + theme preprocess.
   *
   * @return array{
   *   slides: list<array<string, mixed>>,
   *   count: int,
   *   lightbox_root_id: string,
   * }
   */
  public function buildFromEventPageVariables(array $variables): array {
    $slides = [];

    $slides[] = [
      'type' => 'booking',
      'id' => 'booking',
      'nav_label' => (string) $this->t('Tickets'),
    ];

    $gallery = is_array($variables['mel_event_gallery'] ?? NULL) ? $variables['mel_event_gallery'] : [];
    $gallery_items = is_array($gallery['items'] ?? NULL) ? $gallery['items'] : [];
    if ((int) ($gallery['count'] ?? 0) > 0 && $gallery_items !== []) {
      $slides[] = [
        'type' => 'gallery',
        'id' => 'gallery',
        'nav_label' => (string) $this->t('Photos'),
        'items' => array_values(array_slice($gallery_items, 0, self::SIDEBAR_GALLERY_MAX)),
        'total' => (int) ($gallery['count'] ?? count($gallery_items)),
      ];
    }

    $extras = $this->resolveExtrasDocument($variables);
    if ($extras !== NULL) {
      $slides[] = [
        'type' => 'extras',
        'id' => 'extras',
        'nav_label' => (string) $this->t('Extras'),
        'document' => $extras,
      ];
    }

    if ($this->hasSocialSlide($variables)) {
      $slides[] = [
        'type' => 'social',
        'id' => 'social',
        'nav_label' => (string) $this->t('Buzz'),
      ];
    }

    if ($this->hasTrustSlide($variables)) {
      $slides[] = [
        'type' => 'trust',
        'id' => 'trust',
        'nav_label' => (string) $this->t('Trust'),
      ];
    }

    $organiser = is_array($variables['mel_organiser'] ?? NULL) ? $variables['mel_organiser'] : NULL;
    if ($organiser !== NULL && trim((string) ($organiser['name'] ?? '')) !== '') {
      $slides[] = [
        'type' => 'organiser',
        'id' => 'organiser',
        'nav_label' => (string) $this->t('Host'),
        'organiser' => $organiser,
      ];
    }

    return [
      'slides' => $slides,
      'count' => count($slides),
      'lightbox_root_id' => 'mel-event-gallery-main',
    ];
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function hasSocialSlide(array $variables): bool {
    if (!empty($variables['event_viewer_count'])) {
      return TRUE;
    }
    if ((int) ($variables['event_save_count_total'] ?? 0) > 0 && !empty($variables['event_save_count'])) {
      return TRUE;
    }
    $interest = is_array($variables['event_interest'] ?? NULL) ? $variables['event_interest'] : [];
    return trim((string) ($interest['label'] ?? '')) !== '';
  }

  /**
   * @param array<string, mixed> $variables
   */
  private function hasTrustSlide(array $variables): bool {
    if (!empty($variables['mel_show_booking_trust_copy'])) {
      return TRUE;
    }
    return (string) ($variables['google_calendar_url'] ?? '') !== '';
  }

  /**
   * @param array<string, mixed> $variables
   *
   * @return array<string, mixed>|null
   */
  private function resolveExtrasDocument(array $variables): ?array {
    if ($this->addonTeaserBuilder === NULL) {
      return NULL;
    }
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    $doc = $this->addonTeaserBuilder->buildForEvent($node, 'event');
    if (!empty($doc['is_empty']) || !is_array($doc['items'] ?? NULL) || $doc['items'] === []) {
      return NULL;
    }
    return $doc;
  }

}
