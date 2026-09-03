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
    $paths = [
      $projectRoot . '/config/sync/field.field.node.event.field_series_timezone.yml',
      $moduleRoot . '/config/install/field.field.node.event.field_series_timezone.yml',
    ];

    foreach ($paths as $path) {
      $config = Yaml::parseFile($path);

      self::assertIsArray($config);
      self::assertSame('Event timezone', $config['label'] ?? NULL);
      self::assertSame([], $config['settings'] ?? NULL);
      self::assertArrayNotHasKey('size', $config['settings'] ?? []);
      self::assertArrayNotHasKey('placeholder', $config['settings'] ?? []);
    }
  }

}
