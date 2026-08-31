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

  public function testNestedEventMenuMatchesTheOrganiserToolbarLanguage(): void {
    $webRoot = dirname(__DIR__, 6);
    $moduleRoot = dirname(__DIR__, 3);
    $shellCss = file_get_contents($moduleRoot . '/css/mel-event-studio-shell.css');
    $libraries = file_get_contents($moduleRoot . '/myeventlane_event_studio.libraries.yml');
    $navigation = file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/layout/_navigation.scss',
    );

    $this->assertIsString($shellCss);
    $this->assertStringContainsString(
      '.mel-vendor-console.mel-vendor-shell--studio-focus',
      $shellCss,
    );
    $this->assertStringContainsString('.mel-event-studio-sidebar__heading', $shellCss);
    $this->assertStringContainsString('font-size: 0.75rem;', $shellCss);
    $this->assertStringContainsString('letter-spacing: 0.12em;', $shellCss);
    $this->assertStringContainsString('background: #eef6ff;', $shellCss);
    $this->assertStringContainsString('border-color: #f4c7bd;', $shellCss);
    $this->assertStringContainsString('box-shadow: inset 3px 0 0 #f26d5b;', $shellCss);

    $this->assertIsString($navigation);
    $this->assertStringContainsString(
      '.mel-sidebar__item--studio-open .mel-event-studio-sidebar__link',
      $navigation,
    );
    $this->assertStringContainsString('font-size: $font-size-sm;', $navigation);

    $this->assertIsString($libraries);
    $this->assertStringContainsString("mel_event_studio:\n  version: 1.32", $libraries);
    $this->assertStringContainsString("mel_event_studio_shell_only:\n  version: 1.21", $libraries);
  }

}
