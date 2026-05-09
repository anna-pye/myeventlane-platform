<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager
 *
 * @group myeventlane_checkout_flow
 */
final class MelAttendeeCheckinManagerTest extends UnitTestCase {

  /**
   * @covers ::checkIn
   */
  public function testCheckInWithoutEventFailsClosed(): void {
    $manager = $this->mockManager();
    $access = $this->mockAccess(allowed: TRUE);
    $checkin = $this->makeCheckin($manager, $access);

    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn(NULL);

    $result = $checkin->checkIn($attendee, $this->mockActor(7));
    $this->assertFalse($result['success']);
    $this->assertSame('no_event', $result['reason']);
  }

  /**
   * @covers ::checkIn
   */
  public function testCheckInDeniedByAccess(): void {
    $manager = $this->mockManager();
    $access = $this->mockAccess(allowed: FALSE);
    $checkin = $this->makeCheckin($manager, $access);

    $event = $this->mockEvent('event');
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CONFIRMED);
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('id')->willReturn(123);

    $result = $checkin->checkIn($attendee, $this->mockActor(7));
    $this->assertFalse($result['success']);
    $this->assertSame('forbidden', $result['reason']);
  }

  /**
   * @covers ::checkIn
   */
  public function testIdempotentWhenAlreadyCheckedIn(): void {
    $manager = $this->mockManager();
    $manager->expects($this->never())->method('checkIn');
    $access = $this->mockAccess(allowed: TRUE);
    $checkin = $this->makeCheckin($manager, $access);

    $event = $this->mockEvent('event');
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CHECKED_IN);
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('id')->willReturn(45);

    $result = $checkin->checkIn($attendee, $this->mockActor(7));
    $this->assertTrue($result['success']);
    $this->assertFalse($result['transitioned']);
    $this->assertSame('already_checked_in', $result['reason']);
  }

  /**
   * @covers ::checkIn
   */
  public function testRefundedRowCannotCheckIn(): void {
    $manager = $this->mockManager();
    $access = $this->mockAccess(allowed: TRUE);
    $checkin = $this->makeCheckin($manager, $access);

    $event = $this->mockEvent('event');
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CANCELLED);
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('id')->willReturn(99);

    $result = $checkin->checkIn($attendee, $this->mockActor(7));
    $this->assertFalse($result['success']);
    $this->assertSame('cancelled', $result['state']);
  }

  /**
   * @covers ::checkIn
   */
  public function testSuccessfulTransition(): void {
    $manager = $this->mockManager();
    $manager->expects($this->once())->method('checkIn')->willReturn(TRUE);
    $access = $this->mockAccess(allowed: TRUE);
    $checkin = $this->makeCheckin($manager, $access);

    $event = $this->mockEvent('event');
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CONFIRMED);
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('id')->willReturn(50);

    $result = $checkin->checkIn($attendee, $this->mockActor(7));
    $this->assertTrue($result['success']);
    $this->assertTrue($result['transitioned']);
    $this->assertSame('checked_in', $result['state']);
    $this->assertStringStartsWith('mel.attendee.checkin.', $result['audit_id']);
  }

  /**
   * @covers ::reverseCheckIn
   */
  /**
   * @covers ::checkInAttendee
   */
  public function testCheckInAttendeeDelegatesToCheckIn(): void {
    $manager = $this->mockManager();
    $manager->expects($this->once())->method('checkIn')->willReturn(TRUE);
    $access = $this->mockAccess(allowed: TRUE);
    $checkin = $this->makeCheckin($manager, $access);

    $event = $this->mockEvent('event');
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CONFIRMED);
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('id')->willReturn(50);

    $out = $checkin->checkInAttendee($attendee, $this->mockActor(7), 'vendor_list');
    $this->assertTrue($out['success']);
    $this->assertTrue($out['transitioned']);
  }

  /**
   * @covers ::reverseCheckIn
   */
  public function testReverseCheckInDeniedByAccess(): void {
    $manager = $this->mockManager();
    $access = $this->mockAccess(allowed: FALSE);
    $checkin = $this->makeCheckin($manager, $access);

    $event = $this->mockEvent('event');
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getEvent')->willReturn($event);

    $result = $checkin->reverseCheckIn($attendee, $this->mockActor(2));
    $this->assertFalse($result['success']);
    $this->assertSame('forbidden', $result['reason']);
  }

  private function makeCheckin(
    AttendanceManagerInterface $manager,
    MelAttendeeOperationsAccessInterface $access,
  ): MelAttendeeCheckinManager {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    return new MelAttendeeCheckinManager(
      $manager,
      $access,
      $time,
      $this->createMock(LoggerChannelInterface::class),
      $etm,
    );
  }

  private function mockManager(): AttendanceManagerInterface {
    return $this->createMock(AttendanceManagerInterface::class);
  }

  private function mockAccess(bool $allowed): MelAttendeeOperationsAccessInterface {
    $access = $this->createMock(MelAttendeeOperationsAccessInterface::class);
    $access->method('canCheckInAttendees')
      ->willReturn($allowed ? AccessResult::allowed() : AccessResult::forbidden());
    return $access;
  }

  private function mockEvent(string $bundle): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn($bundle);
    $event->method('id')->willReturn(1);
    return $event;
  }

  private function mockActor(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    return $account;
  }

}
