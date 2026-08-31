<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the organiser-facing Payments hub hierarchy and guidance.
 *
 * @group myeventlane_vendor
 */
final class VendorPaymentsHubPresentationContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  public function testHubUsesOneHealthActionAndACompactToolHierarchy(): void {
    $template = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/payments-hub.html.twig');
    $this->assertIsString($template);
    $this->assertStringContainsString('mel-payments-hub__money-workspace', $template);
    $this->assertStringContainsString("{{ 'Payment tools'|t }}", $template);
    $this->assertStringContainsString('<details class="mel-payments-hub__tool">', $template);
    $this->assertStringNotContainsString('mel-payments-hub__callout', $template);

    $healthEnd = strpos($template, '</section>', strpos($template, 'id="payment-health"'));
    $healthMarkup = substr($template, strpos($template, 'id="payment-health"'), $healthEnd);
    $this->assertSame(1, substr_count($healthMarkup, '<a class="mel-button'));
  }

  public function testHubUsesSharedOrganiserHierarchyWithoutLosingPaymentActions(): void {
    $template = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/payments-hub.html.twig');
    $controller = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor/src/Controller/VendorPaymentsHubController.php');
    $this->assertIsString($template);
    $this->assertIsString($controller);
    $this->assertStringContainsString('mel-payments-hub mel-organiser-page', $template);
    $this->assertStringContainsString("{{ 'Your money'|t }}", $template);
    $this->assertStringContainsString('mel-organiser-page__metrics--three', $template);
    $this->assertStringContainsString('health.cta_url', $template);
    $this->assertStringContainsString('payouts.history_url', $template);
    $this->assertStringContainsString('refunds.review_url', $template);
    $this->assertStringContainsString("'title' => NULL", $controller);
  }

  public function testBuilderExplainsBalancesAndUsesPayoutAwareEmptyStates(): void {
    $builder = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHubBuilder.php');
    $template = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/payments-hub.html.twig');
    $this->assertIsString($builder);
    $this->assertIsString($template);
    $this->assertStringContainsString("'available_help'", $builder);
    $this->assertStringContainsString("'pending_help'", $builder);
    $this->assertStringContainsString("'net_help'", $builder);
    $this->assertStringContainsString('Stripe payouts are not enabled yet', $builder);
    $this->assertStringContainsString('No Stripe payout has arrived yet', $builder);
    $this->assertStringContainsString("'Stripe payouts'|t", $template);
    $this->assertStringContainsString("'Last Stripe payout'|t", $template);
  }

  public function testStripeAccountDetailsExposeTheCurrentStoresConnectedAccountId(): void {
    $root = $this->repositoryRoot();
    $health = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHealthService.php');
    $template = file_get_contents($root . '/web/themes/custom/myeventlane_vendor_theme/templates/payments-hub.html.twig');
    $this->assertIsString($health);
    $this->assertIsString($template);
    $this->assertStringContainsString("field_stripe_account_id", $health);
    $this->assertStringContainsString("\$base['account_id'] = \$hasAccount ? \$accountId : NULL;", $health);
    $this->assertStringContainsString("{{ 'Store'|t }}", $template);
    $this->assertStringContainsString("{{ 'Stripe account ID'|t }}", $template);
    $this->assertStringContainsString('{% if health.account_id %}', $template);
  }

  public function testRefundReviewActionTargetsAnAccessibleEventQueue(): void {
    $builder = file_get_contents(
      $this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHubBuilder.php',
    );
    $template = file_get_contents(
      $this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/payments-hub.html.twig',
    );
    $this->assertIsString($builder);
    $this->assertIsString($template);
    $this->assertStringContainsString(
      "'myeventlane_refunds.vendor_refund_requests'",
      $builder,
    );
    $this->assertStringContainsString('checkNamedRoute(', $builder);
    $this->assertStringContainsString("(\$request['status'] ?? '') !== 'requested'", $builder);
    $this->assertStringContainsString("'review_url' => \$reviewUrl", $builder);
    $this->assertStringContainsString('refunds.review_url', $template);
    $this->assertStringNotContainsString('refunds.hub_url', $template);
  }

  public function testThemeSupportsWideDesktopAndMobileStacking(): void {
    $styles = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_payments-hub.scss');
    $this->assertIsString($styles);
    $this->assertStringContainsString('.mel-payments-hub__money-workspace', $styles);
    $this->assertStringContainsString('@media (min-width: 960px)', $styles);
    $this->assertStringContainsString('.mel-payments-hub__tool', $styles);
    $this->assertStringContainsString('min-height: 52px', $styles);
    $this->assertStringContainsString('.mel-payments-hub__health.mel-card--status', $styles);
    $this->assertStringContainsString('flex: 1 1 auto', $styles);

    $theme = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    $this->assertIsString($theme);
    $this->assertStringContainsString("\$has_page_level_payment_health = str_starts_with(\$current_path, '/vendor/support')", $theme);
    $this->assertStringContainsString("'myeventlane_vendor.console.payments'", $theme);
  }

  public function testLinkedTaxAndBillingRoutesRemainUsableForOrganisers(): void {
    $basController = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_finance/src/Controller/VendorBasController.php');
    $this->assertIsString($basController);
    $this->assertStringContainsString("->modify('last day of this month')", $basController);
    $this->assertStringNotContainsString("sprintf('%02d', (int) \$now->format('t'))", $basController);

    $donationAccess = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_donations/src/Access/DonationAccess.php');
    $this->assertIsString($donationAccess);
    $vendorAccess = substr($donationAccess, strpos($donationAccess, 'public function vendorAccess'));
    $this->assertStringContainsString("hasPermission('access vendor console')", $vendorAccess);
    $this->assertStringNotContainsString('isVendorDomain()', $vendorAccess);

    $billingController = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_donations/src/Controller/VendorMelContributionBillingController.php');
    $this->assertIsString($billingController);
    $this->assertStringContainsString("'payment_controls_available' => FALSE", $billingController);
    $this->assertStringContainsString('catch (\\Throwable $e)', $billingController);
  }

}
