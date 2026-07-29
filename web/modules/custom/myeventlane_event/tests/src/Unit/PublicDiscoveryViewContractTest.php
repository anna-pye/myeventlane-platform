<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects public discovery filters and sparse-result placement.
 *
 * @group myeventlane_event
 */
final class PublicDiscoveryViewContractTest extends TestCase {

  public function testTicketTypeUsesValidViewsFilterHandler(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $config = (string) file_get_contents($webRoot . '/../config/sync/views.view.upcoming_events.yml');

    $this->assertSame(2, substr_count($config, "field_event_type_value:\n          id: field_event_type_value"));
    $this->assertSame(2, substr_count($config, "field: field_event_type_value"));
    $this->assertStringNotContainsString("field_event_type:\n          id: field_event_type", $config);
    $this->assertStringContainsString('identifier: event_type', $config);
  }

  public function testSparseDiscoveryGridsHaveDeliberateDesktopPlacement(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/pages/_discovery.scss',
    );

    $this->assertStringContainsString(':has(> :only-child)', $styles);
    $this->assertStringContainsString(':has(> :first-child:nth-last-child(2))', $styles);
    $this->assertStringContainsString(':has(> :first-child:nth-last-child(3))', $styles);
    $this->assertStringContainsString('justify-content: center;', $styles);
  }

  public function testBrowseResultGrammarAndResetStateAreExplicit(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $themeRoot = $webRoot . '/themes/custom/myeventlane_theme';
    $preprocess = (string) file_get_contents($themeRoot . '/myeventlane_theme.theme');
    $view = (string) file_get_contents(
      $themeRoot . '/templates/views/views-view--upcoming-events--page-events.html.twig',
    );

    $this->assertStringContainsString('$variables[\'mel_browse_active_filters\'] = FALSE;', $preprocess);
    $this->assertStringContainsString("formatPlural(\n      (int) \$total,\n      '1 event',", $preprocess);
    $this->assertStringContainsString('mel_browse_result_count_label|default', $view);
  }

}
