<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\node\NodeInterface;

/**
 * Determines contextual Boost extension / restart recommendations (Phase 11C).
 */
final class BoostExtensionRecommendationService {

  use StringTranslationTrait;

  private const PRIORITY_EXPIRING_SOON = 100;

  private const PRIORITY_STRONG_PERFORMANCE = 90;

  private const PRIORITY_RECENTLY_EXPIRED = 80;

  private const PRIORITY_UPCOMING_EVENT = 70;

  private const SECONDS_PER_DAY = 86400;

  public function __construct(
    private readonly BoostStatusService $boostStatusService,
    private readonly BoostManager $boostManager,
    private $performanceLevelService,
    private readonly BoostDecisionSupportService $decisionSupportService,
    private readonly BoostTrendIntelligenceService $trendIntelligenceService,
    private $boostMetricsService,
    private readonly EventStateResolver $eventStateResolver,
    private readonly TimeInterface $time,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Returns a single highest-priority recommendation, or NULL when none apply.
   *
   * @return array{
   *   type: string,
   *   title: string,
   *   body: string,
   *   cta_label: string,
   *   cta_url: string,
   *   priority: int,
   * }|null
   */
  public function getRecommendation(NodeInterface $event): ?array {
    if ($event->bundle() !== 'event') {
      return NULL;
    }

    if ($this->isExcluded($event)) {
      return NULL;
    }

    $now = (int) $this->time->getRequestTime();
    $boostStatus = $this->boostManager->getBoostStatusForEvent($event, $now);
    $visibility = $this->boostStatusService->getVisibilityPayload($event);
    $boostActive = !empty($boostStatus['active']);
    $endTimestamp = isset($boostStatus['end_timestamp']) ? (int) $boostStatus['end_timestamp'] : NULL;
    $eventStart = $this->getEventStartTimestamp($event);
    $eventFuture = $this->isEventFuture($event, $now);
    $eventPublished = $event->isPublished();
    $daysRemaining = $visibility['days_remaining'] ?? NULL;
    $daysUntilStart = $this->daysUntilEventStart($eventStart, $now);
    $daysSinceExpiry = $this->daysSinceBoostExpiry($endTimestamp, $now, $boostActive);

    $candidates = [];

    if ($boostActive
      && $daysRemaining !== NULL
      && (int) $daysRemaining <= 3
      && $eventStart !== NULL
      && $eventStart > $now
      && $eventPublished) {
      $candidates[] = $this->recommendationPayload(
        'extend',
        (string) $this->t('Your Boost ends soon'),
        (string) $this->t('Keep your event visible while registrations are still open.'),
        (string) $this->t('Extend Boost'),
        self::PRIORITY_EXPIRING_SOON,
        $event,
      );
    }

    if ($boostActive && $eventFuture && $this->hasStrongPerformanceLevel($event, $boostStatus)) {
      $candidates[] = $this->recommendationPayload(
        'extend',
        (string) $this->t('Your Boost is performing well'),
        (string) $this->t('Keep the momentum going while people are discovering your event.'),
        (string) $this->t('Extend Boost'),
        self::PRIORITY_STRONG_PERFORMANCE,
        $event,
      );
    }

    if (!$boostActive
      && $daysSinceExpiry !== NULL
      && $daysSinceExpiry <= 7
      && $eventFuture) {
      $candidates[] = $this->recommendationPayload(
        'reboost',
        (string) $this->t('Your Boost has ended'),
        (string) $this->t('Your event is still upcoming. Restart promotion to maintain visibility.'),
        (string) $this->t('Re-Boost Event'),
        self::PRIORITY_RECENTLY_EXPIRED,
        $event,
      );
    }

    if ($daysUntilStart !== NULL
      && $daysUntilStart <= 14
      && $eventFuture
      && !$boostActive
      && $eventPublished) {
      $candidates[] = $this->recommendationPayload(
        'boost',
        (string) $this->t('Increase attendance before your event'),
        (string) $this->t('Events often receive the most attention in the final two weeks.'),
        (string) $this->t('Boost Event'),
        self::PRIORITY_UPCOMING_EVENT,
        $event,
      );
    }

    if ($candidates === []) {
      return NULL;
    }

    usort($candidates, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

    return $candidates[0];
  }

  /**
   * Global exclusions — return NULL before any rule when matched.
   */
  private function isExcluded(NodeInterface $event): bool {
    if (!$event->isPublished()) {
      return TRUE;
    }

    $state = $this->eventStateResolver->resolveState($event);
    if (in_array($state, [EventStateResolver::STATE_ARCHIVED, EventStateResolver::STATE_CANCELLED, EventStateResolver::STATE_ENDED], TRUE)) {
      return TRUE;
    }

    $now = (int) $this->time->getRequestTime();
    if (!$this->isEventFuture($event, $now)) {
      return TRUE;
    }

    $boostStatus = $this->boostManager->getBoostStatusForEvent($event, $now);
    $endTimestamp = isset($boostStatus['end_timestamp']) ? (int) $boostStatus['end_timestamp'] : NULL;
    if ($endTimestamp !== NULL && empty($boostStatus['active'])) {
      $daysSinceExpiry = ($now - $endTimestamp) / self::SECONDS_PER_DAY;
      if ($daysSinceExpiry > 7) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Uses BoostPerformanceLevelService only — no duplicate scoring.
   *
   * @param array<string, mixed> $boostStatus
   */
  private function hasStrongPerformanceLevel(NodeInterface $event, array $boostStatus): bool {
    try {
      $boostMetrics = $this->boostMetricsService->getEventBoostMetrics($event);
      $boost = [
        'active' => !empty($boostStatus['active']),
      ];
      $flags = $this->resolveBookingFlags($event);
      $emptyPlacements = [
        'show' => FALSE,
        'cards' => [],
      ];
      $decisionSupport = $this->decisionSupportService->build(
        $boostMetrics,
        $boost,
        $flags['tickets'],
        $flags['rsvp'],
        $emptyPlacements,
      );
      $trendIntelligence = $this->trendIntelligenceService->analyze(
        $boostMetrics,
        $flags['tickets'],
        $flags['rsvp'],
      );
      $performanceLevel = $this->performanceLevelService->build(
        $decisionSupport,
        $trendIntelligence,
      );
    }
    catch (\Throwable) {
      return FALSE;
    }

    if (empty($performanceLevel['show'])) {
      return FALSE;
    }

    $level = (string) ($performanceLevel['level'] ?? '');
    return in_array($level, ['excellent', 'good'], TRUE);
  }

  /**
   * @return array{tickets: bool, rsvp: bool}
   */
  private function resolveBookingFlags(NodeInterface $event): array {
    $eventType = '';
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $eventType = mb_strtolower(trim((string) $event->get('field_event_type')->value));
    }

    $hasProduct = $event->hasField('field_product_target')
      && !$event->get('field_product_target')->isEmpty();

    $isTickets = $hasProduct || in_array($eventType, ['paid', 'both'], TRUE);
    $isRsvp = in_array($eventType, ['rsvp', 'both'], TRUE);

    return [
      'tickets' => $isTickets,
      'rsvp' => $isRsvp,
    ];
  }

  /**
   * @return array{
   *   type: string,
   *   title: string,
   *   body: string,
   *   cta_label: string,
   *   cta_url: string,
   *   priority: int,
   * }
   */
  private function recommendationPayload(
    string $type,
    string $title,
    string $body,
    string $ctaLabel,
    int $priority,
    NodeInterface $event,
  ): array {
    return [
      'type' => $type,
      'title' => $title,
      'body' => $body,
      'cta_label' => $ctaLabel,
      'cta_url' => $this->buildBoostPageUrl($event),
      'priority' => $priority,
    ];
  }

  private function buildBoostPageUrl(NodeInterface $event): string {
    try {
      return Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $event->id()])->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

  private function getEventStartTimestamp(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return NULL;
    }
    $date = $event->get('field_event_start')->date;
    return $date ? (int) $date->getTimestamp() : NULL;
  }

  private function getEventEndTimestamp(NodeInterface $event): ?int {
    if (!$event->hasField('field_event_end') || $event->get('field_event_end')->isEmpty()) {
      return NULL;
    }
    $date = $event->get('field_event_end')->date;
    return $date ? (int) $date->getTimestamp() : NULL;
  }

  private function isEventFuture(NodeInterface $event, int $now): bool {
    $eventEnd = $this->getEventEndTimestamp($event);
    if ($eventEnd !== NULL) {
      return $eventEnd > $now;
    }

    $eventStart = $this->getEventStartTimestamp($event);
    if ($eventStart !== NULL) {
      return $eventStart > $now;
    }

    return TRUE;
  }

  private function daysUntilEventStart(?int $eventStart, int $now): ?int {
    if ($eventStart === NULL || $eventStart <= $now) {
      return NULL;
    }

    return (int) ceil(($eventStart - $now) / self::SECONDS_PER_DAY);
  }

  private function daysSinceBoostExpiry(?int $endTimestamp, int $now, bool $boostActive): ?int {
    if ($boostActive || $endTimestamp === NULL || $endTimestamp > $now) {
      return NULL;
    }

    return (int) ceil(($now - $endTimestamp) / self::SECONDS_PER_DAY);
  }

}
