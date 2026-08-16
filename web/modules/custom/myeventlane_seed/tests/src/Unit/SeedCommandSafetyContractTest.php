<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_seed\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the development seed module from unscoped event deletion.
 *
 * @group myeventlane_seed
 */
final class SeedCommandSafetyContractTest extends TestCase {

  /**
   * Ensures the command surface contains only seed-scoped event operations.
   */
  public function testUnscopedEventCommandsAreRetired(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $commands = file_get_contents($moduleRoot . '/src/Commands/MelSeedCommands.php');
    self::assertIsString($commands);

    foreach ([
      'mel:reset-events',
      'mel-reset-events',
      'mel:seed-demo',
      'mel-seed-demo',
      'deleteAllEvents',
      'EventResetService',
    ] as $unsafeContract) {
      self::assertStringNotContainsString($unsafeContract, $commands);
    }

    self::assertStringContainsString("name: 'mel:seed-events'", $commands);
    self::assertStringContainsString("name: 'mel:purge-events'", $commands);
    self::assertStringContainsString("'dry-run'", $commands);
  }

  /**
   * Ensures the removed reset service cannot be registered again unnoticed.
   */
  public function testUnscopedResetServiceIsAbsent(): void {
    $moduleRoot = dirname(__DIR__, 3);
    self::assertFileDoesNotExist($moduleRoot . '/src/Service/EventResetService.php');

    foreach (['myeventlane_seed.services.yml', 'drush.services.yml'] as $serviceFile) {
      $services = file_get_contents($moduleRoot . '/' . $serviceFile);
      self::assertIsString($services);
      self::assertStringNotContainsString('myeventlane_seed.event_reset', $services);
      self::assertStringNotContainsString('EventResetService', $services);
    }

    $serviceInventory = file_get_contents(dirname(__DIR__, 7) . '/mel-services.json');
    self::assertIsString($serviceInventory);
    self::assertStringNotContainsString('myeventlane_seed.event_reset', $serviceInventory);
  }

  /**
   * Ensures production configuration does not enable seed tooling.
   */
  public function testSeedModuleIsExcludedFromProductionExtensions(): void {
    $extensions = file_get_contents(dirname(__DIR__, 7) . '/config/sync/core.extension.yml');
    self::assertIsString($extensions);
    self::assertStringNotContainsString('myeventlane_seed:', $extensions);
  }

}
