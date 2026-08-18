<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards historical GST presentation against mutable store registration data.
 *
 * @group myeventlane_checkout_flow
 */
final class TaxInvoiceHistoricalGstTest extends TestCase {

  public function testInvoiceStatusUsesTaxRecordedOnOrder(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/TaxInvoicePresentationBuilder.php');

    self::assertIsString($source);
    self::assertStringContainsString("\$isTaxInvoice = \$aggregated['tax_rows'] !== [];", $source);
    self::assertStringNotContainsString("get('tax_registrations')", $source);
  }

}
