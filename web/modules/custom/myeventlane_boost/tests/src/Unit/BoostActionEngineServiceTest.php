<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_boost\Service\BoostActionEngineService;
use Drupal\myeventlane_boost\Service\BoostDecisionSupportService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for Boost action engine (Phase 10).
 *
 * @group myeventlane_boost
 */
final class BoostActionEngineServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private BoostActionEngineService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $translation = $this->createMock(TranslationInterface::class);
    $translation
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    $decisionSupport = new BoostDecisionSupportService($translation);
    $this->service = new BoostActionEngineService($decisionSupport, $translation);
  }

  /**
   * Strong ROI prioritises extend Boost action.
   */
  public function testActionPrioritisationStrongRoi(): void {
    $result = $this->service->build(
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
      '/edit',
      '/boost',
      'https://example.com/event',
    );

    $this->assertTrue($result['show']);
    $this->assertLessThanOrEqual(3, count($result['actions']));
    $this->assertSame('extend_boost', $result['actions'][0]['id']);
    $this->assertSame('Boost Event', $result['actions'][0]['cta_label']);
  }

  /**
   * Low CTR suggests refreshing the hero image.
   */
  public function testLowCtrVisibilityAction(): void {
    $result = $this->service->build(
      [
        'spend' => '$20.00',
        'impressions' => 200,
        'clicks' => 1,
        'ctr' => 0.005,
      ],
      ['active' => TRUE],
      TRUE,
      FALSE,
      ['show' => FALSE, 'cards' => []],
      '/edit',
      NULL,
      NULL,
    );

    $ids = array_column($result['actions'], 'id');
    $this->assertContains('refresh_hero_image', $ids);
  }

  /**
   * Actions are capped at three and deduplicated by id.
   */
  public function testNoDuplicateActions(): void {
    $result = $this->service->build(
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
            'key' => 'homepage_discover',
            'label' => 'Homepage Discover',
            'impressions' => 200,
            'clicks' => 30,
            'ctr' => 0.05,
          ],
        ],
      ],
      '/edit',
      '/boost',
      'https://example.com/event',
    );

    $ids = array_column($result['actions'], 'id');
    $this->assertSame(count($ids), count(array_unique($ids)));
    $this->assertLessThanOrEqual(3, count($ids));
  }

  /**
   * RSVP volume without donations suggests a donation prompt.
   */
  public function testDonationPromptForStrongRsvpVolume(): void {
    $result = $this->service->build(
      [
        'spend' => '$30.00',
        'impressions' => 250,
        'clicks' => 60,
        'ctr' => 0.24,
        'rsvps_during_boost' => 15,
        'donation_revenue_during_boost' => 0.0,
      ],
      ['active' => TRUE],
      FALSE,
      TRUE,
      ['show' => FALSE, 'cards' => []],
      '/edit',
      NULL,
      NULL,
    );

    $ids = array_column($result['actions'], 'id');
    $this->assertContains('add_donation_prompt', $ids);
  }

  /**
   * Hidden when there is no Boost history.
   */
  public function testHiddenWithoutBoostHistory(): void {
    $result = $this->service->build(
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
      '/edit',
      '/boost',
      NULL,
    );

    $this->assertFalse($result['show']);
    $this->assertSame([], $result['actions']);
  }

}
