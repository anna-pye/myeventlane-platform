<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the platform Stripe account-context boundary.
 */
final class PlatformStripeGatewayContractTest extends TestCase {

  /**
   * The platform gateway definition uses MEL's isolated implementation.
   */
  public function testPlatformGatewayDefinitionIsIsolated(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_commerce.module');

    self::assertIsString($module);
    self::assertStringContainsString("\$definitions['stripe']['class'] = PlatformStripe::class", $module);
  }

  /**
   * Every remote payment entry point restores platform Stripe scope first.
   */
  public function testRemoteOperationsEnterPlatformContext(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/Commerce/PaymentGateway/PlatformStripe.php');

    self::assertIsString($source);
    self::assertStringContainsString('$this->init();', $source);
    self::assertStringContainsString('StripeLibrary::setAccountId(NULL);', $source);

    foreach ([
      'createPayment',
      'capturePayment',
      'voidPayment',
      'refundPayment',
      'createPaymentMethod',
      'deletePaymentMethod',
      'createPaymentIntent',
    ] as $method) {
      self::assertMatchesRegularExpression(
        '/function ' . $method . '\\([^}]+?\\$this->enterPlatformContext\\(\\);/s',
        $source,
      );
    }
  }

}
