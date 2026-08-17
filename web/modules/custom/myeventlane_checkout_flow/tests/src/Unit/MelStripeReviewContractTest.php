<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_stripe\Plugin\Commerce\CheckoutPane\StripeReview;
use Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane\MelStripeReview;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane\MelStripeReview
 * @group myeventlane_checkout_flow
 */
final class MelStripeReviewContractTest extends UnitTestCase {

  /**
   * Ensures MEL retains Stripe's reviewed Payment Element implementation.
   */
  public function testPaneExtendsCommerceStripeReview(): void {
    $this->assertTrue(is_subclass_of(MelStripeReview::class, StripeReview::class));

    $method = new \ReflectionMethod(MelStripeReview::class, 'isVisible');
    $source = file_get_contents($method->getFileName());
    $this->assertIsString($source);
    $this->assertStringContainsString('SupportsZeroBalanceOrderInterface', $source);
    $this->assertStringContainsString('$balance->isZero()', $source);
    $this->assertStringContainsString("['intentType'] = 'setup'", $source);
  }

}
