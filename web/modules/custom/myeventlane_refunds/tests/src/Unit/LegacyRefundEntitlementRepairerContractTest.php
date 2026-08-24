<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the fail-closed legacy refund entitlement repair contract.
 *
 * @group myeventlane_refunds
 */
final class LegacyRefundEntitlementRepairerContractTest extends TestCase {

  /**
   * Ensures reconstruction is attributable, revoked and replay safe.
   */
  public function testRepairIsBoundedRevokedAndIdempotent(): void {
    $root = dirname(__DIR__, 3);
    $source = file_get_contents($root . '/src/Service/LegacyRefundEntitlementRepairer.php');
    $this->assertIsString($source);

    $this->assertStringContainsString("=== 'donation_only'", $source);
    $this->assertStringContainsString("!== 'full' && \$selectedIds === []", $source);
    $this->assertStringContainsString("->condition('order_item', \$orderItemIds, 'IN')", $source);
    $this->assertStringContainsString('attendeeBelongsToOrderItem', $source);
    $this->assertStringContainsString('already has an unmarked canonical ticket', $source);
    $this->assertStringContainsString("'status' => Ticket::STATUS_REFUNDED", $source);
    $this->assertStringContainsString("'fulfilment_status' => Ticket::FULFILMENT_CANCELLED", $source);
    $this->assertStringContainsString("'legacy_refund_repair'", $source);
    $this->assertStringContainsString("\$ticket->get('metadata_json')->first()?->getValue()", $source);
    $this->assertStringContainsString("if (\$apply && \$result['blocked'] === [])", $source);
    $this->assertStringContainsString('$this->database->startTransaction()', $source);
  }

  /**
   * Ensures the operator command cannot move money and defaults to dry-run.
   */
  public function testCommandRequiresExplicitApplyAndOnlyCallsRepairer(): void {
    $root = dirname(__DIR__, 3);
    $commands = file_get_contents($root . '/src/Commands/RefundCommands.php');
    $this->assertIsString($commands);

    $this->assertStringContainsString('@command mel:refund-repair-legacy-entitlements', $commands);
    $this->assertStringContainsString("array \$options = ['apply' => FALSE]", $commands);
    $this->assertStringContainsString("->condition('status', 'completed')", $commands);
    $this->assertStringContainsString('legacyEntitlementRepairer->repair', $commands);
    $this->assertStringNotContainsString('processRefund($logId)', $commands);
  }

}
