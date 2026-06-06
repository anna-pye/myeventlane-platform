<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_boost\Service\BoostDecisionSupportService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for Boost decision support rules.
 *
 * @group myeventlane_boost
 */
final class BoostDecisionSupportServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private BoostDecisionSupportService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $translation = $this->createMock(TranslationInterface::class);
    $translation
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    $this->service = new BoostDecisionSupportService($translation);
  }

  /**
   * Excellent health when CTR and revenue exceed thresholds.
   */
  public function testExcellentHealthScore(): void {
    $result = $this->service->build(
      [
        'spend' => '$45.00',
        'impressions' => 500,
        'clicks' => 60,
        'ctr' => 0.12,
        'revenue_during_boost' => 280.0,
        'orders_during_boost' => 8,
        'tickets_during_boost' => 8,
        'sales_during_boost' => '$280.00',
      ],
      ['active' => TRUE],
      TRUE,
      FALSE,
      [
        'show' => TRUE,
        'cards' => [
          [
            'key' => 'homepage_discover',
            'label' => 'Homepage Discover',
            'impressions' => 300,
            'clicks' => 40,
            'ctr' => 0.133,
          ],
        ],
      ],
    );

    $this->assertTrue($result['health']['show']);
    $this->assertSame('excellent', $result['health']['score']);
    $this->assertSame('high', $result['confidence']['level']);
  }

  /**
   * Needs attention when CTR is very low and there are no conversions.
   */
  public function testNeedsAttentionHealthScore(): void {
    $result = $this->service->build(
      [
        'spend' => '$30.00',
        'impressions' => 200,
        'clicks' => 1,
        'ctr' => 0.005,
        'revenue_during_boost' => 0.0,
        'orders_during_boost' => 0,
        'tickets_during_boost' => 0,
      ],
      ['active' => TRUE],
      TRUE,
      FALSE,
      ['show' => FALSE, 'cards' => []],
    );

    $this->assertSame('needs_attention', $result['health']['score']);
    $this->assertSame('medium', $result['confidence']['level']);
  }

  /**
   * Conversion insight is shown when clicks exist.
   */
  public function testConversionInsightForPaidEvent(): void {
    $result = $this->service->build(
      [
        'spend' => '$20.00',
        'impressions' => 120,
        'clicks' => 50,
        'ctr' => 0.416,
        'revenue_during_boost' => 100.0,
        'orders_during_boost' => 9,
        'tickets_during_boost' => 9,
        'sales_during_boost' => '$100.00',
      ],
      ['active' => TRUE],
      TRUE,
      FALSE,
      ['show' => FALSE, 'cards' => []],
    );

    $conversion = array_values(array_filter(
      $result['insights'],
      static fn (array $insight): bool => ($insight['type'] ?? '') === 'conversion',
    ));
    $this->assertCount(1, $conversion);
    $this->assertStringContainsString('18%', (string) $conversion[0]['body']);
  }

  /**
   * ROI insight uses plain language without exposing formulas.
   */
  public function testRoiInsightStrongReturn(): void {
    $result = $this->service->build(
      [
        'spend' => '$45.00',
        'impressions' => 400,
        'clicks' => 30,
        'ctr' => 0.075,
        'revenue_during_boost' => 280.0,
        'orders_during_boost' => 6,
        'sales_during_boost' => '$280.00',
      ],
      ['active' => FALSE],
      TRUE,
      FALSE,
      ['show' => FALSE, 'cards' => []],
    );

    $roi = array_values(array_filter(
      $result['insights'],
      static fn (array $insight): bool => ($insight['type'] ?? '') === 'roi',
    ));
    $this->assertCount(1, $roi);
    $this->assertStringContainsString('$280.00', (string) $roi[0]['lines'][0]);
    $this->assertStringContainsString('6.22', (string) $roi[0]['lines'][1]);
  }

  /**
   * Recommendations are prioritised and capped at three.
   */
  public function testRecommendationsPrioritisedAndUnique(): void {
    $recs = $this->service->buildRecommendations(
      [
        'spend' => '$45.00',
        'impressions' => 500,
        'clicks' => 80,
        'ctr' => 0.002,
        'revenue_during_boost' => 300.0,
        'orders_during_boost' => 1,
      ],
      ['active' => TRUE],
      TRUE,
      FALSE,
      [
        'show' => TRUE,
        'cards' => [
          [
            'key' => 'category_music',
            'label' => 'Music Category',
            'impressions' => 200,
            'clicks' => 30,
            'ctr' => 0.15,
          ],
          [
            'key' => 'search_results',
            'label' => 'Search Results',
            'impressions' => 150,
            'clicks' => 2,
            'ctr' => 0.013,
          ],
        ],
      ],
      '/boost',
    );

    $this->assertLessThanOrEqual(3, count($recs));
    $this->assertSame(count($recs), count(array_unique($recs)));
    $this->assertStringContainsString('extending Boost', (string) $recs[0]);
  }

  /**
   * No Boost scenario suggests considering Boost.
   */
  public function testNoBoostRecommendation(): void {
    $recs = $this->service->buildRecommendations(
      [
        'spend' => '$0.00',
        'impressions' => 0,
        'clicks' => 0,
        'ctr' => 0.0,
      ],
      ['active' => FALSE],
      TRUE,
      FALSE,
      ['show' => FALSE, 'cards' => []],
      '/boost',
    );

    $this->assertContains('Consider Boost to reach more potential attendees.', $recs);
  }

  /**
   * Placement winner is hidden when data is insufficient.
   */
  public function testPlacementWinnerHiddenWithInsufficientData(): void {
    $result = $this->service->build(
      [
        'spend' => '$10.00',
        'impressions' => 15,
        'clicks' => 2,
        'ctr' => 0.133,
      ],
      ['active' => TRUE],
      TRUE,
      FALSE,
      [
        'show' => TRUE,
        'cards' => [
          [
            'key' => 'homepage_discover',
            'label' => 'Homepage Discover',
            'impressions' => 15,
            'clicks' => 2,
            'ctr' => 0.133,
          ],
        ],
      ],
    );

    $placement = array_values(array_filter(
      $result['insights'],
      static fn (array $insight): bool => ($insight['type'] ?? '') === 'placement',
    ));
    $this->assertCount(0, $placement);
    $this->assertSame('low', $result['confidence']['level']);
  }

}
