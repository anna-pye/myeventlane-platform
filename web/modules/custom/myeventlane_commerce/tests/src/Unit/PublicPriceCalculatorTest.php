<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\myeventlane_commerce\Service\PublicPriceCalculator;
use Drupal\myeventlane_core\Service\PlatformFeeSettings;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\PublicPriceCalculator
 * @group myeventlane_commerce
 */
final class PublicPriceCalculatorTest extends UnitTestCase {

  /**
   * @covers ::total
   */
  public function testBuyerPricesAndOrderLevelRounding(): void {
    $service = $this->service();
    $this->assertSame('11.28', $service->total(new Price('11.11', 'AUD'))->getNumber());
    $this->assertSame('33.83', $service->total(new Price('33.33', 'AUD'))->getNumber());
    $this->assertSame('33.5', $service->total(new Price('33', 'AUD'))->getNumber());
    $this->assertSame('0', $service->total(new Price('0', 'AUD'))->getNumber());
    $this->assertSame('51.5', $service->total(new Price('50', 'AUD'), TRUE)->getNumber());
  }

  /**
   * @covers ::buyerFeePercent
   * @covers ::total
   */
  public function testAbsorbedAndConfiguredFees(): void {
    $absorbed = $this->service(['fee_payer' => 'organizer_absorbs']);
    $this->assertSame('11.11', $absorbed->total(new Price('11.11', 'AUD'))->getNumber());
    $this->assertSame(0.0, $absorbed->buyerFeePercent(TRUE));
    $zero = $this->service(['platform_fee_percent' => 0]);
    $this->assertSame('11.11', $zero->total(new Price('11.11', 'AUD'))->getNumber());
    $custom = $this->service(['platform_fee_percent' => 2, 'operational_extras_platform_fee_percent' => 4]);
    $this->assertSame('102', $custom->total(new Price('100', 'AUD'))->getNumber());
    $this->assertSame('104', $custom->total(new Price('100', 'AUD'), TRUE)->getNumber());
  }

  private function service(array $overrides = []): PublicPriceCalculator {
    $values = $overrides + ['platform_fee_percent' => 1.5, 'fee_payer' => 'buyer'];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn ($key) => $values[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_core.settings')->willReturn($config);
    $settings = new PlatformFeeSettings($factory, $this->getStringTranslationStub());
    $rounder = new class implements RounderInterface {
      public function round(Price $price, $mode = PHP_ROUND_HALF_UP): Price {
        return new Price(Calculator::round($price->getNumber(), 2, $mode), $price->getCurrencyCode());
      }
    };
    return new PublicPriceCalculator($factory, $settings, $rounder);
  }

}
