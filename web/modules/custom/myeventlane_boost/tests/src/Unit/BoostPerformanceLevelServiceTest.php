<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_boost\Service\BoostActionEngineService;
use Drupal\myeventlane_boost\Service\BoostDecisionSupportService;
use Drupal\myeventlane_boost\Service\BoostPerformanceLevelService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for Boost performance level (Phase 9).
 *
 * @group myeventlane_boost
 */
final class BoostPerformanceLevelServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private BoostPerformanceLevelService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $translation = $this->createMock(TranslationInterface::class);
    $translation
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    $this->service = new BoostPerformanceLevelService($translation);
  }

  /**
   * Excellent level maps from decision-support health score.
   */
  public function testExcellentLevel(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'excellent',
        ],
        'confidence' => [
          'level' => 'high',
        ],
      ],
      ['show' => FALSE],
      FALSE,
    );

    $this->assertTrue($result['show']);
    $this->assertSame('excellent', $result['level']);
    $this->assertSame('Excellent', $result['label']);
    $this->assertStringContainsString('strong attention', (string) $result['description']);
  }

  /**
   * Good level maps from decision-support health score.
   */
  public function testGoodLevel(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'good',
        ],
        'confidence' => [
          'level' => 'medium',
        ],
      ],
      ['show' => FALSE],
      FALSE,
    );

    $this->assertSame('good', $result['level']);
    $this->assertSame('Good', $result['label']);
    $this->assertStringContainsString('performing well', (string) $result['description']);
  }

  /**
   * Fair health score maps to Average performance level.
   */
  public function testAverageLevelFromFairHealth(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'fair',
        ],
        'confidence' => [
          'level' => 'medium',
        ],
      ],
      ['show' => FALSE],
      FALSE,
    );

    $this->assertSame('average', $result['level']);
    $this->assertSame('Average', $result['label']);
    $this->assertStringContainsString('opportunities to improve', (string) $result['description']);
  }

  /**
   * Needs attention level maps from decision-support health score.
   */
  public function testNeedsAttentionLevel(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'needs_attention',
        ],
        'confidence' => [
          'level' => 'medium',
        ],
      ],
      ['show' => FALSE],
      FALSE,
    );

    $this->assertSame('needs_attention', $result['level']);
    $this->assertSame('Needs Attention', $result['label']);
    $this->assertStringContainsString('visibility or conversion', (string) $result['description']);
  }

  /**
   * Hidden when confidence is low.
   */
  public function testHiddenWhenConfidenceLow(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'good',
        ],
        'confidence' => [
          'level' => 'low',
        ],
      ],
      ['show' => FALSE],
      FALSE,
    );

    $this->assertFalse($result['show']);
  }

  /**
   * Declining trend downgrades performance level one step.
   */
  public function testDecliningTrendDowngradesLevel(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'excellent',
        ],
        'confidence' => [
          'level' => 'high',
        ],
      ],
      [
        'show' => TRUE,
        'status' => 'declining',
      ],
      FALSE,
    );

    $this->assertSame('good', $result['level']);
  }

  /**
   * Benchmark readiness strengthens confidence for trend upgrades.
   */
  public function testBenchmarkReadyUpgradesWithImprovingTrend(): void {
    $result = $this->service->build(
      [
        'health' => [
          'show' => TRUE,
          'score' => 'fair',
        ],
        'confidence' => [
          'level' => 'medium',
        ],
      ],
      [
        'show' => TRUE,
        'status' => 'improving',
      ],
      TRUE,
    );

    $this->assertSame('good', $result['level']);
  }

}

