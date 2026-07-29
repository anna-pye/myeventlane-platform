<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects empty and sparse homepage rail presentation.
 *
 * @group myeventlane_front
 */
final class HomepageSparseRailContractTest extends TestCase {

  public function testLatestRailUsesRealResultsGate(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $themeRoot = $webRoot . '/themes/custom/myeventlane_theme';
    $preprocess = (string) file_get_contents($themeRoot . '/myeventlane_theme.theme');
    $frontPage = (string) file_get_contents($themeRoot . '/templates/page--front.html.twig');

    $this->assertStringContainsString(
      "viewDisplayHasResults('upcoming_events', 'homepage_latest')",
      $preprocess,
    );
    $this->assertStringContainsString(
      '{% if mel_home_show_latest|default(false) %}',
      $frontPage,
    );
    $this->assertStringNotContainsString('{% if page.homepage_latest %}', $frontPage);
  }

  public function testSparseRailsRetainResponsiveLayoutContract(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $themeRoot = $webRoot . '/themes/custom/myeventlane_theme';
    $styles = (string) file_get_contents($themeRoot . '/src/scss/pages/_front-page.scss');
    $carousel = (string) file_get_contents($themeRoot . '/src/js/card-carousel.js');

    $this->assertStringContainsString('&:has(> :only-child)', $styles);
    $this->assertStringContainsString('&:has(> :first-child:nth-last-child(2))', $styles);
    $this->assertStringContainsString('grid-template-columns: 1fr;', $styles);
    $this->assertStringContainsString('centeredInsufficientSlides: true', $carousel);
    $this->assertStringContainsString('watchOverflow: true', $carousel);

    $featured = (string) file_get_contents(
      $themeRoot . '/templates/views/views-view-unformatted--front-featured-events--block-featured.html.twig',
    );
    $this->assertStringContainsString(
      "'mel-card-carousel--spotlight mel-card-carousel--count-' ~ carousel_items|length",
      $featured,
    );
    $this->assertStringContainsString('.mel-card-carousel--count-2 .swiper-wrapper', $styles);
  }

}
