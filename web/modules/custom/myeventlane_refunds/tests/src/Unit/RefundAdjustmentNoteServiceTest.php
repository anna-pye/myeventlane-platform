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
   * Tests taxable, gift, mixed and unregistered supplier refunds.
   */
  public function testGstAdjustmentCalculation(): void {
    $service = new RefundAdjustmentNoteService(
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
    );

    self::assertSame(100, $service->calculateGstAdjustmentCents(TRUE, 1100, 0, 'tickets_only'));
    self::assertSame(0, $service->calculateGstAdjustmentCents(TRUE, 1100, 0, 'donation_only'));
    self::assertSame(100, $service->calculateGstAdjustmentCents(TRUE, 1600, 500, 'tickets_and_donation'));
    self::assertSame(0, $service->calculateGstAdjustmentCents(FALSE, 1100, 0, 'tickets_only'));
  }

}
