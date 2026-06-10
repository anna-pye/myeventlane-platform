<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Builds render arrays for homepage readiness guidance surfaces.
 */
final class FeaturedEventReadinessRenderBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly FeaturedEventReadinessService $readinessService,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Compact checklist card for boost purchase, success, and Event Studio.
   *
   * @return array<string, mixed>
   */
  public function buildChecklistCard(NodeInterface $event, bool $compact = TRUE, bool $show_spotlight_notice = FALSE): array {
    $presentation = $this->readinessService->getPresentation($event);

    return [
      '#theme' => 'mel_homepage_readiness_checklist',
      '#presentation' => $presentation,
      '#compact' => $compact,
      '#show_spotlight_notice' => $show_spotlight_notice,
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => ['languages:language_interface'],
      ],
      '#attached' => [
        'library' => ['myeventlane_event/homepage_readiness'],
      ],
    ];
  }

  /**
   * Dashboard summary card for active boosted events.
   *
   * @param list<\Drupal\node\NodeInterface> $boosted_events
   *
   * @return array<string, mixed>|null
   */
  public function buildDashboardSummary(array $boosted_events): ?array {
    if ($boosted_events === []) {
      return NULL;
    }

    $ready = 0;
    $needs_attention = 0;
    foreach ($boosted_events as $event) {
      if ($this->readinessService->isReady($event)) {
        $ready++;
      }
      else {
        $needs_attention++;
      }
    }

    return [
      '#theme' => 'mel_homepage_readiness_dashboard_summary',
      '#heading' => (string) $this->t('Homepage Readiness'),
      '#total_boosted' => count($boosted_events),
      '#ready_count' => $ready,
      '#needs_attention_count' => $needs_attention,
      '#status_label' => $needs_attention === 0
        ? (string) $this->t('All boosted events are homepage ready')
        : (string) $this->t('@ready ready, @attention need attention', [
          '@ready' => $ready,
          '@attention' => $needs_attention,
        ]),
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user', 'languages:language_interface'],
      ],
      '#attached' => [
        'library' => ['myeventlane_event/homepage_readiness'],
      ],
    ];
  }

}
