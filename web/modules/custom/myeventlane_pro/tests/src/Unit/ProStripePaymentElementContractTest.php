<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsZeroBalanceOrderInterface;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement;
use Drupal\myeventlane_pro\Plugin\Commerce\PaymentGateway\ProStripePaymentElement;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_pro\Plugin\Commerce\PaymentGateway\ProStripePaymentElement
 * @group myeventlane_pro
 */
final class ProStripePaymentElementContractTest extends UnitTestCase {

  /**
   * Ensures Commerce retains the Pro gateway on a zero-balance trial order.
   */
  public function testGatewayDeclaresZeroBalanceSupport(): void {
    $this->assertTrue(is_subclass_of(ProStripePaymentElement::class, StripePaymentElement::class));
    $this->assertTrue(is_subclass_of(ProStripePaymentElement::class, SupportsZeroBalanceOrderInterface::class));
  }

  /**
   * Ensures the trial path has an explicit SetupIntent implementation.
   */
  public function testGatewayImplementsZeroBalanceSetupIntentPath(): void {
    $method = new \ReflectionMethod(ProStripePaymentElement::class, 'createIntent');
    $source = file_get_contents($method->getFileName());

    $this->assertIsString($source);
    $this->assertStringContainsString('SetupIntent::create', $source);
    $this->assertStringContainsString("'usage' => 'off_session'", $source);
    $this->assertStringContainsString("'purpose' => 'mel_pro_trial'", $source);
  }

}
