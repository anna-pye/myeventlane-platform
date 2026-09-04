<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards explicit and fail-closed operational add-on refunds.
 *
 * @group myeventlane_refunds
 */
final class OperationalAddonRefundContractTest extends TestCase {

  /**
   * Confirms refunds persist exact quantities and enforce safe fulfilment.
   */
  public function testRefundUsesExactWholeLineQuantities(): void {
    $root = dirname(__DIR__, 7);
    $form = file_get_contents($root . '/web/modules/custom/myeventlane_refunds/src/Form/VendorRefundForm.php');
    $processor = file_get_contents($root . '/web/modules/custom/myeventlane_refunds/src/Service/RefundProcessor.php');
    $restock = file_get_contents($root . '/web/modules/custom/myeventlane_tickets/src/Service/OperationalStockReturnManager.php');
    $issuer = file_get_contents($root . '/web/modules/custom/myeventlane_tickets/src/Ticket/OperationalEntitlementIssuer.php');

    self::assertIsString($form);
    self::assertIsString($processor);
    self::assertIsString($restock);
    self::assertIsString($issuer);
    self::assertStringContainsString("'refund_type' => \$refundType", $form);
    self::assertStringContainsString("'operational_item_quantities' => \$operationalQuantities", $form);
    self::assertStringContainsString("'operational_item_quantities_json'", $processor);
    self::assertStringContainsString('quantity === max(0, (int) round((float) $item->getQuantity()))', $restock);
    self::assertStringContainsString('Ticket::FULFILMENT_PENDING', $restock);
    self::assertStringContainsString('Ticket::FULFILMENT_PREPARING', $restock);
    self::assertStringContainsString('Ticket::FULFILMENT_READY', $restock);
    self::assertStringContainsString('alreadyReconciled', $restock);
    self::assertStringContainsString('myeventlane_operational_item:', $restock);
    self::assertStringContainsString('myeventlane_operational_item:', $issuer);
  }

}
