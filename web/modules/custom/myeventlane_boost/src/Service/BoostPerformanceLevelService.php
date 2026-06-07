<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Organiser-facing Boost performance level (Phase 9).
 *
 * Composes outputs from BoostDecisionSupportService and
 * BoostTrendIntelligenceService only — no duplicate metric calculations.
 * BoostBenchmarkService readiness adjusts internal confidence only.
 */
final class BoostPerformanceLevelService {

  use StringTranslationTrait;

  private const LEVEL_EXCELLENT = 'excellent';

  private const LEVEL_GOOD = 'good';

  private const LEVEL_AVERAGE = 'average';

  private const LEVEL_NEEDS_ATTENTION = 'needs_attention';

  /**
   * Ordered levels for upgrade/downgrade adjustments.
   *
   * @var list<string>
   */
  private const LEVEL_ORDER = [
    self::LEVEL_NEEDS_ATTENTION,
    self::LEVEL_AVERAGE,
    self::LEVEL_GOOD,
    self::LEVEL_EXCELLENT,
  ];

  /**
   * Constructs BoostPerformanceLevelService.
   */
  public function __construct(TranslationInterface $stringTranslation) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds the performance level card payload.
   *
   * @param array<string, mixed> $decisionSupport
   *   Output from BoostDecisionSupportService::build().
   * @param array<string, mixed> $trendIntelligence
   *   Output from BoostTrendIntelligenceService::analyze().
   * @param bool $benchmarkReady
   *   TRUE when BoostBenchmarkService reports platform readiness.
   *
   * @return array<string, mixed>
   */
  public function build(
    array $decisionSupport,
    array $trendIntelligence,
    bool $benchmarkReady = FALSE,
  ): array {
    $health = is_array($decisionSupport['health'] ?? NULL) ? $decisionSupport['health'] : [];
    if (empty($health['show'])) {
      return $this->hiddenPayload();
    }

    $confidence = is_array($decisionSupport['confidence'] ?? NULL)
      ? $decisionSupport['confidence']
      : [];
    $confidenceLevel = (string) ($confidence['level'] ?? 'low');
    if ($confidenceLevel === 'low') {
      return $this->hiddenPayload();
    }

    $baseScore = (string) ($health['score'] ?? '');
    $level = $this->mapHealthScoreToLevel($baseScore);
    if ($level === NULL) {
      return $this->hiddenPayload();
    }

    $effectiveConfidence = $this->resolveEffectiveConfidence($confidenceLevel, $benchmarkReady);
    $level = $this->applyTrendAdjustment($level, $trendIntelligence, $effectiveConfidence);

    return $this->levelPayload($level);
  }

  /**
   * Maps decision-support health score to performance level.
   */
  private function mapHealthScoreToLevel(string $score): ?string {
    return match ($score) {
      self::LEVEL_EXCELLENT => self::LEVEL_EXCELLENT,
      self::LEVEL_GOOD => self::LEVEL_GOOD,
      'fair' => self::LEVEL_AVERAGE,
      self::LEVEL_NEEDS_ATTENTION => self::LEVEL_NEEDS_ATTENTION,
      default => NULL,
    };
  }

  /**
   * Strengthens confidence internally when benchmarks are ready.
   */
  private function resolveEffectiveConfidence(string $confidenceLevel, bool $benchmarkReady): string {
    if ($benchmarkReady && $confidenceLevel === 'medium') {
      return 'high';
    }
    return $confidenceLevel;
  }

  /**
   * Applies one-step trend adjustment using existing trend status only.
   *
   * @param array<string, mixed> $trendIntelligence
   */
  private function applyTrendAdjustment(
    string $level,
    array $trendIntelligence,
    string $effectiveConfidence,
  ): string {
    if (empty($trendIntelligence['show'])) {
      return $level;
    }

    $status = (string) ($trendIntelligence['status'] ?? 'stable');
    if ($status === 'declining') {
      return $this->shiftLevel($level, -1);
    }

    if ($status === 'improving' && $effectiveConfidence === 'high') {
      return $this->shiftLevel($level, 1);
    }

    return $level;
  }

  /**
   * Shifts a level up or down within allowed bounds.
   */
  private function shiftLevel(string $level, int $steps): string {
    $index = array_search($level, self::LEVEL_ORDER, TRUE);
    if ($index === FALSE) {
      return $level;
    }

    $newIndex = max(0, min(count(self::LEVEL_ORDER) - 1, $index + $steps));
    return self::LEVEL_ORDER[$newIndex];
  }

  /**
   * @return array<string, mixed>
   */
  private function levelPayload(string $level): array {
    $labels = [
      self::LEVEL_EXCELLENT => (string) $this->t('Excellent'),
      self::LEVEL_GOOD => (string) $this->t('Good'),
      self::LEVEL_AVERAGE => (string) $this->t('Average'),
      self::LEVEL_NEEDS_ATTENTION => (string) $this->t('Needs Attention'),
    ];
    $descriptions = [
      self::LEVEL_EXCELLENT => (string) $this->t('Your event is attracting strong attention and converting that attention into attendees.'),
      self::LEVEL_GOOD => (string) $this->t('Your event is performing well and gaining steady engagement.'),
      self::LEVEL_AVERAGE => (string) $this->t('Your event is receiving attention but there are opportunities to improve engagement.'),
      self::LEVEL_NEEDS_ATTENTION => (string) $this->t('Your event would benefit from improvements to visibility or conversion.'),
    ];
    $badges = [
      self::LEVEL_EXCELLENT => 'success',
      self::LEVEL_GOOD => 'success',
      self::LEVEL_AVERAGE => 'warning',
      self::LEVEL_NEEDS_ATTENTION => 'danger',
    ];

    return [
      'show' => TRUE,
      'level' => $level,
      'label' => $labels[$level] ?? $labels[self::LEVEL_AVERAGE],
      'description' => $descriptions[$level] ?? $descriptions[self::LEVEL_AVERAGE],
      'badge' => $badges[$level] ?? 'warning',
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function hiddenPayload(): array {
    return [
      'show' => FALSE,
      'level' => '',
      'label' => '',
      'description' => '',
      'badge' => 'muted',
    ];
  }

}
