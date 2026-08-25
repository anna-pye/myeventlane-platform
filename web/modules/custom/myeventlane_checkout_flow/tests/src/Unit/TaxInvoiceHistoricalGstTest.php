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

  /**
   * Invoice state must use order-time tax and immutable supplier snapshots.
   */
  public function testInvoiceStatusUsesTaxRecordedOnOrder(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/TaxInvoicePresentationBuilder.php');
    $platform = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PlatformFeeTaxSnapshotResolver.php');
    $subscriber = file_get_contents(dirname(__DIR__, 3) . '/src/EventSubscriber/SellerIdentitySnapshotSubscriber.php');

    self::assertIsString($source);
    self::assertIsString($platform);
    self::assertIsString($subscriber);
    self::assertStringContainsString("\$isTaxInvoice = \$aggregated['tax_rows'] !== []", $source);
    self::assertStringContainsString(
      "\$order_total_gst = \$aggregated['total_formatted'];",
      $source,
    );
    self::assertStringNotContainsString(
      "\$this->formatPrice(new Price('0', \$orderCurrency))",
      $source,
    );
    self::assertStringNotContainsString("get('tax_registrations')", $source);
    self::assertStringContainsString("\$seller = \$this->sellerIdentity->resolve(\$order);", $source);
    self::assertStringContainsString("\$vendor_name = \$seller['seller_name'];", $source);
    self::assertStringContainsString('PlatformFeeTaxSnapshotResolver::isPlatformFeeSource', $source);
    self::assertStringContainsString("'platform_fee_lines' => \$platform_fee_lines", $source);
    self::assertStringContainsString("public const ORDER_DATA_KEY = 'mel_platform_fee_tax_snapshot';", $platform);
    self::assertStringContainsString("\$order->setData(self::ORDER_DATA_KEY, \$snapshot);", $platform);
    self::assertStringContainsString("\$this->platformFeeTax->capture(\$order);", $subscriber);
  }

  /**
   * Buyer documents must not collapse organiser and MEL supplier charges.
   */
  public function testBuyerDocumentsSeparateOrganiserAndMelSupplierCharges(): void {
    $pdf = file_get_contents(dirname(__DIR__, 6) . '/themes/custom/myeventlane_theme/templates/commerce/mel-tax-invoice-pdf.html.twig');
    $receipt = file_get_contents(dirname(__DIR__, 6) . '/../config/sync/myeventlane_messaging.template.order_receipt.yml');

    self::assertIsString($pdf);
    self::assertIsString($receipt);
    foreach ([$pdf, $receipt] as $document) {
      self::assertStringContainsString('Tickets and organiser charges', $document);
      self::assertStringContainsString('MyEventLane platform charges', $document);
      self::assertStringContainsString('GST included in this fee', $document);
      self::assertStringNotContainsString('The supplier is not registered for GST.', $document);
    }
  }

}
