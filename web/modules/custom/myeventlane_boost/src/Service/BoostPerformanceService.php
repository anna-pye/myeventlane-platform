<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_analytics\Service\AnalyticsDataService;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin façade for boost lifecycle metrics (tickets, revenue, sales velocity).
 *
 * Canonical boost state remains in BoostManager; entitlement windows in
 * BoostEntitlementManager; sales aggregates in AnalyticsDataService::getSalesTimeSeries().
 */
final class BoostPerformanceService {

  use StringTranslationTrait;

  private const PRE_DAYS = 7;

  private const POST_DAYS = 7;

  /**
   * Legacy-only: assume a 7-day boost window when only field_promo_expires exists.
   */
  private const LEGACY_ASSUMED_BOOST_SECONDS = 604800;

  /**
   * Rolling lookback for “opportunity” when no boost entitlement exists (single series call).
   */
  private const OPPORTUNITY_LOOKBACK_DAYS = 30;

  public function __construct(
    private readonly BoostManager $boostManager,
    private readonly BoostEntitlementManager $entitlementManager,
    private readonly BoostMetricsService $boostMetricsService,
    private readonly AnalyticsDataService $analyticsDataService,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly UrlGeneratorInterface $urlGenerator,
    TranslationInterface $stringTranslation,
    private readonly StateInterface $state,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Gets lifecycle performance payload for an event (vendor UI).
   *
   * Single analytics read per request path: one getSalesTimeSeries() range.
   */
  public function getPerformanceForEvent(NodeInterface $event, ?int $now = NULL): array {
    $now = $now ?? $this->time->getRequestTime();
    $empty = $this->emptyPayload();

    if ($event->bundle() !== 'event') {
      return $empty;
    }

    $eventId = (int) $event->id();

    $latest = $this->entitlementManager->getLatestEntitlementForEvent($eventId);
    $startTs = NULL;
    $endTs = NULL;
    $legacyWindow = FALSE;

    if ($latest !== NULL) {
      $startTs = (int) ($latest->get('starts')->value ?? 0);
      $endTs = (int) ($latest->get('ends')->value ?? 0);
    }
    else {
      $expiresValue = $event->get('field_promo_expires')->value ?? NULL;
      if ($expiresValue) {
        try {
          $expires = new \DateTimeImmutable($expiresValue, new \DateTimeZone('UTC'));
          $endTs = $expires->getTimestamp();
          $startTs = $endTs - self::LEGACY_ASSUMED_BOOST_SECONDS;
          $legacyWindow = TRUE;
        }
        catch (\Exception $e) {
          $this->logger->warning('Boost performance: invalid field_promo_expires for event @nid.', [
            '@nid' => (string) $eventId,
          ]);
        }
      }
    }

    if ($startTs === NULL || $endTs === NULL || $startTs <= 0 || $endTs <= 0) {
      return $this->applyDevUiFallback(
        $event,
        $this->buildNoBoostWindowPayload($event, $now, $empty),
      );
    }

    return $this->applyDevUiFallback(
      $event,
      $this->buildBoostWindowPayload($event, $now, $startTs, $endTs, $legacyWindow),
    );
  }

  /**
   * Events with no entitlement / legacy window: opportunity loop + pricing (single time series).
   */
  private function buildNoBoostWindowPayload(NodeInterface $event, int $now, array $empty): array {
    if (!$event->isPublished()) {
      $empty['lifecycle_state'] = 'none';
      return $empty;
    }

    $endEvent = $this->getEventEndTimestamp($event);
    if ($endEvent !== NULL && $now > $endEvent) {
      $empty['lifecycle_state'] = 'none';
      return $empty;
    }

    $eventId = (int) $event->id();
    $rangeStart = $now - self::OPPORTUNITY_LOOKBACK_DAYS * 86400;
    $series = [];
    try {
      $series = $this->analyticsDataService->getSalesTimeSeries($eventId, 'day', $rangeStart, $now);
    }
    catch (\Throwable $e) {
      $this->logger->error('Boost performance: opportunity getSalesTimeSeries failed for event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
      return $empty;
    }

    $tickets30 = $this->sumSeriesTickets($series);
    $tickets7 = $this->sumSeriesTicketsLastDays($series, 7, $now);
    $days7 = 7.0;
    $salesVelocity7 = $tickets7 / $days7;

    $metrics = [
      'tickets_before' => 0,
      'tickets_during' => 0,
      'tickets_after' => 0,
      'revenue_before' => 0.0,
      'revenue_during' => 0.0,
      'revenue_after' => 0.0,
      'sales_velocity_before' => 0.0,
      'sales_velocity_during' => 0.0,
      'sales_velocity_after' => $salesVelocity7,
      'uplift_pct' => NULL,
    ];

    $confidence = $this->confidenceFromTicketTotal($tickets30);
    $windowsComplete = TRUE;

    $boostMetricsSummary = $this->safeBoostMetrics($event);

    $highAttentionProxy = $tickets30 >= 10
      || $this->boostMetricImpressions($boostMetricsSummary) >= 100
      || $this->boostMetricClicks($boostMetricsSummary) >= 25;

    $lowVelocity = $salesVelocity7 < 1.0;
    $isOpportunity = $confidence !== 'low'
      && $highAttentionProxy
      && $lowVelocity
      && $tickets30 >= 5;

    $actionState = 'do_nothing';
    $pricing = $this->buildPricingRecommendation(
      opportunity: $isOpportunity,
      lifecycleState: 'none',
      salesVelocityBefore: 0.0,
      salesVelocityAfter: $salesVelocity7,
      meaningfulUplift: FALSE,
      boostEnded: FALSE,
    );

    if (!$isOpportunity) {
      $empty['lifecycle_state'] = 'none';
      $empty['metrics'] = $metrics;
      $empty['boost_metrics_summary'] = $boostMetricsSummary;
      $empty['action_state'] = $actionState;
      $empty['confidence_level'] = $confidence;
      $empty['windows_complete'] = $windowsComplete;
      $empty['pricing_recommendation'] = $pricing;
      $empty['ui'] = $this->emptyUi();
      return $empty;
    }

    $actionState = 'boost_again';
    $urls = $this->safeUrls($eventId);

    $ui = [
      'show' => TRUE,
      'variant' => 'opportunity',
      'emoji' => '👀',
      'title' => (string) $this->t('Your event is getting attention'),
      'intro' => (string) $this->t('People are viewing your event but not booking.'),
      'tips' => [
        (string) $this->t('Adjust ticket pricing'),
        (string) $this->t('Add urgency (limited tickets)'),
        (string) $this->t('Improve event description'),
      ],
      'cta' => [
        'url' => $urls['boost'],
        'label' => (string) $this->t('Boost this event'),
        'classes' => 'mel-btn mel-btn--sm mel-btn--cta',
      ],
      'headline_percent' => NULL,
    ];

    return [
      'lifecycle_state' => 'none',
      'boost_start' => NULL,
      'boost_end' => NULL,
      'legacy_window' => FALSE,
      'metrics' => $metrics,
      'boost_metrics_summary' => $boostMetricsSummary,
      'action_state' => $actionState,
      'confidence_level' => $confidence,
      'windows_complete' => $windowsComplete,
      'pricing_recommendation' => $pricing,
      'ui' => $ui,
    ];
  }

  /**
   * Events with a resolved boost window (single getSalesTimeSeries range).
   */
  private function buildBoostWindowPayload(
    NodeInterface $event,
    int $now,
    int $startTs,
    int $endTs,
    bool $legacyWindow,
  ): array {
    $eventId = (int) $event->id();
    $status = $this->boostManager->getBoostStatusForEvent($event, $now);

    $beforeStart = $startTs - self::PRE_DAYS * 86400;
    $beforeEnd = $startTs - 1;
    $duringStart = $startTs;
    $duringEnd = $endTs;
    $afterStart = $endTs + 1;
    $afterEnd = $endTs + self::POST_DAYS * 86400;

    $rangeStart = $beforeStart;
    $rangeEnd = $afterEnd;

    $series = [];
    try {
      $series = $this->analyticsDataService->getSalesTimeSeries($eventId, 'day', $rangeStart, $rangeEnd);
    }
    catch (\Throwable $e) {
      $this->logger->error('Boost performance: getSalesTimeSeries failed for event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
      return $this->emptyPayload();
    }

    $before = $this->sumSeriesWindow($series, $beforeStart, $beforeEnd);
    $during = $this->sumSeriesWindow($series, $duringStart, $duringEnd);
    $after = $this->sumSeriesWindow($series, $afterStart, $afterEnd);

    $daysBefore = $this->windowDayCount($beforeStart, $beforeEnd);
    $daysDuring = $this->windowDayCount($duringStart, $duringEnd);
    $daysAfter = $this->windowDayCount($afterStart, $afterEnd);

    $svBefore = $before['tickets'] / $daysBefore;
    $svDuring = $during['tickets'] / $daysDuring;
    $svAfter = $after['tickets'] / $daysAfter;

    $upliftPct = NULL;
    if ($svBefore > 0.0) {
      $upliftPct = ($svAfter - $svBefore) / $svBefore * 100.0;
    }

    $metrics = [
      'tickets_before' => $before['tickets'],
      'tickets_during' => $during['tickets'],
      'tickets_after' => $after['tickets'],
      'revenue_before' => $before['revenue'],
      'revenue_during' => $during['revenue'],
      'revenue_after' => $after['revenue'],
      'sales_velocity_before' => $svBefore,
      'sales_velocity_during' => $svDuring,
      'sales_velocity_after' => $svAfter,
      'uplift_pct' => $upliftPct,
    ];

    $boostMetricsSummary = $this->safeBoostMetrics($event);

    $postWindowComplete = $now >= ($endTs + self::POST_DAYS * 86400);
    $boostEnded = $now >= $endTs;

    $meaningfulUplift = FALSE;
    if ($svBefore > 0.0 && $upliftPct !== NULL && $upliftPct >= 10.0) {
      $meaningfulUplift = TRUE;
    }
    if ($after['tickets'] > $before['tickets']) {
      $meaningfulUplift = TRUE;
    }
    if ($after['revenue'] > $before['revenue']) {
      $meaningfulUplift = TRUE;
    }

    $completed = $boostEnded
      && $postWindowComplete
      && $svAfter > $svBefore
      && $meaningfulUplift;

    $activeUi = $status['active'] || ($now < $endTs);

    $lifecycleState = 'none';
    if ($activeUi) {
      $lifecycleState = 'active';
    }
    elseif ($completed) {
      $lifecycleState = 'completed';
    }
    elseif ($boostEnded) {
      $lifecycleState = 'expired';
    }

    $ticketTotal = $before['tickets'] + $during['tickets'] + $after['tickets'];
    $windowsComplete = $postWindowComplete || $activeUi;
    $confidence = $this->confidenceFromBoostWindows($ticketTotal, $windowsComplete, $postWindowComplete, $boostEnded);

    $workedForUi = $boostEnded && $postWindowComplete && $meaningfulUplift;
    $failedForUi = $boostEnded && $postWindowComplete && !$meaningfulUplift;

    $actionState = $this->resolveActionState(
      $lifecycleState,
      $confidence,
      $meaningfulUplift,
      $completed,
      $workedForUi,
      $failedForUi,
      $activeUi,
    );

    $pricing = $this->buildPricingRecommendation(
      opportunity: FALSE,
      lifecycleState: $lifecycleState,
      salesVelocityBefore: $svBefore,
      salesVelocityAfter: $svAfter,
      meaningfulUplift: $meaningfulUplift,
      boostEnded: $boostEnded,
    );

    $urls = $this->safeUrls($eventId);
    $ui = $this->buildBoostWindowUi(
      $lifecycleState,
      $confidence,
      $activeUi,
      $workedForUi,
      $failedForUi,
      $postWindowComplete,
      $boostEnded,
      $upliftPct,
      $metrics,
      $urls,
    );

    return [
      'lifecycle_state' => $lifecycleState,
      'boost_start' => $startTs,
      'boost_end' => $endTs,
      'legacy_window' => $legacyWindow,
      'metrics' => $metrics,
      'boost_metrics_summary' => $boostMetricsSummary,
      'action_state' => $actionState,
      'confidence_level' => $confidence,
      'windows_complete' => $windowsComplete,
      'pricing_recommendation' => $pricing,
      'ui' => $ui,
    ];
  }

  /**
   * @param array<string, mixed> $metrics
   * @param array{boost: string, analytics: string, overview: string, edit: string} $urls
   *
   * @phpstan-return array<string, mixed>
   */
  private function buildBoostWindowUi(
    string $lifecycleState,
    string $confidence,
    bool $activeUi,
    bool $workedForUi,
    bool $failedForUi,
    bool $postWindowComplete,
    bool $boostEnded,
    ?float $upliftPct,
    array $metrics,
    array $urls,
  ): array {
    if ($activeUi) {
      return [
        'show' => TRUE,
        'variant' => 'active_boost',
        'emoji' => '🚀',
        'title' => (string) $this->t('Your event is boosted'),
        'intro' => (string) $this->t('You’re getting extra visibility right now.'),
        'tips' => [],
        'cta' => [
          // Event analytics is Pro-gated; overview is available to all vendor console users.
          'url' => $urls['overview'] !== '' ? $urls['overview'] : $urls['analytics'],
          'label' => (string) $this->t('View performance'),
          'classes' => 'mel-btn mel-btn--sm mel-btn--primary',
        ],
        'headline_percent' => NULL,
      ];
    }

    if ($boostEnded && !$postWindowComplete) {
      return $this->emptyUi();
    }

    if ($lifecycleState === 'completed' || $workedForUi) {
      $pct = $this->formatWorkedPercent($upliftPct, $metrics);
      return [
        'show' => TRUE,
        'variant' => 'boost_worked',
        'emoji' => '🔥',
        'title' => (string) $this->t('Your boost worked'),
        'intro' => $pct > 0
          ? (string) $this->t('Bookings increased by @pct%. Keep momentum going while your event is active.', ['@pct' => $pct])
          : (string) $this->t('Keep momentum going while your event is active.'),
        'tips' => [],
        'cta' => [
          'url' => $urls['boost'],
          'label' => (string) $this->t('Boost again'),
          'classes' => 'mel-btn mel-btn--sm mel-btn--cta',
        ],
        'headline_percent' => $pct > 0 ? (string) $pct : NULL,
      ];
    }

    if ($failedForUi) {
      return [
        'show' => TRUE,
        'variant' => 'boost_failed',
        'emoji' => '⚠️',
        'title' => (string) $this->t('Your boost didn’t convert'),
        'intro' => (string) $this->t('Your event got visibility but bookings didn’t increase.'),
        'tips' => [
          (string) $this->t('Improve event description'),
          (string) $this->t('Rework pricing'),
          (string) $this->t('Add scarcity'),
        ],
        'cta' => [
          'url' => $urls['edit'],
          'label' => (string) $this->t('Improve my event'),
          'classes' => 'mel-btn mel-btn--sm mel-btn--primary',
        ],
        'headline_percent' => NULL,
      ];
    }

    if ($confidence === 'low') {
      return $this->emptyUi();
    }

    return $this->emptyUi();
  }

  /**
   * @param array{tickets_before: int, tickets_after: int, sales_velocity_before: float, sales_velocity_after: float} $metrics
   */
  private function formatWorkedPercent(?float $upliftPct, array $metrics): int {
    if ($upliftPct !== NULL) {
      $rounded = (int) round($upliftPct);
      if ($rounded > 0) {
        return $rounded;
      }
    }

    $tb = (int) ($metrics['tickets_before'] ?? 0);
    $ta = (int) ($metrics['tickets_after'] ?? 0);
    $delta = $ta - $tb;

    if ($tb <= 0 && $ta > 0) {
      return 100;
    }

    if ($delta > 0) {
      return $delta;
    }

    return 0;
  }

  /**
   * @phpstan-return 'boost_again'|'fix_event'|'do_nothing'
   */
  private function resolveActionState(
    string $lifecycleState,
    string $confidence,
    bool $meaningfulUplift,
    bool $completedLifecycle,
    bool $workedForUi,
    bool $failedForUi,
    bool $activeUi,
  ): string {
    if ($activeUi) {
      return 'do_nothing';
    }
    if ($confidence === 'low') {
      return 'do_nothing';
    }
    if ($failedForUi) {
      return 'fix_event';
    }
    if ($completedLifecycle || $workedForUi) {
      return 'boost_again';
    }
    if ($lifecycleState === 'expired' && !$meaningfulUplift) {
      return 'fix_event';
    }
    return 'do_nothing';
  }

  /**
   * @return array{type: string, message: string}
   */
  private function buildPricingRecommendation(
    bool $opportunity,
    string $lifecycleState,
    float $salesVelocityBefore,
    float $salesVelocityAfter,
    bool $meaningfulUplift,
    bool $boostEnded,
  ): array {
    if ($opportunity) {
      return [
        'type' => 'discount',
        'message' => (string) $this->t('Try a lower entry price or a time-limited offer to improve conversion.'),
      ];
    }

    if ($lifecycleState === 'active') {
      return [
        'type' => 'upsell',
        'message' => (string) $this->t('Strong visibility — consider highlighting premium tiers or add-ons.'),
      ];
    }

    if ($salesVelocityAfter > $salesVelocityBefore * 1.25 && $salesVelocityAfter >= 1.0) {
      return [
        'type' => 'upsell',
        'message' => (string) $this->t('Demand looks healthy — consider a higher anchor price or limited releases.'),
      ];
    }

    if ($salesVelocityAfter < $salesVelocityBefore * 0.85 || ($boostEnded && !$meaningfulUplift)) {
      return [
        'type' => 'discount',
        'message' => (string) $this->t('Sales pace softened — test a lower entry price or bundled value.'),
      ];
    }

    if ($meaningfulUplift && $lifecycleState === 'completed') {
      return [
        'type' => 'upsell',
        'message' => (string) $this->t('Great run — scale reach with another boost while momentum is high.'),
      ];
    }

    return [
      'type' => 'standard',
      'message' => '',
    ];
  }

  private function confidenceFromTicketTotal(int $tickets): string {
    if ($tickets < 5) {
      return 'low';
    }
    if ($tickets <= 20) {
      return 'medium';
    }
    return 'high';
  }

  /**
   * @phpstan-return 'low'|'medium'|'high'
   */
  private function confidenceFromBoostWindows(
    int $ticketTotal,
    bool $windowsComplete,
    bool $postWindowComplete,
    bool $boostEnded,
  ): string {
    if (!$windowsComplete || ($boostEnded && !$postWindowComplete)) {
      return 'low';
    }
    return $this->confidenceFromTicketTotal($ticketTotal);
  }

  /**
   * @param array<int, array{date: string, ticket_count: int, revenue: float}> $series
   *
   * @phpstan-return array{tickets: int, revenue: float}
   */
  private function sumSeriesWindow(array $series, int $windowStart, int $windowEnd): array {
    $tickets = 0;
    $revenue = 0.0;
    $startDay = date('Y-m-d', $windowStart);
    $endDay = date('Y-m-d', $windowEnd);

    foreach ($series as $point) {
      $date = $point['date'] ?? '';
      if ($date === '' || $date < $startDay || $date > $endDay) {
        continue;
      }
      $tickets += (int) ($point['ticket_count'] ?? 0);
      $revenue += (float) ($point['revenue'] ?? 0.0);
    }

    return [
      'tickets' => $tickets,
      'revenue' => $revenue,
    ];
  }

  /**
   * @param array<int, array{date: string, ticket_count: int, revenue: float}> $series
   */
  private function sumSeriesTickets(array $series): int {
    $n = 0;
    foreach ($series as $point) {
      $n += (int) ($point['ticket_count'] ?? 0);
    }
    return $n;
  }

  /**
   * @param array<int, array{date: string, ticket_count: int, revenue: float}> $series
   */
  private function sumSeriesTicketsLastDays(array $series, int $days, int $now): int {
    $cutoff = date('Y-m-d', $now - ($days - 1) * 86400);
    $today = date('Y-m-d', $now);
    $n = 0;
    foreach ($series as $point) {
      $date = $point['date'] ?? '';
      if ($date === '' || $date < $cutoff || $date > $today) {
        continue;
      }
      $n += (int) ($point['ticket_count'] ?? 0);
    }
    return $n;
  }

  private function windowDayCount(int $windowStart, int $windowEnd): float {
    if ($windowEnd < $windowStart) {
      return 1.0;
    }
    return max(1.0, (float) ceil(($windowEnd - $windowStart + 1) / 86400));
  }

  private function getEventEndTimestamp(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_end') || $event->get('field_event_end')->isEmpty()) {
      return NULL;
    }
    $item = $event->get('field_event_end')->first();
    if ($item === NULL) {
      return NULL;
    }
    $value = $item->value ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    try {
      $d = new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
      return $d->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function safeBoostMetrics(NodeInterface $event): array {
    try {
      return $this->boostMetricsService->getEventBoostMetrics($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Boost performance: getEventBoostMetrics failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * @param array<string, mixed> $summary
   */
  private function boostMetricImpressions(array $summary): int {
    return (int) ($summary['impressions'] ?? 0);
  }

  /**
   * @param array<string, mixed> $summary
   */
  private function boostMetricClicks(array $summary): int {
    return (int) ($summary['clicks'] ?? 0);
  }

  /**
   * @phpstan-return array{boost: string, analytics: string, overview: string, edit: string}
   */
  private function safeUrls(int $eventId): array {
    $out = [
      'boost' => '',
      'analytics' => '',
      'overview' => '',
      'edit' => '',
    ];
    if ($eventId <= 0) {
      return $out;
    }
    try {
      $out['boost'] = $this->urlGenerator->generateFromRoute('myeventlane_boost.vendor_event_boost', ['event' => $eventId]);
      $out['analytics'] = $this->urlGenerator->generateFromRoute('myeventlane_vendor.console.event_analytics', ['event' => $eventId]);
      $out['overview'] = $this->urlGenerator->generateFromRoute('myeventlane_event_studio.workspace', ['node' => $eventId]);
      $out['edit'] = $this->urlGenerator->generateFromRoute('myeventlane_event_studio.edit', ['node' => $eventId]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Boost performance: URL generation failed for event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
    }
    return $out;
  }

  /**
   * Whether dev/staging UI fallback is enabled.
   */
  private function isDevUiFallbackEnabled(): bool {
    return (bool) $this->state->get('mel.dev_mode', FALSE);
  }

  /**
   * Surfaces Boost UI when canonical logic hides it; no new metrics or SQL.
   *
   * @param array<string, mixed> $payload
   *   Full performance payload from buildNoBoostWindowPayload / buildBoostWindowPayload.
   *
   * @return array<string, mixed>
   */
  private function applyDevUiFallback(NodeInterface $event, array $payload): array {
    if (!$this->isDevUiFallbackEnabled()) {
      return $payload;
    }
    $ui = $payload['ui'] ?? [];
    if (!is_array($ui)) {
      $ui = [];
    }
    if (!empty($ui['show'])) {
      return $payload;
    }
    $eventId = (int) $event->id();
    $urls = $this->safeUrls($eventId);
    $cta = $ui['cta'] ?? [];
    if (!is_array($cta)) {
      $cta = [];
    }
    $ui['show'] = TRUE;
    if (empty($ui['title'])) {
      $ui['title'] = (string) $this->t('Boost your event visibility');
    }
    if (empty($ui['intro'])) {
      $ui['intro'] = (string) $this->t('This event could benefit from promotion.');
    }
    $tips = $ui['tips'] ?? [];
    if (!is_array($tips) || $tips === []) {
      $ui['tips'] = [
        (string) $this->t('Increase visibility with Boost'),
        (string) $this->t('Reach more attendees'),
      ];
    }
    if (empty($cta['label'])) {
      $cta['label'] = (string) $this->t('Boost this event');
    }
    if (empty($cta['url'])) {
      $cta['url'] = $urls['boost'] !== '' ? $urls['boost'] : NULL;
    }
    if (empty($cta['classes'])) {
      $cta['classes'] = 'mel-btn mel-btn--sm mel-btn--cta';
    }
    $ui['cta'] = $cta;
    $payload['ui'] = $ui;
    return $payload;
  }

  /**
   * @phpstan-return array<string, mixed>
   */
  private function emptyPayload(): array {
    return [
      'lifecycle_state' => 'none',
      'boost_start' => NULL,
      'boost_end' => NULL,
      'legacy_window' => FALSE,
      'metrics' => [
        'tickets_before' => 0,
        'tickets_during' => 0,
        'tickets_after' => 0,
        'revenue_before' => 0.0,
        'revenue_during' => 0.0,
        'revenue_after' => 0.0,
        'sales_velocity_before' => 0.0,
        'sales_velocity_during' => 0.0,
        'sales_velocity_after' => 0.0,
        'uplift_pct' => NULL,
      ],
      'boost_metrics_summary' => [],
      'action_state' => 'do_nothing',
      'confidence_level' => 'low',
      'windows_complete' => FALSE,
      'pricing_recommendation' => [
        'type' => 'standard',
        'message' => '',
      ],
      'ui' => $this->emptyUi(),
    ];
  }

  /**
   * @phpstan-return array<string, mixed>
   */
  private function emptyUi(): array {
    return [
      'show' => FALSE,
      'variant' => '',
      'emoji' => '',
      'title' => '',
      'intro' => '',
      'tips' => [],
      'cta' => [
        'url' => NULL,
        'label' => '',
        'classes' => 'mel-btn mel-btn--sm mel-btn--primary',
      ],
      'headline_percent' => NULL,
    ];
  }

}
