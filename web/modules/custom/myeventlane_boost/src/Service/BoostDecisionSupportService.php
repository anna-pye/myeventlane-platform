<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Transforms Boost metrics into organiser-facing decision support.
 *
 * Consumes output from BoostMetricsService only — no new queries or calculations
 * of underlying metrics.
 */
final class BoostDecisionSupportService {

  use StringTranslationTrait;

  /**
   * Constructs BoostDecisionSupportService.
   */
  public function __construct(TranslationInterface $stringTranslation) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds decision support for the event analytics Boost panel.
   *
   * @param array<string, mixed> $boost_metrics
   *   Output from BoostMetricsService::getEventBoostMetrics().
   * @param array<string, mixed> $boost
   *   Boost status from MetricsAggregator (expects 'active' key).
   * @param array{
   *   show: bool,
   *   cards: list<array<string, mixed>>,
   * } $placement_performance_section
   *   Pre-built placement cards from the analytics controller.
   * @param string|null $boost_page_url
   *   URL to the Boost purchase page, if available.
   *
   * @return array<string, mixed>
   *   Decision support payload for Twig.
   */
  public function build(
    array $boost_metrics,
    array $boost,
    bool $isTickets,
    bool $isRsvp,
    array $placement_performance_section,
    ?string $boost_page_url = NULL,
  ): array {
    $impressions = (int) ($boost_metrics['impressions'] ?? 0);
    $clicks = (int) ($boost_metrics['clicks'] ?? 0);
    $ctr = (float) ($boost_metrics['ctr'] ?? 0.0);
    $spend = $this->parseFormattedAmount((string) ($boost_metrics['spend'] ?? '$0.00'));
    $revenue = (float) ($boost_metrics['revenue_during_boost'] ?? 0.0);
    $orders = (int) ($boost_metrics['orders_during_boost'] ?? 0);
    $tickets = (int) ($boost_metrics['tickets_during_boost'] ?? 0);
    $rsvps = (int) ($boost_metrics['rsvps_during_boost'] ?? 0);
    $donation_revenue = (float) ($boost_metrics['donation_revenue_during_boost'] ?? 0.0);
    $boost_active = !empty($boost['active']);
    $has_boost_history = $spend > 0.0 || $impressions > 0 || $clicks > 0 || $boost_active;

    $confidence = $this->buildConfidence($impressions, $clicks, $has_boost_history);
    $health = $this->buildHealthScore(
      $ctr,
      $impressions,
      $spend,
      $revenue,
      $donation_revenue,
      $orders,
      $rsvps,
      $isTickets,
      $isRsvp,
      $has_boost_history,
      $placement_performance_section,
    );
    $placement_winner = $this->buildPlacementWinner(
      $placement_performance_section,
      $tickets,
      $orders,
      $rsvps,
      $isTickets,
      $isRsvp,
    );
    $conversion_insights = $this->buildConversionInsights(
      $clicks,
      $orders,
      $rsvps,
      $isTickets,
      $isRsvp,
    );
    $roi_insight = $this->buildRoiInsight(
      $spend,
      $revenue,
      $donation_revenue,
      $isTickets,
      $isRsvp,
      (string) ($boost_metrics['sales_during_boost'] ?? '$0.00'),
      (string) ($boost_metrics['donation_revenue_during_boost_formatted'] ?? '$0.00'),
    );

    $insights = [];
    if ($placement_winner['show']) {
      $insights[] = [
        'type' => 'placement',
        'title' => (string) $this->t('Best placement'),
        'body' => $placement_winner['label'],
        'detail' => $placement_winner['reason'],
      ];
    }
    foreach ($conversion_insights as $insight) {
      $insights[] = $insight;
    }
    if ($roi_insight['show']) {
      $insights[] = [
        'type' => 'roi',
        'title' => (string) $this->t('Return on Boost'),
        'lines' => $roi_insight['lines'],
      ];
    }

    $recommendations = $this->buildRecommendations(
      $boost_metrics,
      $boost,
      $isTickets,
      $isRsvp,
      $placement_performance_section,
      $boost_page_url,
      $ctr,
      $impressions,
      $clicks,
      $spend,
      $revenue,
      $orders,
      $rsvps,
      $donation_revenue,
    );

    $show = $health['show'] || $insights !== [] || ($confidence['show'] && !$health['show']);

    return [
      'show' => $show,
      'confidence' => $confidence,
      'health' => $health,
      'insights' => $insights,
      'recommendations' => $recommendations,
    ];
  }

