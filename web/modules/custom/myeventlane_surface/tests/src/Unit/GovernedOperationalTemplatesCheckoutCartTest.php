<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use Drupal\myeventlane_surface\GovernedOperationalTemplates;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_surface\GovernedOperationalTemplates
 *
 * @group myeventlane_surface
 */
final class GovernedOperationalTemplatesCheckoutCartTest extends UnitTestCase {

  private function templates(): GovernedOperationalTemplates {
    return new GovernedOperationalTemplates(new MelReadinessHelper($this->getStringTranslationStub()));
  }

  /**
   * @covers ::checkoutCartEmpty
   * @covers ::checkoutCartExpired
   * @covers ::checkoutCartUnavailableTicket
   * @covers ::checkoutCartSoldOut
   * @covers ::checkoutCartRemovedUnavailableLineItem
   * @covers ::checkoutCartPaymentUnavailable
   */
  public function testCheckoutCartEmptyStatesUseMelEmptyStateTheme(): void {
    $t = $this->templates();
    foreach ([
      $t->checkoutCartEmpty(),
      $t->checkoutCartExpired(),
      $t->checkoutCartUnavailableTicket(),
      $t->checkoutCartSoldOut(),
      $t->checkoutCartRemovedUnavailableLineItem(),
      $t->checkoutCartPaymentUnavailable(),
    ] as $build) {
      $this->assertSame('mel_empty_state', $build['#theme']);
      $this->assertNotSame('', (string) $build['#heading']);
      $this->assertNotSame('', (string) $build['#what_happened']);
    }
  }

}
