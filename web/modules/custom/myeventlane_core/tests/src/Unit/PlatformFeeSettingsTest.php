<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\Service\PlatformFeeSettings;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\Service\PlatformFeeSettings
 *
 * @group myeventlane_core
 */
final class PlatformFeeSettingsTest extends UnitTestCase {

  /**
   * @covers ::getOperationalExtrasFeePercent
   * @covers ::extrasFeeIsDoubleTicketFee
   */
  public function testExtrasFeeDefaultsToDoubleTicketFee(): void {
    $settings = $this->service(['platform_fee_percent' => 1.5]);
    $this->assertSame(3.0, $settings->getOperationalExtrasFeePercent());
    $this->assertTrue($settings->extrasFeeIsDoubleTicketFee());
  }

  /**
   * @covers ::getOperationalExtrasFeePercent
   */
  public function testExtrasFeeUsesExplicitConfigWhenSet(): void {
    $settings = $this->service([
      'platform_fee_percent' => 2.0,
      'operational_extras_platform_fee_percent' => 4.5,
    ]);
    $this->assertSame(4.5, $settings->getOperationalExtrasFeePercent());
    $this->assertFalse($settings->extrasFeeIsDoubleTicketFee());
  }

  /**
   * @param array<string, mixed> $values
   */
  private function service(array $values): PlatformFeeSettings {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($values) {
      return $values[$key] ?? NULL;
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_core.settings')->willReturn($config);
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')->willReturnCallback(static function ($s, $args = []) {
      if ($args !== []) {
        return strtr((string) $s, $args);
      }
      return (string) $s;
    });
    return new PlatformFeeSettings($factory, $translation);
  }

}
