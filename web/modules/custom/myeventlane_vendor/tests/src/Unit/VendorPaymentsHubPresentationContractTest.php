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

  public function testBuilderExplainsBalancesAndUsesPayoutAwareEmptyStates(): void {
    $builder = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHubBuilder.php');
    $this->assertIsString($builder);
    $this->assertStringContainsString("'available_help'", $builder);
    $this->assertStringContainsString("'pending_help'", $builder);
    $this->assertStringContainsString("'net_help'", $builder);
    $this->assertStringContainsString('Payouts are not enabled yet', $builder);
    $this->assertStringContainsString('No payout has arrived yet', $builder);
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
