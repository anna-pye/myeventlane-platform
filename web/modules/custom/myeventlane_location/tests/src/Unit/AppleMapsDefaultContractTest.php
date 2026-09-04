<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_location\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects Apple Maps as the canonical site provider.
 *
 * @group myeventlane_location
 */
final class AppleMapsDefaultContractTest extends TestCase {

  /**
   * The versioned defaults use Apple Maps at every defensive boundary.
   */
  public function testAppleMapsIsCanonicalDefault(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $projectRoot = dirname($moduleRoot, 4);

    $installConfig = (string) file_get_contents(
      $moduleRoot . '/config/install/myeventlane_location.settings.yml',
    );
    $syncConfig = (string) file_get_contents(
      $projectRoot . '/config/sync/myeventlane_location.settings.yml',
    );
    $manager = (string) file_get_contents(
      $moduleRoot . '/src/Service/LocationProviderManager.php',
    );
    $eventMap = (string) file_get_contents($moduleRoot . '/js/event-map.js');
    $autocomplete = (string) file_get_contents($moduleRoot . '/js/address-autocomplete.js');

    self::assertStringContainsString('default_provider: apple_maps', $installConfig);
    self::assertStringContainsString('default_provider: apple_maps', $syncConfig);
    self::assertStringContainsString("?? 'apple_maps'", $manager);
    self::assertStringContainsString("settings.provider || 'apple_maps'", $eventMap);
    self::assertStringContainsString("getSettings().provider || 'apple_maps'", $autocomplete);
  }

  /**
   * Local development keeps the approved Google Maps fallback explicit.
   */
  public function testDdevUsesExplicitGoogleFallback(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $projectRoot = dirname($moduleRoot, 4);
    $settings = (string) file_get_contents(
      $projectRoot . '/web/sites/default/settings.mel_shared_session.php',
    );

    self::assertStringContainsString("\$melGetEnv('MEL_MAP_PROVIDER')", $settings);
    self::assertStringContainsString(
      "\$config['myeventlane_location.settings']['default_provider'] = 'google_maps';",
      $settings,
    );
  }

}
