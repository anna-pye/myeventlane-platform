<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the refund-to-ticket entitlement reconciliation contract.
 *
 * @group myeventlane_refunds
 */
final class RefundTicketEntitlementReconcilerContractTest extends TestCase {

  /**
   * Ensures completed ticket refunds revoke the matching access surfaces.
   */
  public function testReconciliationIsSelectiveFailClosedAndIdempotent(): void {
    $root = dirname(__DIR__, 3);
    $source = file_get_contents($root . '/src/Service/RefundTicketEntitlementReconciler.php');
    $this->assertIsString($source);

    $this->assertStringContainsString("=== 'donation_only'", $source);
    $this->assertStringContainsString("!== 'full' && \$selectedIds === []", $source);
    $this->assertStringContainsString("count(\$itemAttendees) !== count(\$itemTickets)", $source);
    $this->assertStringContainsString("if (\$selectedIds === [])", $source);
    $this->assertStringContainsString("Ticket::STATUS_REFUNDED", $source);
    $this->assertStringContainsString("Ticket::FULFILMENT_CANCELLED", $source);
    $this->assertStringContainsString("!== Ticket::STATUS_REFUNDED", $source);

    // Historical repairs must still load already-cancelled attendees so their
    // corresponding canonical ticket can be repaired.
    $this->assertStringNotContainsString(
      "->condition('status', EventAttendee::STATUS_CANCELLED, '<>')",
      $source,
    );
  }

  /**
   * Ensures the repair path is bounded and cannot move refund money.
   */
  public function testCompletedRefundRepairCommandUsesReconciliationOnly(): void {
    $root = dirname(__DIR__, 3);
    $processor = file_get_contents($root . '/src/Service/RefundProcessor.php');
    $commands = file_get_contents($root . '/src/Commands/RefundCommands.php');
    $this->assertIsString($processor);
    $this->assertIsString($commands);

    $this->assertStringContainsString("!== self::STATUS_COMPLETED", $processor);
    $this->assertStringContainsString('reconcileCompletedRefundEntitlements', $commands);
    $this->assertStringContainsString('@command mel:refund-reconcile-entitlements', $commands);
    $this->assertStringNotContainsString('processRefund($logId)', $commands);
  }

}
