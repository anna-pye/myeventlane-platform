<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_boost\Service\BoostTrendIntelligenceService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for Boost trend intelligence rules.
 *
 * @group myeventlane_boost
 */
final class BoostTrendIntelligenceServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private BoostTrendIntelligenceService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $translation = $this->createMock(TranslationInterface::class);
    $translation
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    $this->service = new BoostTrendIntelligenceService($translation);
  }

  /**
   * Recent half outperforms earlier half → improving.
   */
  public function testImprovingTrend(): void {
    $result = $this->service->analyze(
      $this->metricsWithChart(
        impressions: [10, 10, 10, 30, 30, 30],
        clicks: [1, 1, 1, 4, 4, 4],
        totalImpressions: 240,
        totalClicks: 30,
      ),
      TRUE,
      FALSE,
    );

    $this->assertTrue($result['show']);
    $this->assertSame('improving', $result['status']);
    $this->assertGreaterThan(50, $result['momentum']);
    $this->assertSame('improving', $result['metrics']['impressions']['direction']);
    $this->assertSame('improving', $result['metrics']['clicks']['direction']);
    $this->assertSame('improving', $result['metrics']['ctr']['direction']);
    $this->assertStringContainsString('visibility is increasing', (string) $result['summary']);
  }

  /**
   * Equal halves → stable.
   */
  public function testStableTrend(): void {
    $result = $this->service->analyze(
      $this->metricsWithChart(
        impressions: [20, 20, 20, 20, 20, 20],
        clicks: [2, 2, 2, 2, 2, 2],
        totalImpressions: 240,
        totalClicks: 24,
      ),
      TRUE,
      FALSE,
    );

    $this->assertTrue($result['show']);
    $this->assertSame('stable', $result['status']);
    $this->assertSame(50, $result['momentum']);
    $this->assertStringContainsString('remained stable', (string) $result['summary']);
  }

  /**
   * Recent half underperforms earlier half → declining.
   */
  public function testDecliningTrend(): void {
    $result = $this->service->analyze(
      $this->metricsWithChart(
        impressions: [40, 40, 40, 10, 10, 10],
        clicks: [5, 5, 5, 1, 1, 1],
        totalImpressions: 240,
        totalClicks: 33,
      ),
      TRUE,
      FALSE,
    );

    $this->assertTrue($result['show']);
    $this->assertSame('declining', $result['status']);
    $this->assertLessThan(50, $result['momentum']);
    $this->assertSame('declining', $result['metrics']['impressions']['direction']);
    $this->assertStringContainsString('CTR has declined', (string) $result['summary']);
  }

  /**
   * Missing chart data hides the section.
   */
  public function testInsufficientDataWithoutChart(): void {
    $result = $this->service->analyze(
      [
        'spend' => '$10.00',
        'impressions' => 15,
        'clicks' => 2,
        'ctr' => 0.133,
        'chart_data' => NULL,
      ],
      TRUE,
      FALSE,
    );

    $this->assertFalse($result['show']);
    $this->assertSame('insufficient_data', $result['status']);
  }

  /**
   * Fewer than three daily points hides the section.
   */
  public function testInsufficientDataWithTooFewDailyPoints(): void {
    $result = $this->service->analyze(
      $this->metricsWithChart(
        impressions: [10, 10],
        clicks: [1, 1],
        totalImpressions: 20,
        totalClicks: 2,
      ),
      TRUE,
      FALSE,
    );

    $this->assertFalse($result['show']);
    $this->assertSame('insufficient_data', $result['status']);
  }

  /**
   * Low impression volume across periods hides the section.
   */
  public function testInsufficientDataWithLowImpressionVolume(): void {
    $result = $this->service->analyze(
      $this->metricsWithChart(
        impressions: [2, 2, 2, 3, 3, 3],
        clicks: [0, 0, 1, 0, 0, 1],
        totalImpressions: 15,
        totalClicks: 2,
      ),
      TRUE,
      FALSE,
    );

    $this->assertFalse($result['show']);
    $this->assertSame('insufficient_data', $result['status']);
  }

  /**
   * High sample size yields high confidence.
   */
  public function testHighConfidenceWithStrongSample(): void {
    $result = $this->service->analyze(
      $this->metricsWithChart(
        impressions: [20, 20, 20, 25, 25, 25],
        clicks: [4, 4, 4, 5, 5, 5],
        totalImpressions: 150,
        totalClicks: 27,
      ),
      TRUE,
      FALSE,
    );

    $this->assertTrue($result['show']);
    $this->assertSame('high', $result['confidence']['level']);
  }

  /**
   * Builds chart_data fixture aligned with BoostMetricsService shape.
   *
   * @param list<int> $impressions
   * @param list<int> $clicks
   *
   * @return array<string, mixed>
   */
  private function metricsWithChart(
    array $impressions,
    array $clicks,
    int $totalImpressions,
    int $totalClicks,
  ): array {
    $labels = [];
    foreach (range(1, count($impressions)) as $day) {
      $labels[] = sprintf('2026-01-%02d', $day);
    }

    $ctr = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) : 0.0;

    return [
      'spend' => '$25.00',
      'impressions' => $totalImpressions,
      'clicks' => $totalClicks,
      'ctr' => $ctr,
      'chart_data' => [
        'impressions_vs_clicks' => [
          'labels' => $labels,
          'impressions' => $impressions,
          'clicks' => $clicks,
        ],
        'ctr_by_placement' => [
          'labels' => ['homepage_discover'],
          'data' => [round($ctr * 100, 2)],
        ],
      ],
    ];
  }

}
