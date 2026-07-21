<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use Drupal\myeventlane_refunds\Access\RefundRequestRouteBinder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for refund-request route-event binding.
 *
 * @coversDefaultClass \Drupal\myeventlane_refunds\Access\RefundRequestRouteBinder
 *
 * @group myeventlane_refunds
 */
final class VendorRefundRequestAccessCheckBindingTest extends TestCase {

  /**
   * Matching refund request and event is allowed.
   */
  public function testMatchingRefundRequestAllowed(): void {
    $this->assertTrue(RefundRequestRouteBinder::requestBelongsToEvent(
      ['id' => 7, 'event_id' => 100],
      100,
    ));
  }

  /**
   * Route-event / refund-request mismatch is denied.
   */
  public function testMismatchedRefundRequestDenied(): void {
    $this->assertFalse(RefundRequestRouteBinder::requestBelongsToEvent(
      ['id' => 7, 'event_id' => 999],
      100,
    ));
  }

  /**
   * Missing refund request fails closed.
   */
  public function testMissingRefundRequestDenied(): void {
    $this->assertFalse(RefundRequestRouteBinder::requestBelongsToEvent(NULL, 100));
  }

  /**
   * Invalid event id fails closed.
   */
  public function testInvalidEventIdDenied(): void {
    $this->assertFalse(RefundRequestRouteBinder::requestBelongsToEvent(
      ['id' => 7, 'event_id' => 100],
      0,
    ));
  }

}
