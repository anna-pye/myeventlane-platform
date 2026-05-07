<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\myeventlane_checkout_flow\MelAttendeeAttendanceState;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\MelAttendeeAttendanceState
 *
 * @group myeventlane_checkout_flow
 */
final class MelAttendeeAttendanceStateTest extends UnitTestCase {

  /**
   * @covers ::fromEventAttendee
   */
  public function testCheckedInStatusMapsToCheckedIn(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CHECKED_IN);
    $this->assertSame(
      MelAttendeeAttendanceState::CheckedIn,
      MelAttendeeAttendanceState::fromEventAttendee($attendee),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testCancelledStatusMapsToCancelled(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CANCELLED);
    $this->assertSame(
      MelAttendeeAttendanceState::Cancelled,
      MelAttendeeAttendanceState::fromEventAttendee($attendee),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testRefundedOrderStateMapsToRefunded(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CONFIRMED);
    $orderItem = $this->mockOrderItem('refunded');
    $this->assertSame(
      MelAttendeeAttendanceState::Refunded,
      MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testPartiallyRefundedOrderStateMapsToRefunded(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CONFIRMED);
    $orderItem = $this->mockOrderItem('partially_refunded');
    $this->assertSame(
      MelAttendeeAttendanceState::Refunded,
      MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testCancelledOrderStateMapsToCancelled(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CONFIRMED);
    $orderItem = $this->mockOrderItem('canceled');
    $this->assertSame(
      MelAttendeeAttendanceState::Cancelled,
      MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testDraftOrderStateMapsToPending(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CONFIRMED);
    $orderItem = $this->mockOrderItem('draft');
    $this->assertSame(
      MelAttendeeAttendanceState::Pending,
      MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testCompletedOrderWithConfirmedStatusMapsToRegistered(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_CONFIRMED);
    $orderItem = $this->mockOrderItem('completed');
    $this->assertSame(
      MelAttendeeAttendanceState::Registered,
      MelAttendeeAttendanceState::fromEventAttendee($attendee, $orderItem),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testWaitlistMapsToPending(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_WAITLIST);
    $this->assertSame(
      MelAttendeeAttendanceState::Pending,
      MelAttendeeAttendanceState::fromEventAttendee($attendee),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testNoShowMapsToCancelled(): void {
    $attendee = $this->mockAttendee(EventAttendee::STATUS_NO_SHOW);
    $this->assertSame(
      MelAttendeeAttendanceState::Cancelled,
      MelAttendeeAttendanceState::fromEventAttendee($attendee),
    );
  }

  /**
   * @covers ::fromEventAttendee
   */
  public function testUnknownStatusFallsBackToInvalid(): void {
    $attendee = $this->mockAttendee('garbage');
    $this->assertSame(
      MelAttendeeAttendanceState::Invalid,
      MelAttendeeAttendanceState::fromEventAttendee($attendee),
    );
  }

  /**
   * @covers ::countsAsActive
   * @covers ::isCheckInEligible
   * @covers ::isSettled
   */
  public function testHelperPredicates(): void {
    $this->assertTrue(MelAttendeeAttendanceState::Registered->countsAsActive());
    $this->assertTrue(MelAttendeeAttendanceState::CheckedIn->countsAsActive());
    $this->assertFalse(MelAttendeeAttendanceState::Pending->countsAsActive());
    $this->assertFalse(MelAttendeeAttendanceState::Refunded->countsAsActive());

    $this->assertTrue(MelAttendeeAttendanceState::Registered->isCheckInEligible());
    $this->assertFalse(MelAttendeeAttendanceState::CheckedIn->isCheckInEligible());
    $this->assertFalse(MelAttendeeAttendanceState::Cancelled->isCheckInEligible());

    $this->assertTrue(MelAttendeeAttendanceState::CheckedIn->isSettled());
    $this->assertTrue(MelAttendeeAttendanceState::Cancelled->isSettled());
    $this->assertFalse(MelAttendeeAttendanceState::Pending->isSettled());
  }

  /**
   * @covers ::all
   * @covers ::readinessId
   */
  public function testAllStatesEnumerated(): void {
    $all = MelAttendeeAttendanceState::all();
    $values = array_map(static fn ($s) => $s->value, $all);
    sort($values);
    $this->assertSame(
      ['cancelled', 'checked_in', 'invalid', 'pending', 'refunded', 'registered'],
      $values,
    );
    $this->assertSame(
      'attendee.operational.state.checked_in',
      MelAttendeeAttendanceState::CheckedIn->readinessId(),
    );
  }

  private function mockAttendee(string $status): EventAttendee {
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getStatus')->willReturn($status);
    return $attendee;
  }

  private function mockOrderItem(string $orderStateId): OrderItemInterface {
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn($orderStateId);
    $order = $this->createMock(OrderInterface::class);
    $order->method('getState')->willReturn($state);
    $orderItem = $this->createMock(OrderItemInterface::class);
    $orderItem->method('getOrder')->willReturn($order);
    return $orderItem;
  }

}
