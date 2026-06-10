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
    return $this->buildChecklistCardFromPresentation(
      $event,
      $this->readinessService->getPresentation($event),
      $compact,
      $show_spotlight_notice,
    );
  }

  /**
   * Checklist card from an existing presentation payload (avoids duplicate evaluation).
   *
   * @param array<string, mixed> $presentation
   *   Payload from FeaturedEventReadinessService::getPresentation().
   *
   * @return array<string, mixed>
   */
  public function buildChecklistCardFromPresentation(
    NodeInterface $event,
    array $presentation,
    bool $compact = TRUE,
    bool $show_spotlight_notice = FALSE,
    bool $summary_only = FALSE,
    bool $hide_published_item = FALSE,
  ): array {
    return [
      '#theme' => 'mel_homepage_readiness_checklist',
      '#presentation' => $presentation,
      '#compact' => $compact,
      '#show_spotlight_notice' => $show_spotlight_notice,
      '#summary_only' => $summary_only,
      '#hide_published_item' => $hide_published_item,
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

    return $this->buildDashboardSummaryFromCounts(count($boosted_events), $ready, $needs_attention);
  }

  /**
   * Dashboard summary from precomputed promotion readiness counts.
   *
   * @return array<string, mixed>
   */
  public function buildDashboardSummaryFromCounts(int $total_boosted, int $ready_count, int $needs_attention_count): array {
    return [
      '#theme' => 'mel_homepage_readiness_dashboard_summary',
      '#heading' => (string) $this->t('Homepage Readiness'),
      '#total_boosted' => $total_boosted,
      '#ready_count' => $ready_count,
      '#needs_attention_count' => $needs_attention_count,
      '#status_label' => $needs_attention_count === 0
        ? (string) $this->t('All boosted events are ready for homepage promotion')
        : (string) $this->t('@ready ready for promotion, @attention need attention', [
          '@ready' => $ready_count,
          '@attention' => $needs_attention_count,
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
