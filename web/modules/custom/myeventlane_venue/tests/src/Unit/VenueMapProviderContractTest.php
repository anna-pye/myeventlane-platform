<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects provider-aware map rendering on public venue pages.
 *
 * @group myeventlane_venue
 */
final class VenueMapProviderContractTest extends TestCase {

  /**
   * The venue page uses the shared provider rather than a Google iframe.
   */
  public function testVenuePageUsesConfiguredMapProvider(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $controller = (string) file_get_contents(
      $moduleRoot . '/src/Controller/VenueViewController.php',
    );
    $template = (string) file_get_contents(
      $moduleRoot . '/templates/myeventlane-venue-page.html.twig',
    );

    self::assertStringContainsString("'myeventlane_location/event_map'", $controller);
    self::assertStringContainsString("['myeventlaneLocationMap']", $controller);
    self::assertStringContainsString('getFrontendSettings()', $controller);
    self::assertStringContainsString('myeventlane-event-map-container', $template);
    self::assertStringContainsString('data-mel-directions-link', $template);
    self::assertStringNotContainsString('google.com/maps?output=embed', $template);
    self::assertStringNotContainsString('<iframe', $template);
  }

  /**
   * Venue coordinates come from canonical VenueLocation accessors.
   */
  public function testVenueMapUsesCanonicalLocationData(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $controller = (string) file_get_contents(
      $moduleRoot . '/src/Controller/VenueViewController.php',
    );

    self::assertStringContainsString('getPrimaryLocation($venue)', $controller);
    self::assertStringContainsString('getLatitude()', $controller);
    self::assertStringContainsString('getLongitude()', $controller);
    self::assertStringContainsString('getAddressText()', $controller);
    self::assertStringContainsString('getTitle()', $controller);
    self::assertStringNotContainsString("get('name')", $controller);
    self::assertStringNotContainsString("get('address')", $controller);
  }

}
