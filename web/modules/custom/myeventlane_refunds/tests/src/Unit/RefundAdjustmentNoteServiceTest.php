<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

require_once dirname(__DIR__, 3) . '/src/Service/RefundAdjustmentNoteService.php';

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_refunds\Service\RefundAdjustmentNoteService;
use PHPUnit\Framework\TestCase;

/**
 * Tests GST reversal calculations for confirmed refunds.
 *
 * @group myeventlane_refunds
 */
final class RefundAdjustmentNoteServiceTest extends TestCase {

  /**
   * Tests full, partial, gift and untaxed order refunds.
   */
  public function testGstAdjustmentCalculation(): void {
    $service = new RefundAdjustmentNoteService(
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
    );

    self::assertSame(100, $service->calculateGstAdjustmentCents(100, 1100, 1100));
    self::assertSame(50, $service->calculateGstAdjustmentCents(100, 1100, 550));
    self::assertSame(0, $service->calculateGstAdjustmentCents(100, 1100, 0));
    self::assertSame(0, $service->calculateGstAdjustmentCents(0, 1100, 1100));
    self::assertSame(100, $service->calculateGstAdjustmentCents(100, 1100, 1600));
  }

  /**
   * Ensures historical refund tax never follows mutable registration data.
   */
  public function testHistoricalRefundLogicDoesNotUseCurrentRegistration(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/RefundAdjustmentNoteService.php');

    self::assertIsString($source);
    self::assertStringContainsString('sumRecordedTaxCents($order)', $source);
    self::assertStringNotContainsString("get('tax_registrations')", $source);
  }

}
