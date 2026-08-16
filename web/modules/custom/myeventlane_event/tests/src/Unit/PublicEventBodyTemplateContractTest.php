<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the public event description rendering contract.
 *
 * @group myeventlane_event
 */
final class PublicEventBodyTemplateContractTest extends TestCase {

  public function testBodyIsRenderedOnceAndReusedForOutput(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig',
    );

    self::assertSame(1, substr_count($template, 'content.body|render'));
    self::assertStringContainsString('{{ body_render }}', $template);
    self::assertStringNotContainsString('{{ content.body }}', $template);
  }

  public function testPublicContentCardsUseOneOrderedColumn(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig',
    );
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/components/_event-full.scss',
    );

    self::assertStringContainsString('<div class="mel-event-full__content-grid">', $template);
    self::assertStringNotContainsString('mel-event-full__content-grid--duo', $template);
    self::assertStringNotContainsString('.mel-event-full__content-grid--duo', $styles);

    $aboutPosition = strpos($template, "'About the Event'|t");
    $highlightsPosition = strpos($template, "'Highlights'|t");
    $expectPosition = strpos($template, "'What to expect'|t");

    self::assertIsInt($aboutPosition);
    self::assertIsInt($highlightsPosition);
    self::assertIsInt($expectPosition);
    self::assertLessThan($highlightsPosition, $aboutPosition);
    self::assertLessThan($expectPosition, $highlightsPosition);
  }

  public function testPublicMapUsesConfiguredProviderRenderer(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig',
    );

    self::assertStringContainsString('myeventlane-event-map-container', $template);
    self::assertStringContainsString('data-latitude="{{ mel_lat }}"', $template);
    self::assertStringContainsString('data-longitude="{{ mel_lng }}"', $template);
    self::assertStringNotContainsString('www.google.com/maps?output=embed', $template);
    self::assertStringNotContainsString('<iframe', $template);
  }

  public function testPublicMapProvidesDeviceAwareDirections(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $template = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/node/node--event--full.html.twig',
    );
    $script = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_location/js/event-map.js',
    );

    self::assertStringContainsString('data-mel-directions-link', $template);
    self::assertStringContainsString('maps.apple.com/directions?destination=', $template);
    self::assertStringContainsString('www.google.com/maps/dir/?api=1', $template);
    self::assertStringContainsString('dir_action=navigate', $template);
    self::assertStringContainsString("{{ 'Get directions'|t }}", $template);

    self::assertStringContainsString('/iPad|iPhone|iPod/i', $script);
    self::assertStringContainsString("platform === 'MacIntel'", $script);
    self::assertStringContainsString('/Android/i', $script);
    self::assertStringContainsString("provider === 'apple_maps'", $script);
    self::assertStringContainsString('link.dataset.appleDirectionsUrl', $script);
    self::assertStringContainsString('link.dataset.googleDirectionsUrl', $script);
  }

}
