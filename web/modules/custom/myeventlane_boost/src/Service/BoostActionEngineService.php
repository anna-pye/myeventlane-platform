<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Prioritised Boost action recommendations with CTAs (Phase 10).
 *
 * Reuses analytics signals from BoostDecisionSupportService rules without
 * duplicating underlying metric calculations.
 */
final class BoostActionEngineService {

  use StringTranslationTrait;

  /**
   * Constructs BoostActionEngineService.
   */
  public function __construct(
    private readonly BoostDecisionSupportService $decisionSupport,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds up to three prioritised actions for an event Boost panel.
   *
   * @param array<string, mixed> $boost_metrics
   *   Output from BoostMetricsService::getEventBoostMetrics().
   * @param array<string, mixed> $boost
   *   Boost status from MetricsAggregator (expects 'active' key).
   * @param array{
   *   show: bool,
   *   cards: list<array<string, mixed>>,
   * } $placement_performance_section
   * @param string|null $edit_event_url
   * @param string|null $boost_page_url
   * @param string|null $public_event_url
   *
   * @return array{
   *   show: bool,
   *   actions: list<array{
   *     id: string,
   *     category: string,
   *     title: string,
   *     description: string,
   *     cta_label: string,
   *     cta_url: string,
   *   }>,
   * }
   */
  public function build(
    array $boost_metrics,
    array $boost,
    bool $isTickets,
    bool $isRsvp,
    array $placement_performance_section,
    ?string $edit_event_url = NULL,
    ?string $boost_page_url = NULL,
    ?string $public_event_url = NULL,
  ): array {
    $impressions = (int) ($boost_metrics['impressions'] ?? 0);
    $clicks = (int) ($boost_metrics['clicks'] ?? 0);
    $ctr = (float) ($boost_metrics['ctr'] ?? 0.0);
    $spend = $this->parseFormattedAmount((string) ($boost_metrics['spend'] ?? '$0.00'));
    $revenue = (float) ($boost_metrics['revenue_during_boost'] ?? 0.0);
    $orders = (int) ($boost_metrics['orders_during_boost'] ?? 0);
    $rsvps = (int) ($boost_metrics['rsvps_during_boost'] ?? 0);
    $donation_revenue = (float) ($boost_metrics['donation_revenue_during_boost'] ?? 0.0);
    $boost_active = !empty($boost['active']);
    $has_boost_history = $spend > 0.0 || $impressions > 0 || $clicks > 0 || $boost_active;

    if (!$has_boost_history) {
      return $this->emptyPayload();
    }

    $roi_revenue = $this->resolveRoiRevenue($revenue, $donation_revenue, $isTickets, $isRsvp);
    $candidates = [];

    if ($spend > 0.0 && $roi_revenue > $spend * 1.5 && $boost_page_url !== NULL && $boost_page_url !== '') {
      $candidates[] = $this->candidate(
        'extend_boost',
        'growth',
        100,
        (string) $this->t('Extend your Boost'),
        (string) $this->t('Strong return on Boost spend — keep momentum going while performance is high.'),
        (string) $this->t('Boost Event'),
        $boost_page_url,
      );
    }

    if ($impressions >= 100 && $ctr < 0.01 && $edit_event_url !== NULL && $edit_event_url !== '') {
      $candidates[] = $this->candidate(
        'refresh_hero_image',
        'visibility',
        95,
        (string) $this->t('Refresh your event image'),
        (string) $this->t('Visibility is solid but click-through is low — a stronger hero image can lift engagement.'),
        (string) $this->t('Edit Event'),
        $edit_event_url,
      );
    }

    if ($isTickets && $clicks >= 20 && $orders < max(2, (int) floor($clicks * 0.03))
      && $edit_event_url !== NULL && $edit_event_url !== '') {
      $candidates[] = $this->candidate(
        'improve_event_page',
        'conversion',
        90,
        (string) $this->t('Improve your event description'),
        (string) $this->t('People are clicking through but not buying — sharpen your event page copy.'),
        (string) $this->t('Edit Event'),
        $edit_event_url,
      );
    }

    if ($isRsvp && $clicks >= 20 && $rsvps < max(3, (int) floor($clicks * 0.05))
      && $edit_event_url !== NULL && $edit_event_url !== '') {
      $candidates[] = $this->candidate(
        'improve_rsvp_page',
        'conversion',
        88,
        (string) $this->t('Improve your event description'),
        (string) $this->t('Good click volume but few RSVPs during Boost — make your event page more compelling.'),
        (string) $this->t('Edit Event'),
        $edit_event_url,
      );
    }

    if ($isRsvp && $rsvps >= 10 && $donation_revenue <= 0.0
      && $edit_event_url !== NULL && $edit_event_url !== '') {
      $candidates[] = $this->candidate(
        'add_donation_prompt',
        'revenue',
        85,
        (string) $this->t('Add a donation prompt'),
        (string) $this->t('RSVPs are strong — invite attendees to support your event with an optional donation.'),
        (string) $this->t('Edit Event'),
        $edit_event_url,
      );
    }

    $winner = $this->resolvePlacementWinnerCard($placement_performance_section);
    if ($winner !== NULL) {
      $winner_key = (string) ($winner['key'] ?? '');
      $winner_label = (string) ($winner['label'] ?? '');
      if (str_starts_with($winner_key, 'category_') && $winner_label !== ''
        && $public_event_url !== NULL && $public_event_url !== '') {
        $candidates[] = $this->candidate(
          'share_category_placement',
          'growth',
          75,
          (string) $this->t('Share your event'),
          (string) $this->t('@placement is your strongest category placement — share your event link to build on that reach.', [
            '@placement' => $winner_label,
          ]),
          (string) $this->t('Share Event'),
          $public_event_url,
        );
      }
      elseif ((str_starts_with($winner_key, 'homepage_') || $winner_key === 'homepage_discover')
        && ((float) ($winner['ctr'] ?? 0.0)) >= 0.03
        && $boost_page_url !== NULL && $boost_page_url !== '') {
        $candidates[] = $this->candidate(
          'extend_homepage_boost',
          'growth',
          72,
          (string) $this->t('Extend your Boost'),
          (string) $this->t('@placement is performing well — extend Boost to keep that visibility.', [
            '@placement' => $winner_label,
          ]),
          (string) $this->t('Boost Event'),
          $boost_page_url,
        );
      }
    }

    $weakest = $this->resolveWeakestPlacementCard($placement_performance_section);
    if ($weakest !== NULL && $edit_event_url !== NULL && $edit_event_url !== '') {
      $weakest_key = (string) ($weakest['key'] ?? '');
      if ($weakest_key === 'search_results' || str_starts_with($weakest_key, 'search_')) {
        $candidates[] = $this->candidate(
          'improve_search_appearance',
          'visibility',
          65,
          (string) $this->t('Improve how you appear in search'),
          (string) $this->t('Search Results has your weakest click-through — review your event title and summary.'),
          (string) $this->t('Edit Event'),
          $edit_event_url,
        );
      }
    }

    if (!$boost_active && $spend <= 0.0 && $boost_page_url !== NULL && $boost_page_url !== '') {
      $candidates[] = $this->candidate(
        'start_boost',
        'growth',
        55,
        (string) $this->t('Boost your event'),
        (string) $this->t('Reach more potential attendees with a Boost campaign.'),
        (string) $this->t('Boost Event'),
        $boost_page_url,
      );
    }

    foreach ($boost_metrics['recommendations'] ?? [] as $metrics_rec) {
      if (!is_string($metrics_rec) || $metrics_rec === ''
        || $edit_event_url === NULL || $edit_event_url === '') {
        continue;
      }
      $candidates[] = $this->candidate(
        'metrics_' . md5($metrics_rec),
        'growth',
        50,
        (string) $this->t('Review Boost placement'),
        $metrics_rec,
        (string) $this->t('Edit Event'),
        $edit_event_url,
      );
    }

    $actions = $this->selectActions($candidates);

    return [
      'show' => $actions !== [],
      'actions' => $actions,
    ];
  }

  /**
   * Selects up to three unique, non-contradictory actions.
   *
   * @param list<array<string, mixed>> $candidates
   *
   * @return list<array<string, mixed>>
   */
  private function selectActions(array $candidates): array {
    usort($candidates, static fn (array $a, array $b): int => (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0));

    $selected = [];
    $seenIds = [];
    $seenCategories = [];

    foreach ($candidates as $candidate) {
      $id = (string) ($candidate['id'] ?? '');
      $category = (string) ($candidate['category'] ?? '');
      $ctaUrl = (string) ($candidate['cta_url'] ?? '');
      if ($id === '' || $ctaUrl === '' || isset($seenIds[$id])) {
        continue;
      }

      if ($this->contradictsExisting($candidate, $selected)) {
        continue;
      }

      if ($category !== '' && isset($seenCategories[$category])) {
        continue;
      }

      unset($candidate['priority']);
      $selected[] = $candidate;
      $seenIds[$id] = TRUE;
      if ($category !== '') {
        $seenCategories[$category] = TRUE;
      }

      if (count($selected) >= 3) {
        break;
      }
    }

    return $selected;
  }

  /**
   * Detects contradictory action pairs.
   *
   * @param array<string, mixed> $candidate
   * @param list<array<string, mixed>> $selected
   */
  private function contradictsExisting(array $candidate, array $selected): bool {
    $id = (string) ($candidate['id'] ?? '');
    foreach ($selected as $action) {
      $existingId = (string) ($action['id'] ?? '');
      if ($existingId === '') {
        continue;
      }

      if (($id === 'extend_boost' || $id === 'extend_homepage_boost') && $existingId === 'start_boost') {
        return TRUE;
      }
      if ($id === 'start_boost' && ($existingId === 'extend_boost' || $existingId === 'extend_homepage_boost')) {
        return TRUE;
      }

      if (($id === 'improve_event_page' || $id === 'improve_rsvp_page')
        && $existingId === 'improve_search_appearance') {
        continue;
      }

      if ($id === 'extend_boost' && $existingId === 'extend_homepage_boost') {
        return TRUE;
      }
      if ($id === 'extend_homepage_boost' && $existingId === 'extend_boost') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * @return array<string, mixed>
   */
  private function candidate(
    string $id,
    string $category,
    int $priority,
    string $title,
    string $description,
    string $cta_label,
    string $cta_url,
  ): array {
    return [
      'id' => $id,
      'category' => $category,
      'priority' => $priority,
      'title' => $title,
      'description' => $description,
      'cta_label' => $cta_label,
      'cta_url' => $cta_url,
    ];
  }

  /**
   * Resolves monetary ROI basis from existing metrics.
   */
  private function resolveRoiRevenue(
    float $ticket_revenue,
    float $donation_revenue,
    bool $isTickets,
    bool $isRsvp,
  ): float {
    if ($isTickets && $ticket_revenue > 0.0) {
      return $ticket_revenue;
    }
    if ($isRsvp && $donation_revenue > 0.0) {
      return $donation_revenue;
    }
    if ($isTickets) {
      return $ticket_revenue;
    }
    return $donation_revenue;
  }

  /**
   * @param array{
   *   show: bool,
   *   cards: list<array<string, mixed>>,
   * } $placement_performance_section
   *
   * @return array<string, mixed>|null
   */
  private function resolvePlacementWinnerCard(array $placement_performance_section): ?array {
    return $this->decisionSupport->resolvePlacementWinnerCard($placement_performance_section);
  }

  /**
   * @param array{
   *   show: bool,
   *   cards: list<array<string, mixed>>,
   * } $placement_performance_section
   *
   * @return array<string, mixed>|null
   */
  private function resolveWeakestPlacementCard(array $placement_performance_section): ?array {
    return $this->decisionSupport->resolveWeakestPlacementCard($placement_performance_section);
  }

  /**
   * Parses a formatted currency string into a float.
   */
  private function parseFormattedAmount(string $formatted): float {
    if ($formatted === '—' || $formatted === '') {
      return 0.0;
    }
    $clean = preg_replace('/[^\d.]/', '', $formatted);
    if ($clean === NULL || $clean === '') {
      return 0.0;
    }
    return (float) $clean;
  }

  /**
   * @return array{show: bool, actions: list<array<string, mixed>>}
   */
  private function emptyPayload(): array {
    return [
      'show' => FALSE,
      'actions' => [],
    ];
  }

}
