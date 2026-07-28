<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the organiser-facing payout page hierarchy.
 *
 * @group myeventlane_vendor
 */
final class VendorPayoutsPresentationContractTest extends TestCase {

  /**
   * Ensures the page keeps one clear payout action and explains its data.
   */
  public function testPayoutTemplateKeepsClearHierarchy(): void {
    $template = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_vendor_theme/templates/payouts.html.twig');

    self::assertIsString($template);
    self::assertStringContainsString('Payout status', $template);
    self::assertStringContainsString('Payout balances', $template);
    self::assertStringContainsString('Recent ticket transactions', $template);
    self::assertStringContainsString('Bank transfers are shown in the payout balance above.', $template);
    self::assertStringNotContainsString("{{ 'Actions'|t }}", $template);
  }

}
