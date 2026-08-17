<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\myeventlane_checkout_flow\Service\OrganiserCheckoutContext;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\OrganiserCheckoutContext
 * @group myeventlane_checkout_flow
 */
final class OrganiserCheckoutContextTest extends UnitTestCase {

  /**
   * @covers ::build
   */
  public function testProTrialExplainsFreeAndOngoingPayments(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('bundle')->willReturn('mel_pro_subscription_variation');
    $variation->method('getPrice')->willReturn(new Price('49.00', 'AUD'));

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getPurchasedEntity')->willReturn($variation);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$item]);
    $order->method('getTotalPrice')->willReturn(new Price('0', 'AUD'));

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('configuration.trial_interval.number')->willReturn(30);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $formatter = $this->createMock(CurrencyFormatterInterface::class);
    $formatter->method('format')->willReturn('A$49.00');

    $context = new OrganiserCheckoutContext(
      $configFactory,
      $formatter,
      $this->getStringTranslationStub(),
    );
    $built = $context->build($order);

    $this->assertSame('pro', $built['kind']);
    $copy = json_encode($built, JSON_THROW_ON_ERROR);
    $this->assertStringContainsString('30-day free trial', $copy);
    $this->assertStringContainsString('A$49.00 each month', $copy);
    $this->assertStringContainsString('Renews monthly until cancelled', $copy);
    $this->assertStringContainsString('Your Pro trial has started', $copy);
    $this->assertStringContainsString('Manage MyEventLane Pro', $copy);
    $this->assertStringNotContainsString('tickets', strtolower($copy));
  }

  /**
   * @covers ::build
   */
  public function testProWithoutTrialDoesNotPromisePaymentAfterTrial(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('bundle')->willReturn('mel_pro_subscription_variation');
    $variation->method('getPrice')->willReturn(new Price('49.00', 'AUD'));

    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getPurchasedEntity')->willReturn($variation);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$item]);
    $order->method('getTotalPrice')->willReturn(new Price('49.00', 'AUD'));

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('configuration.trial_interval.number')->willReturn(30);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $formatter = $this->createMock(CurrencyFormatterInterface::class);
    $formatter->method('format')->willReturn('A$49.00');

    $context = new OrganiserCheckoutContext(
      $configFactory,
      $formatter,
      $this->getStringTranslationStub(),
    );
    $copy = json_encode($context->build($order), JSON_THROW_ON_ERROR);

    $this->assertStringContainsString('No free trial is applied', $copy);
    $this->assertStringContainsString('A$49.00 per month from today', $copy);
    $this->assertStringNotContainsString('per month after the trial', $copy);
  }

  /**
   * @covers ::build
   */
  public function testCustomerOrderIsNotRelabelledAsOrganiserPurchase(): void {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('bundle')->willReturn('ticket_variation');
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getPurchasedEntity')->willReturn($variation);

    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn([$item]);

    $context = new OrganiserCheckoutContext(
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(CurrencyFormatterInterface::class),
      $this->getStringTranslationStub(),
    );

    $this->assertSame(['kind' => 'customer'], $context->build($order));
  }

}
