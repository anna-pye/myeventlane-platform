<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser-facing MEL Pro subscription home.
 *
 * @group myeventlane_pro
 */
final class ProManagePresentationContractTest extends TestCase {

  public function testManagePageExplainsPlanToolsAndSelfServiceCancellation(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Your Pro toolkit'|t", $template);
    self::assertStringContainsString("'Deeper analytics'|t", $template);
    self::assertStringContainsString("'Pro email templates'|t", $template);
    self::assertStringContainsString("'Marketing and branding tools'|t", $template);
    self::assertStringContainsString("'Cancel at period end'|t", $template);
    self::assertStringContainsString("'Reactivate MEL Pro'|t", $template);
    self::assertStringContainsString("'Invoices and receipts'|t", $template);
  }

  public function testManagePageDoesNotPresentStaleRenewalAsFutureBilling(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($controller);
    self::assertIsString($template);

    self::assertStringContainsString('$renewalDateStale = TRUE', $controller);
    self::assertStringContainsString("'Your billing date needs review'|t", $template);
    self::assertStringContainsString('We do not have a future renewal date to show', $template);
  }

  public function testBillingPortalUsesTrustedExternalRedirect(): void {
    $controller = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/ProBillingController.php');
    self::assertIsString($controller);

    self::assertStringContainsString('use Drupal\\Core\\Routing\\TrustedRedirectResponse;', $controller);
    self::assertStringContainsString('return new TrustedRedirectResponse($url);', $controller);
  }

  public function testZeroRoiUsesPlainLanguage(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-manage.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString('roi_summary.roi_multiple > 0', $template);
    self::assertStringContainsString('No recovered revenue has been attributed', $template);
  }

  public function testCancellationRemainsAvailableWhenLegacyConfigKeyIsMissing(): void {
    $service = file_get_contents(dirname(__DIR__, 3) . '/src/Service/ProSubscriptionStatusService.php');
    self::assertIsString($service);

    self::assertStringContainsString("get('cancel_request_enabled') ?? TRUE", $service);
  }

  public function testCancellationUsesCommerceScheduledChange(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProCancelRequestForm.php');
    $reactivate = file_get_contents(dirname(__DIR__, 3) . '/src/Form/ProReactivateForm.php');
    self::assertIsString($form);
    self::assertIsString($reactivate);

    self::assertStringContainsString('$subscription->cancel(TRUE)', $form);
    self::assertStringContainsString("hasScheduledChange('state', 'canceled')", $form);
    self::assertStringContainsString("removeScheduledChanges('state')", $reactivate);
  }

  public function testUpgradePageUsesVerifiedBenefitLanguageAndOneSubscribeForm(): void {
    $template = file_get_contents(dirname(__DIR__, 3) . '/templates/vendor-pro-overview.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("'Advanced organiser analytics'|t", $template);
    self::assertStringContainsString("'Pro email templates'|t", $template);
    self::assertStringContainsString('30-day trial, then @price per month', $template);
    self::assertSame(1, substr_count($template, '{{ subscribe_form }}'));
    self::assertStringNotContainsString('Automated refunds', $template);
    self::assertStringNotContainsString('Revenue Growth', $template);
  }

}
