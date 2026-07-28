<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_analytics\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser Analytics information hierarchy.
 *
 * @group myeventlane_analytics
 */
final class AnalyticsDashboardPresentationContractTest extends TestCase {

  /**
   * Ensures analytics lead with insight and consolidate operational checks.
   */
  public function testAnalyticsTemplateKeepsCompactGuidedHierarchy(): void {
    $template = file_get_contents(dirname(__DIR__, 6) . '/modules/custom/myeventlane_analytics/templates/analytics-dashboard.html.twig');

    self::assertIsString($template);
    self::assertStringContainsString('mel-analytics-hub__priority-grid', $template);
    self::assertStringContainsString("{{ 'Your next step'|t }}", $template);
    self::assertStringContainsString('mel-analytics-hub__readiness-details', $template);
    self::assertStringContainsString("{{ 'Review checks'|t }}", $template);
    self::assertStringContainsString('mel-analytics-hub__tail-grid', $template);
    self::assertStringNotContainsString('<h1 class="mel-analytics-hub__title">', $template);
  }

}
