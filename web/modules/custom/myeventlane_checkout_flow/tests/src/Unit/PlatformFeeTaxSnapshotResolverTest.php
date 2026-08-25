<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\myeventlane_checkout_flow\Service\PlatformFeeTaxSnapshotResolver;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/src/Service/PlatformFeeTaxSnapshotResolver.php';

/**
 * Covers MEL platform-fee tax snapshotting.
 *
 * @group myeventlane_checkout_flow
 */
final class PlatformFeeTaxSnapshotResolverTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', new class() {

      /**
       * Returns the adjustment types used by this fixture.
       */
      public function getDefinitions(): array {
        return [
          'fee' => ['label' => 'Fee'],
          'custom' => ['label' => 'Custom'],
        ];
      }

    });
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * MEL fee GST is snapshotted without claiming organiser contributions.
   */
  public function testCapturesOnlyMelFeeWithInclusiveGst(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['platform_fee_gst_inclusive', TRUE],
      ['platform_legal_name', 'MyEventLane Inc'],
      ['platform_abn', '11 304 813 593'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('myeventlane_core.settings')
      ->willReturn($config);

    $rounder = $this->createMock(RounderInterface::class);
    $rounder->expects(self::once())
      ->method('round')
      ->with(self::callback(
        static fn (Price $price): bool => $price->getNumber() === '0.027272',
      ))
      ->willReturn(new Price('0.03', 'AUD'));

    $platformFee = new Adjustment([
      'type' => 'fee',
      'label' => 'Platform fee (1.5%)',
      'amount' => new Price('0.30', 'AUD'),
      'source_id' => 'myeventlane_platform_fee',
    ]);
    $organiserContribution = new Adjustment([
      'type' => 'custom',
      'label' => 'Contribution',
      'amount' => new Price('5.00', 'AUD'),
      'source_id' => 'myeventlane_order_donation',
    ]);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getData')
      ->with(PlatformFeeTaxSnapshotResolver::ORDER_DATA_KEY)
      ->willReturn(NULL);
    $order->method('getAdjustments')
      ->willReturn([$platformFee, $organiserContribution]);
    $order->expects(self::once())
      ->method('setData')
      ->with(
        PlatformFeeTaxSnapshotResolver::ORDER_DATA_KEY,
        self::callback(
          static fn (array $snapshot): bool => count($snapshot['fee_lines']) === 1,
        ),
      );

    $resolver = new PlatformFeeTaxSnapshotResolver($configFactory, $rounder);
    $snapshot = $resolver->capture($order);

    self::assertSame('MyEventLane Inc', $snapshot['platform_name']);
    self::assertSame('11 304 813 593', $snapshot['platform_abn']);
    self::assertSame('0.30', $snapshot['fee_lines'][0]['amount_number']);
    self::assertSame('0.03', $snapshot['fee_lines'][0]['gst_number']);
    self::assertSame(
      'myeventlane_platform_fee',
      $snapshot['fee_lines'][0]['source_id'],
    );
  }

}
