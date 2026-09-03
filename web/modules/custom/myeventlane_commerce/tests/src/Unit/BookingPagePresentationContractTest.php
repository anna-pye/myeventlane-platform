<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the booking page's zero-selection and copy behaviour.
 *
 * @group myeventlane_commerce
 */
final class BookingPagePresentationContractTest extends TestCase {

  public function testCheckoutActionIsDisabledUntilATicketIsSelected(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $script = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/js/mel-booking-summary.js',
    );

    self::assertStringContainsString('const shouldDisable = submitDisabledByServer || !hasTickets;', $script);
    self::assertStringContainsString('targets.submit.disabled = shouldDisable;', $script);
    self::assertStringContainsString("targets.submit.setAttribute('aria-disabled'", $script);
  }

  public function testTicketQuantityHasAccessibleStepControls(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $script = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/js/mel-booking-summary.js',
    );
    $styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/src/scss/components/_booking-page.scss',
    );

    self::assertStringContainsString("decrease.type = 'button';", $script);
    self::assertStringContainsString("increase.type = 'button';", $script);
    self::assertStringContainsString("Drupal.t('Decrease quantity for @ticket'", $script);
    self::assertStringContainsString("Drupal.t('Increase quantity for @ticket'", $script);
    self::assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }))", $script);
    self::assertStringContainsString('.mel-ticket-quantity-control {', $styles);
    self::assertStringContainsString('grid-template-columns: 44px minmax(3rem, 1fr) 44px;', $styles);
  }

  public function testBookingPageDoesNotRepeatTicketSelectionInstructions(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $page = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/commerce/myeventlane-event-book.html.twig',
    );
    $form = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_theme/templates/form/form--myeventlane-ticket-selection-form.html.twig',
    );

    self::assertSame(1, substr_count($page, 'Choose at least one ticket'));
    self::assertStringNotContainsString('<div class="mel-booking__intro">', $form);
    self::assertStringNotContainsString('Tickets go to your cart first', $page);
  }

}
