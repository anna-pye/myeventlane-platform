<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects high-intent public mobile journeys from fixed UI collisions.
 *
 * @group myeventlane_event
 */
final class PublicMobileConversionContractTest extends TestCase {

  public function testTransactionalJourneysOwnTheMobileBottomEdge(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $navigation = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/components/_mobile-bottom-nav.scss',
    );
    $guide = (string) file_get_contents(
      $webRoot . '/modules/custom/mel_guide/css/mel-guide.css',
    );

    self::assertStringContainsString('&:has(.mel-mobile-cta)', $navigation);
    self::assertStringContainsString('&:has(.mel-book-page)', $navigation);
    self::assertStringContainsString('&:has(.mel-checkout)', $navigation);
    self::assertStringContainsString("&:has(form[id^='views-form-commerce-cart-form'])", $navigation);
    self::assertStringContainsString('.mel-mobile-bottom-nav {', $navigation);
    self::assertStringContainsString('display: none;', $navigation);
    self::assertStringContainsString('body:has(.mel-checkout) .mel-guide', $guide);
  }

  public function testMobileBookingStartsWithTicketSelection(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $booking = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/components/_booking-page.scss',
    );

    self::assertMatchesRegularExpression(
      '/\.mel-book-summary\[data-mel-booking-summary\]\s*\{\s*order:\s*2;/',
      $booking,
    );
    self::assertMatchesRegularExpression(
      '/\.mel-book-main\s*\{\s*order:\s*1;/',
      $booking,
    );
  }

  public function testMobileCheckoutInputsAvoidIosAutoZoom(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $checkout = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/components/_checkout.scss',
    );

    self::assertStringContainsString('font-size: 16px;', $checkout);
  }

  public function testOperationalAddonQuantityControlCannotOverflow(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $addons = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_commerce/css/mel-operational-addons.css',
    );

    self::assertStringContainsString(
      ".mel-event-extra-card input[type='number'].mel-event-extra-card__qty-input",
      $addons,
    );
    self::assertStringContainsString('clip-path: inset(50%);', $addons);
    self::assertStringNotContainsString('position: absolute !important;', $addons);
  }

}
