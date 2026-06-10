<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Evaluates whether an event is ready for homepage merchandising surfaces.
 *
 * Reuses BoostedEventQualityGate for hero/focal checks and aligns with publish
 * signals used elsewhere in Event Studio (dates, summary, venue, category).
 */
final class FeaturedEventReadinessService {

  use StringTranslationTrait;

  public const TIER_REQUIRED = 'required';

  public const TIER_RECOMMENDED = 'recommended';

  public function __construct(
    private readonly BoostedEventQualityGate $qualityGate,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Whether all required homepage readiness checks pass.
   */
  public function isReady(NodeInterface $event): bool {
    foreach ($this->getReadinessChecklist($event) as $item) {
      if (($item['tier'] ?? '') === self::TIER_REQUIRED && empty($item['complete'])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Readiness score across required and recommended checks (0–100).
   */
  public function getReadinessScore(NodeInterface $event): int {
    $items = $this->getReadinessChecklist($event);
    if ($items === []) {
      return 0;
    }

    $complete = 0;
    foreach ($items as $item) {
      if (!empty($item['complete'])) {
        $complete++;
      }
    }

    return (int) round(($complete / count($items)) * 100);
  }

  /**
   * Structured checklist for vendor guidance surfaces.
   *
   * @return list<array{
   *   item_key: string,
   *   tier: string,
   *   complete: bool,
   *   label: string,
   *   label_incomplete: string,
   * }>
   */
  public function getReadinessChecklist(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [];
    }

    return [
      $this->buildItem(
        'hero_image',
        self::TIER_REQUIRED,
        $this->qualityGate->hasHeroImage($event),
        (string) $this->t('Readable hero image uploaded'),
        (string) $this->t('Readable hero image missing'),
      ),
      $this->buildItem(
        'focal_point',
        self::TIER_REQUIRED,
        $this->qualityGate->hasFocalPoint($event),
        (string) $this->t('Focal point selected'),
        (string) $this->t('Focal point missing'),
      ),
      $this->buildItem(
        'published',
        self::TIER_REQUIRED,
        $event->isPublished(),
        (string) $this->t('Event published'),
        (string) $this->t('Event not published'),
      ),
      $this->buildItem(
        'event_date',
        self::TIER_REQUIRED,
        $this->hasEventStart($event),
        (string) $this->t('Event date set'),
        (string) $this->t('Event date missing'),
      ),
      $this->buildItem(
        'summary',
        self::TIER_RECOMMENDED,
        $this->hasSummary($event),
        (string) $this->t('Event summary added'),
        (string) $this->t('Event summary missing'),
      ),
      $this->buildItem(
        'venue',
        self::TIER_RECOMMENDED,
        $this->hasVenue($event),
        (string) $this->t('Venue added'),
        (string) $this->t('Venue missing'),
      ),
      $this->buildItem(
        'category',
        self::TIER_RECOMMENDED,
        $this->hasCategory($event),
        (string) $this->t('Category added'),
        (string) $this->t('Category missing'),
      ),
    ];
  }

  /**
   * Presentation payload for Twig and JSON consumers.
   *
   * @return array<string, mixed>
   */
  public function getPresentation(NodeInterface $event): array {
    $items = $this->getReadinessChecklist($event);
    $required = array_values(array_filter(
      $items,
      static fn (array $item): bool => ($item['tier'] ?? '') === self::TIER_REQUIRED,
    ));
    $recommended = array_values(array_filter(
      $items,
      static fn (array $item): bool => ($item['tier'] ?? '') === self::TIER_RECOMMENDED,
    ));
    $ready = $this->isReady($event);

    return [
      'ready' => $ready,
      'score' => $this->getReadinessScore($event),
      'marketplace_ready' => $this->qualityGate->isMarketplaceReady($event),
      'image_quality' => [
        'readable' => $this->qualityGate->hasHeroImage($event),
        'focal_point' => $this->qualityGate->hasFocalPoint($event),
        'avoids_placeholder' => $this->qualityGate->isMarketplaceReady($event),
      ],
      'status_label' => $ready
        ? (string) $this->t('Ready for homepage promotion')
        : (string) $this->t('Needs attention before homepage promotion'),
      'short_status_label' => $ready
        ? (string) $this->t('Ready for homepage promotion')
        : (string) $this->t('Needs attention'),
      'heading' => (string) $this->t('Homepage Readiness'),
      'intro' => $ready
        ? (string) $this->t('Your event meets the basics for Community Spotlight placement. Boost helps more people discover it across MyEventLane.')
        : (string) $this->t('We want to help you get more attendees. Complete the items below so boosted events can appear in Community Spotlight.'),
      'spotlight_notice' => (string) $this->t('Your event may not appear in Community Spotlight until this is completed.'),
      'items' => $items,
      'required' => $required,
      'recommended' => $recommended,
    ];
  }

  /**
   * @return array{
   *   item_key: string,
   *   tier: string,
   *   complete: bool,
   *   label: string,
   *   label_incomplete: string,
   * }
   */
  private function buildItem(
    string $key,
    string $tier,
    bool $complete,
    string $label,
    string $label_incomplete,
  ): array {
    return [
      'item_key' => $key,
      'tier' => $tier,
      'complete' => $complete,
      'label' => $label,
      'label_incomplete' => $label_incomplete,
    ];
  }

  private function hasEventStart(NodeInterface $event): bool {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return FALSE;
    }

    $value = $event->get('field_event_start')->value;
    return is_string($value) && trim($value) !== '';
  }

  private function hasSummary(NodeInterface $event): bool {
    if (!$event->hasField('field_event_summary') || $event->get('field_event_summary')->isEmpty()) {
      return FALSE;
    }

    return trim(strip_tags((string) $event->get('field_event_summary')->value)) !== '';
  }

  private function hasVenue(NodeInterface $event): bool {
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      return TRUE;
    }
    if ($event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      return TRUE;
    }
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      return trim((string) $event->get('field_venue_name')->value) !== '';
    }

    return FALSE;
  }

  private function hasCategory(NodeInterface $event): bool {
    return $event->hasField('field_category') && !$event->get('field_category')->isEmpty();
  }

}
