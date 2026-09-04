<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards Stripe runtime failures from leaking into ordinary organiser pages.
 */
#[Group('myeventlane_vendor')]
final class StripeRuntimeSafetyContractTest extends TestCase {

  /**
   * Tests that global footer rendering never calls the Stripe API.
   */
  public function testFooterDoesNotFetchStripeBalance(): void {
    $custom = dirname(__DIR__, 4);
    $footer = file_get_contents($custom . '/myeventlane_vendor/src/Service/FooterContextService.php');
    $services = file_get_contents($custom . '/myeventlane_vendor/myeventlane_vendor.services.yml');

    self::assertIsString($footer);
    self::assertStringContainsString("'payout_balance' => NULL", $footer);
    self::assertStringNotContainsString('getAvailableBalanceFormatted', $footer);
    self::assertIsString($services);
    $footer_service = strstr($services, 'logger.channel.myeventlane_vendor:', TRUE);
    self::assertIsString($footer_service);
    self::assertStringNotContainsString('@?myeventlane_stripe.vendor_payout', $footer_service);
  }

  /**
   * Tests that Stripe failures are safe and are not shown as zero balances.
   */
  public function testStripeFailuresDoNotLogExceptionMessages(): void {
    $custom = dirname(__DIR__, 4);
    $payout = file_get_contents($custom . '/myeventlane_stripe/src/Service/VendorStripePayoutService.php');
    $dashboard = file_get_contents($custom . '/myeventlane_dashboard/src/Service/VendorStripeBalanceService.php');
    $payments = file_get_contents($custom . '/myeventlane_vendor/src/Service/VendorPaymentsHubBuilder.php');

    self::assertIsString($payout);
    self::assertStringContainsString("'available' => 'Unavailable'", $payout);
    self::assertStringContainsString('getStripeCode()', $payout);
    self::assertStringNotContainsString('->getMessage()', $payout);
    self::assertIsString($dashboard);
    self::assertStringContainsString("return 'Unavailable';", $dashboard);
    self::assertStringNotContainsString('->getMessage()', $dashboard);
    self::assertIsString($payments);
    self::assertStringContainsString('Payments hub could not load Stripe balances (@exception).', $payments);
    self::assertStringNotContainsString('Payments hub could not load Stripe balances: @message', $payments);
  }

  /**
   * Tests that footers hide an unavailable payout balance.
   */
  public function testFootersHideUnavailablePayoutBalance(): void {
    $web = dirname(dirname(__DIR__, 4), 2);
    $templates = [
      $web . '/themes/custom/myeventlane_theme/templates/layout/footer-internal.html.twig',
      $web . '/themes/custom/myeventlane_vendor_theme/templates/includes/footer-internal.html.twig',
      $web . '/themes/custom/myeventlane_vendor_theme/templates/includes/footer-dashboard-light.html.twig',
    ];

    foreach ($templates as $template_path) {
      $template = file_get_contents($template_path);
      self::assertIsString($template);
      self::assertStringContainsString('payout_balance: null', $template);
      self::assertStringContainsString('{% if footer_context.payout_balance %}', $template);
    }
  }

  /**
   * Tests that an unknown runtime is never presented as production.
   */
  public function testFooterEnvironmentLabelUsesConfigurationOrHost(): void {
    $custom = dirname(__DIR__, 4);
    $footer = (string) file_get_contents($custom . '/myeventlane_vendor/src/Service/FooterContextService.php');
    $services = (string) file_get_contents($custom . '/myeventlane_vendor/myeventlane_vendor.services.yml');
    $web = dirname(dirname(__DIR__, 4), 2);

    self::assertStringContainsString("getenv('APP_ENV')", $footer);
    self::assertStringContainsString("'staging.myeventlane.com.au'", $footer);
    self::assertStringContainsString("return 'Staging';", $footer);
    self::assertStringNotContainsString("getenv('SITE_ENV') ?: 'Production'", $footer);
    self::assertStringContainsString("- '@request_stack'", $services);

    foreach ([
      $web . '/themes/custom/myeventlane_theme/templates/layout/footer-internal.html.twig',
      $web . '/themes/custom/myeventlane_vendor_theme/templates/includes/footer-internal.html.twig',
      $web . '/themes/custom/myeventlane_vendor_theme/templates/includes/footer-dashboard-light.html.twig',
    ] as $templatePath) {
      $template = (string) file_get_contents($templatePath);
      self::assertStringContainsString("environment: ''", $template);
      self::assertStringNotContainsString("environment: 'Production'", $template);
    }
  }

}
