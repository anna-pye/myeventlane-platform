<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_schema\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the event timezone field's configuration convergence.
 *
 * @group myeventlane_schema
 */
final class SeriesTimezoneConfigConvergenceTest extends TestCase {

  /**
   * Field config contains only settings supported by the string field type.
   */
  public function testTimezoneFieldSettingsConvergeAfterImport(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $projectRoot = dirname($moduleRoot, 4);
    $syncPath = $projectRoot . '/config/sync/field.field.node.event.field_series_timezone.yml';
    $installPath = $moduleRoot . '/config/install/field.field.node.event.field_series_timezone.yml';
    $paths = [$syncPath, $installPath];

    foreach ($paths as $path) {
      $config = Yaml::parseFile($path);

      self::assertIsArray($config);
      self::assertSame('Event timezone', $config['label'] ?? NULL);
      self::assertSame([], $config['settings'] ?? NULL);
      self::assertArrayNotHasKey('size', $config['settings'] ?? []);
      self::assertArrayNotHasKey('placeholder', $config['settings'] ?? []);
    }

    $syncConfig = Yaml::parseFile($syncPath);
    $installConfig = Yaml::parseFile($installPath);

    self::assertArrayHasKey('uuid', $syncConfig);
    self::assertNull($syncConfig['uuid']);
    self::assertArrayNotHasKey('uuid', $installConfig);
  }

}
