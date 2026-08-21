<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

require_once dirname(__DIR__, 3) . '/src/Policy/DirectChargeCopy.php';

use Drupal\myeventlane_core\Policy\DirectChargeCopy;
use PHPUnit\Framework\TestCase;

/**
 * Guards approved direct-charge wording on active customer-facing surfaces.
 */
final class DirectChargeCopyContractTest extends TestCase {

  /**
   * Active rendering and distribution files covered by the Stage 14 rescan.
   */
  private const ACTIVE_SURFACES = [
    'config/sync/myeventlane_help_centre.help_content.yml',
    'config/sync/myeventlane_messaging.template.vendor_event_cancellation.yml',
    'web/modules/custom/myeventlane_core/src/Form/GeneralSettingsForm.php',
    'web/modules/custom/myeventlane_event_studio/templates/mel-event-studio.html.twig',
    'web/modules/custom/myeventlane_legal/src/Service/LegalPolicyPageContent.php',
    'web/modules/custom/myeventlane_refunds/src/Form/VendorRefundRequestApproveForm.php',
    'web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml',
    'web/modules/custom/myeventlane_vendor/src/Controller/VendorDashboardController.php',
    'web/modules/custom/myeventlane_vendor/src/Controller/VendorOnboardStripeController.php',
    'web/modules/custom/myeventlane_vendor/src/Controller/VendorPayoutsController.php',
    'web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php',
    'web/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php',
    'web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHealthService.php',
    'web/modules/custom/myeventlane_vendor/src/Service/VendorPaymentsHubBuilder.php',
    'web/modules/custom/myeventlane_vendor/src/Service/VendorSupportHubBuilder.php',
  ];

  public function testCanonicalSourceRetainsApprovedMeanings(): void {
    self::assertStringContainsString('event organiser is the seller', DirectChargeCopy::CUSTOMER_SELLER);
    self::assertStringContainsString('connected Stripe account', DirectChargeCopy::PAYMENT);
    self::assertStringContainsString('does not hold or manually release', DirectChargeCopy::PAYMENT);
    self::assertStringContainsString('refunded money comes from your connected Stripe account', DirectChargeCopy::REFUND);
    self::assertStringContainsString('cannot release a payout or change Stripe', DirectChargeCopy::STRIPE_PAYOUT);
  }

  public function testActiveSurfacesContainNoHighRiskLegacyPromises(): void {
    $root = dirname(__DIR__, 7);
    $prohibited = [
      '/\b(?:MEL|MyEventLane)\s+(?:pays?|payouts?|releases?|holds?|transfers?)\b/i',
      '/\brequest\s+(?:your\s+|a\s+)?payout\b/i',
      '/\bwithdraw\s+(?:your\s+)?(?:MEL\s+)?balance\b/i',
      '/\bfunds?\s+held\s+by\s+(?:MEL|MyEventLane)\b/i',
      '/\bdeducted\s+from\s+(?:the\s+)?(?:vendor|organiser)\s+payout\b/i',
      '/\bpending\s+MEL\s+payout\b/i',
    ];

    foreach (self::ACTIVE_SURFACES as $relativePath) {
      $source = file_get_contents($root . '/' . $relativePath);
      self::assertIsString($source, $relativePath);
      foreach ($prohibited as $pattern) {
        self::assertDoesNotMatchRegularExpression($pattern, $source, $relativePath);
      }
    }
  }

  public function testCanonicalCopyIsReusedAtCriticalDecisionPoints(): void {
    $root = dirname(__DIR__, 7);
    foreach ([
      'web/modules/custom/myeventlane_vendor/src/Controller/VendorOnboardStripeController.php',
      'web/modules/custom/myeventlane_vendor/src/Service/PaidPublishStripeGate.php',
      'web/modules/custom/myeventlane_refunds/src/Form/VendorRefundRequestApproveForm.php',
      'web/modules/custom/myeventlane_legal/src/Service/LegalPolicyPageContent.php',
    ] as $relativePath) {
      $source = file_get_contents($root . '/' . $relativePath);
      self::assertIsString($source);
      self::assertStringContainsString('DirectChargeCopy::', $source, $relativePath);
    }
  }

}