  /**
   * Builds prioritised Boost recommendations (max 3, no duplicates).
   *
   * @return list<string>
   */
  public function buildRecommendations(
    array $boost_metrics,
    array $boost,
    bool $isTickets,
    bool $isRsvp,
    array $placement_performance_section,
    ?string $boost_page_url,
    ?float $ctr = NULL,
    ?int $impressions = NULL,
    ?int $clicks = NULL,
    ?float $spend = NULL,
    ?float $revenue = NULL,
    ?int $orders = NULL,
    ?int $rsvps = NULL,
    ?float $donation_revenue = NULL,
  ): array {
    $ctr = $ctr ?? (float) ($boost_metrics['ctr'] ?? 0.0);
    $impressions = $impressions ?? (int) ($boost_metrics['impressions'] ?? 0);
    $clicks = $clicks ?? (int) ($boost_metrics['clicks'] ?? 0);
    $spend = $spend ?? $this->parseFormattedAmount((string) ($boost_metrics['spend'] ?? '$0.00'));
    $revenue = $revenue ?? (float) ($boost_metrics['revenue_during_boost'] ?? 0.0);
    $orders = $orders ?? (int) ($boost_metrics['orders_during_boost'] ?? 0);
    $rsvps = $rsvps ?? (int) ($boost_metrics['rsvps_during_boost'] ?? 0);
    $donation_revenue = $donation_revenue ?? (float) ($boost_metrics['donation_revenue_during_boost'] ?? 0.0);
    $boost_active = !empty($boost['active']);

    $roi_revenue = $this->resolveRoiRevenue($revenue, $donation_revenue, $isTickets, $isRsvp);
    $candidates = [];

    foreach ($boost_metrics['recommendations'] ?? [] as $metrics_rec) {
      if (!is_string($metrics_rec) || $metrics_rec === '') {
        continue;
      }
      $candidates[] = [
        'priority' => 78,
        'text' => $metrics_rec,
      ];
    }

    if ($spend > 0.0 && $roi_revenue > $spend * 1.5) {
      $candidates[] = [
        'priority' => 100,
        'text' => (string) $this->t('Strong return on Boost spend — consider extending Boost.'),
      ];
    }

    if ($impressions >= 100 && $ctr < 0.01) {
      $candidates[] = [
        'priority' => 95,
        'text' => (string) $this->t('Improve your event hero image to lift click-through rate.'),
      ];
    }

    if ($isTickets && $clicks >= 20 && $orders < max(2, (int) floor($clicks * 0.03))) {
      $candidates[] = [
        'priority' => 90,
        'text' => (string) $this->t('Improve your event description to convert more visitors.'),
      ];
      $candidates[] = [
        'priority' => 85,
        'text' => (string) $this->t('Review ticket pricing — clicks are strong but sales are lagging.'),
      ];
    }

    if ($isRsvp && $clicks >= 20 && $rsvps < max(3, (int) floor($clicks * 0.05))) {
      $candidates[] = [
        'priority' => 90,
        'text' => (string) $this->t('Improve your event description to convert more visitors.'),
      ];
      $candidates[] = [
        'priority' => 82,
        'text' => (string) $this->t('Good click volume but few RSVPs during Boost — sharpen your event page copy.'),
      ];
    }

    $winner = $this->resolvePlacementWinnerCard($placement_performance_section);
    if ($winner !== NULL) {
      $winner_key = (string) ($winner['key'] ?? '');
      $winner_label = (string) ($winner['label'] ?? '');
      if (str_starts_with($winner_key, 'category_') && $winner_label !== '') {
        $candidates[] = [
          'priority' => 75,
          'text' => (string) $this->t('Promote through @placement — it is your strongest category placement.', [
            '@placement' => $winner_label,
          ]),
        ];
      }
      elseif ((str_starts_with($winner_key, 'homepage_') || $winner_key === 'homepage_discover')
        && ($winner['ctr'] ?? 0.0) >= 0.03) {
        $candidates[] = [
          'priority' => 72,
          'text' => (string) $this->t('@placement is performing well — consider extending Boost.', [
            '@placement' => $winner_label,
          ]),
        ];
      }
    }

    $weakest = $this->resolveWeakestPlacementCard($placement_performance_section);
    if ($weakest !== NULL) {
      $weakest_key = (string) ($weakest['key'] ?? '');
      $weakest_label = (string) ($weakest['label'] ?? '');
      if ($weakest_key === 'search_results') {
        $candidates[] = [
          'priority' => 65,
          'text' => (string) $this->t('Search Results has your weakest CTR — improve your event title and keywords.'),
        ];
      }
      elseif (str_starts_with($weakest_key, 'search_') && $weakest_label !== '') {
        $candidates[] = [
          'priority' => 63,
          'text' => (string) $this->t('@placement has your weakest CTR — review how your event appears in search.', [
            '@placement' => $weakest_label,
          ]),
        ];
      }
    }

    if (!$boost_active && $spend <= 0.0 && $boost_page_url !== NULL && $boost_page_url !== '') {
      $candidates[] = [
        'priority' => 55,
        'text' => (string) $this->t('Consider Boost to reach more potential attendees.'),
      ];
    }

    usort($candidates, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

    $unique = [];
    $seen = [];
    foreach ($candidates as $candidate) {
      $text = (string) ($candidate['text'] ?? '');
      if ($text === '' || isset($seen[$text])) {
        continue;
      }
      $seen[$text] = TRUE;
      $unique[] = $text;
      if (count($unique) >= 3) {
        break;
      }
    }

    return $unique;
  }

  /**
   * @return array{show: bool, level: string, label: string, badge: string}
   */
  private function buildConfidence(int $impressions, int $clicks, bool $has_boost_history): array {
    if (!$has_boost_history) {
      return [
        'show' => FALSE,
        'level' => 'low',
        'label' => '',
        'badge' => 'muted',
      ];
    }

    if ($impressions < 20) {
      return [
        'show' => TRUE,
        'level' => 'low',
        'label' => (string) $this->t('Low confidence'),
        'badge' => 'danger',
      ];
    }

    if ($impressions >= 100 && $clicks >= 20) {
      return [
        'show' => TRUE,
        'level' => 'high',
        'label' => (string) $this->t('High confidence'),
        'badge' => 'success',
      ];
    }

    return [
      'show' => TRUE,
      'level' => 'medium',
      'label' => (string) $this->t('Medium confidence'),
      'badge' => 'warning',
    ];
  }

  /**
   * @return array{
   *   show: bool,
   *   score: string,
   *   label: string,
   *   explanation: string,
   *   badge: string,
   * }
   */
  private function buildHealthScore(
    float $ctr,
    int $impressions,
    float $spend,
    float $revenue,
    float $donation_revenue,
    int $orders,
    int $rsvps,
    bool $isTickets,
    bool $isRsvp,
    bool $has_boost_history,
    array $placement_performance_section,
  ): array {
    if (!$has_boost_history) {
      return [
        'show' => FALSE,
        'score' => '',
        'label' => '',
        'explanation' => '',
        'badge' => 'muted',
      ];
    }

    $ctr_pct = $ctr * 100.0;
    $has_conversions = ($isTickets && $orders > 0) || ($isRsvp && $rsvps > 0);
    $roi_revenue = $this->resolveRoiRevenue($revenue, $donation_revenue, $isTickets, $isRsvp);
    $revenue_beats_spend = $spend > 0.0 && $roi_revenue > $spend;
    $winner = $this->resolvePlacementWinnerCard($placement_performance_section);

    if ($impressions >= 20 && $ctr_pct < 1.0 && !$has_conversions) {
      return $this->healthResult(
        'needs_attention',
        $this->buildHealthExplanation('needs_attention', $ctr_pct, $winner),
      );
    }

    if ($ctr_pct >= 5.0 && $revenue_beats_spend) {
      return $this->healthResult(
        'excellent',
        $this->buildHealthExplanation('excellent', $ctr_pct, $winner),
      );
    }

    $points = 0;
    if ($ctr_pct >= 5.0) {
      $points += 3;
    }
    elseif ($ctr_pct >= 2.0) {
      $points += 2;
    }
    elseif ($ctr_pct >= 1.0) {
      $points += 1;
    }

    if ($has_conversions) {
      if (($isTickets && $orders >= 5) || ($isRsvp && $rsvps >= 10)) {
        $points += 3;
      }
      else {
        $points += 2;
      }
    }

    if ($revenue_beats_spend) {
      $points += 3;
    }
    elseif ($roi_revenue > 0.0) {
      $points += 1;
    }

    $best_ctr = (float) ($winner['ctr'] ?? 0.0);
    if ($best_ctr >= 0.05) {
      $points += 2;
    }
    elseif ($best_ctr >= 0.02) {
      $points += 1;
    }

    $score = match (TRUE) {
      $points >= 8 => 'excellent',
      $points >= 5 => 'good',
      $points >= 3 => 'fair',
      default => 'needs_attention',
    };

    return $this->healthResult($score, $this->buildHealthExplanation($score, $ctr_pct, $winner));
  }

  /**
   * @return array{
   *   show: bool,
   *   score: string,
   *   label: string,
   *   explanation: string,
   *   badge: string,
   * }
   */
  private function healthResult(string $score, string $explanation): array {
    $labels = [
      'excellent' => (string) $this->t('Excellent'),
      'good' => (string) $this->t('Good'),
      'fair' => (string) $this->t('Fair'),
      'needs_attention' => (string) $this->t('Needs attention'),
    ];
    $badges = [
      'excellent' => 'success',
      'good' => 'success',
      'fair' => 'warning',
      'needs_attention' => 'danger',
    ];

    return [
      'show' => TRUE,
      'score' => $score,
      'label' => $labels[$score] ?? $labels['fair'],
      'explanation' => $explanation,
      'badge' => $badges[$score] ?? 'warning',
    ];
  }

  /**
   * @param array<string, mixed>|null $winner
   */
  private function buildHealthExplanation(string $score, float $ctr_pct, ?array $winner): string {
    $winner_label = (string) ($winner['label'] ?? '');
    $winner_ctr = isset($winner['ctr']) ? number_format((float) $winner['ctr'] * 100, 1) . '%' : '';

    return match ($score) {
      'excellent' => $winner_label !== '' && $winner_ctr !== ''
        ? (string) $this->t('Your Boost is performing better than average. @placement is generating the strongest engagement at @ctr CTR.', [
          '@placement' => $winner_label,
          '@ctr' => $winner_ctr,
        ])
        : (string) $this->t('Your Boost is performing better than average with @ctr click-through rate.', [
          '@ctr' => number_format($ctr_pct, 1) . '%',
        ]),
      'good' => $winner_label !== ''
        ? (string) $this->t('Boost is delivering solid results. @placement is your top placement so far.', [
          '@placement' => $winner_label,
        ])
        : (string) $this->t('Boost is delivering solid reach and engagement.'),
      'fair' => (string) $this->t('Boost is building visibility — watch conversions over the next few days.'),
      default => $winner_label !== ''
        ? (string) $this->t('Engagement is below average. Review how your event appears on @placement first.', [
          '@placement' => $winner_label,
        ])
        : (string) $this->t('Engagement is below average — review your hero image and event page copy.'),
    };
  }

  /**
   * @return array{show: bool, label: string, reason: string}
   */
  private function buildPlacementWinner(
    array $placement_performance_section,
    int $tickets,
    int $orders,
    int $rsvps,
    bool $isTickets,
    bool $isRsvp,
  ): array {
    $winner = $this->resolvePlacementWinnerCard($placement_performance_section);
    if ($winner === NULL) {
      return [
        'show' => FALSE,
        'label' => '',
        'reason' => '',
      ];
    }

    $ctr_display = number_format((float) ($winner['ctr'] ?? 0.0) * 100, 1) . '%';
    $clicks = (int) ($winner['clicks'] ?? 0);
    $reason_parts = [(string) $this->t('@ctr CTR', ['@ctr' => $ctr_display])];

    if ($clicks > 0) {
      $reason_parts[] = (string) $this->t('@count clicks', ['@count' => (string) $clicks]);
    }

    if ($isTickets && $tickets > 0) {
      $reason_parts[] = (string) $this->t('@count ticket sales during Boost', ['@count' => (string) $tickets]);
    }
    elseif ($isTickets && $orders > 0) {
      $reason_parts[] = (string) $this->t('@count orders during Boost', ['@count' => (string) $orders]);
    }
    elseif ($isRsvp && $rsvps > 0) {
      $reason_parts[] = (string) $this->t('@count RSVPs during Boost', ['@count' => (string) $rsvps]);
    }

    return [
      'show' => TRUE,
      'label' => (string) ($winner['label'] ?? ''),
      'reason' => implode(' · ', $reason_parts),
    ];
  }

  /**
   * @return list<array{type: string, title: string, body: string}>
   */
  private function buildConversionInsights(
    int $clicks,
    int $orders,
    int $rsvps,
    bool $isTickets,
    bool $isRsvp,
  ): array {
    if ($clicks <= 0) {
      return [];
    }

    $insights = [];
    if ($isTickets) {
      $pct = (int) round(($orders / $clicks) * 100);
      $insights[] = [
        'type' => 'conversion',
        'title' => (string) $this->t('Ticket conversion'),
        'body' => (string) $this->t('@pct% of visitors who clicked your event purchased a ticket.', [
          '@pct' => (string) $pct,
        ]),
      ];
    }
    if ($isRsvp) {
      $pct = (int) round(($rsvps / $clicks) * 100);
      $insights[] = [
        'type' => 'conversion',
        'title' => (string) $this->t('RSVP conversion'),
        'body' => (string) $this->t('@pct% of visitors who clicked your event completed an RSVP.', [
          '@pct' => (string) $pct,
        ]),
      ];
    }

    return $insights;
  }

  /**
   * @return array{show: bool, lines: list<string>}
   */
  private function buildRoiInsight(
    float $spend,
    float $revenue,
    float $donation_revenue,
    bool $isTickets,
    bool $isRsvp,
    string $sales_formatted,
    string $donation_formatted,
  ): array {
    if ($spend <= 0.0) {
      return [
        'show' => FALSE,
        'lines' => [],
      ];
    }

    $roi_revenue = $this->resolveRoiRevenue($revenue, $donation_revenue, $isTickets, $isRsvp);
    $spend_formatted = '$' . number_format($spend, 2, '.', ',');

    if ($roi_revenue <= 0.0) {
      return [
        'show' => TRUE,
        'lines' => [
          (string) $this->t('Boost generated visibility but has not yet recovered its cost.'),
        ],
      ];
    }

    $revenue_display = $isTickets && $revenue > 0.0
      ? $sales_formatted
      : ($donation_revenue > 0.0 ? $donation_formatted : $sales_formatted);

    $lines = [
      (string) $this->t('Boost generated @revenue revenue from @spend spend.', [
        '@revenue' => $revenue_display,
        '@spend' => $spend_formatted,
      ]),
    ];

    if ($roi_revenue > $spend) {
      $lines[] = (string) $this->t('Every $1 spent returned $@ratio.', [
        '@ratio' => number_format($roi_revenue / $spend, 2),
      ]);
    }

    return [
      'show' => TRUE,
      'lines' => $lines,
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
    $cards = is_array($placement_performance_section['cards'] ?? NULL)
      ? $placement_performance_section['cards']
      : [];
    if ($cards === []) {
      return NULL;
    }

    $eligible = array_values(array_filter(
      $cards,
      static fn (array $card): bool => (int) ($card['impressions'] ?? 0) >= 10,
    ));
    if ($eligible === []) {
      return NULL;
    }

    if (count($eligible) < 2) {
      $only = $eligible[0];
      if ((int) ($only['impressions'] ?? 0) < 20) {
        return NULL;
      }
    }

    $max_clicks = max(array_map(static fn (array $card): int => (int) ($card['clicks'] ?? 0), $eligible));
    usort($eligible, static function (array $a, array $b) use ($max_clicks): int {
      $score_a = ((float) ($a['ctr'] ?? 0.0) * 0.6)
        + (($max_clicks > 0 ? (int) ($a['clicks'] ?? 0) / $max_clicks : 0.0) * 0.4);
      $score_b = ((float) ($b['ctr'] ?? 0.0) * 0.6)
        + (($max_clicks > 0 ? (int) ($b['clicks'] ?? 0) / $max_clicks : 0.0) * 0.4);
      $cmp = $score_b <=> $score_a;
      if ($cmp !== 0) {
        return $cmp;
      }
      return (int) ($b['clicks'] ?? 0) <=> (int) ($a['clicks'] ?? 0);
    });

    return $eligible[0];
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
    $cards = is_array($placement_performance_section['cards'] ?? NULL)
      ? $placement_performance_section['cards']
      : [];
    if (count($cards) < 2) {
      return NULL;
    }

    $eligible = array_values(array_filter(
      $cards,
      static fn (array $card): bool => (int) ($card['impressions'] ?? 0) >= 10,
    ));
    if (count($eligible) < 2) {
      return NULL;
    }

    usort($eligible, static fn (array $a, array $b): int => (float) ($a['ctr'] ?? 0.0) <=> (float) ($b['ctr'] ?? 0.0));
    $weakest = $eligible[0];
    $strongest = $eligible[count($eligible) - 1];
    if (($weakest['key'] ?? '') === ($strongest['key'] ?? '')) {
      return NULL;
    }

    return $weakest;
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

}
