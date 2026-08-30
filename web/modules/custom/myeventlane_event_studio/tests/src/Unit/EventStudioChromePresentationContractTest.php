<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Protects the presentation-only Event Studio chrome boundary.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioChromePresentationContractTest extends UnitTestCase {

  public function testApprovedChromeIsLoadedFromTheThemeLayer(): void {
    $webRoot = dirname(__DIR__, 6);
    $themeRoot = $webRoot . '/themes/custom/myeventlane_vendor_theme';
    $main = file_get_contents($themeRoot . '/src/scss/main.scss');
    $canvas = file_get_contents($themeRoot . '/src/scss/components/_mel-event-studio-canvas.scss');

    $this->assertIsString($main);
    $this->assertIsString($canvas);
    $this->assertStringContainsString("meta.load-css('components/mel-event-studio-canvas')", $main);
    $this->assertStringContainsString('.mel-event-studio-workspace__main', $canvas);
    $this->assertStringContainsString('.mel-event-studio-section', $canvas);
    $this->assertStringContainsString('.mel-event-studio--home .mel-event-studio-section--flush', $canvas);
    $this->assertStringContainsString('max-width: 64rem', $canvas);
  }

  public function testOperationalMarkupAndAjaxHooksRemainInPlace(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $workspace = file_get_contents($moduleRoot . '/templates/mel-event-studio-workspace.html.twig');
    $topbar = file_get_contents($moduleRoot . '/templates/mel-event-studio-topbar.html.twig');
    $missionControl = file_get_contents($moduleRoot . '/templates/mel-event-studio-mission-control.html.twig');

    $this->assertIsString($workspace);
    $this->assertIsString($topbar);
    $this->assertIsString($missionControl);
    $this->assertStringContainsString('data-mel-studio-shell', $workspace);
    $this->assertStringContainsString('data-mel-section-writable', $workspace);
    $this->assertStringContainsString('mel-event-studio-section.html.twig', $workspace);
    $this->assertStringContainsString('data-mel-publish-action', $topbar);
    $this->assertStringContainsString('data-mel-hero-actions', $topbar);
    $this->assertStringContainsString('data-mel-mission-control', $missionControl);
    $this->assertStringContainsString('data-mel-mc-details', $missionControl);
    $this->assertStringContainsString('data-mel-mc-cta', $missionControl);
  }

}
