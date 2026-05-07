<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsPresenter;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsPresenter
 *
 * @group myeventlane_checkout_flow
 */
final class MelAttendeeOperationsPresenterTest extends UnitTestCase {

  /**
   * @covers ::buildEventViewModel
   */
  public function testEmptyEventReturnsCanonicalShape(): void {
    $manager = $this->createMock(AttendanceManagerInterface::class);
    $manager->method('getAttendeesForEvent')->willReturn([]);

    $presenter = $this->presenter($manager);
    $vm = $presenter->buildEventViewModel($this->mockEvent(99, 'Test event'));

    $this->assertSame(99, $vm['event']['id']);
    $this->assertSame('Test event', $vm['event']['title']);
    $this->assertSame([], $vm['rows']);
    $this->assertSame(['tickets' => [], 'rsvp' => [], 'manual' => []], $vm['groups']);
    $this->assertSame(0, $vm['readiness']['total']);
    $this->assertSame(0, $vm['readiness']['active']);
    $this->assertNotEmpty($vm['filters']['available_states']);
    $this->assertContains('user', $vm['cache']['contexts']);
    $this->assertContains('event_attendee_list:99', $vm['cache']['tags']);
  }

  /**
   * @covers ::buildEventViewModel
   * @covers ::buildRow
   */
  public function testGroupingAndReadinessCounts(): void {
    $rsvpRow = $this->mockAttendee(EventAttendee::SOURCE_RSVP, EventAttendee::STATUS_CONFIRMED);
    $checkedRow = $this->mockAttendee(EventAttendee::SOURCE_TICKET, EventAttendee::STATUS_CHECKED_IN);
    $cancelled = $this->mockAttendee(EventAttendee::SOURCE_TICKET, EventAttendee::STATUS_CANCELLED);
    $manualRow = $this->mockAttendee(EventAttendee::SOURCE_MANUAL, EventAttendee::STATUS_CONFIRMED);

    $manager = $this->createMock(AttendanceManagerInterface::class);
    $manager->method('getAttendeesForEvent')->willReturn([$rsvpRow, $checkedRow, $cancelled, $manualRow]);

    $vendorPresentation = $this->createMock(VendorAttendeePresentationServiceInterface::class);
    $vendorPresentation->method('buildVendorRowFromEventAttendee')->willReturnCallback(
      function (EventAttendee $a): array {
        return [
          'full_name' => 'Anna ' . $a->getSource(),
          'email' => 'a@b.com',
          'phone' => '',
          'ticket_type' => 'General',
          'ticket_code' => 'CODE-' . $a->getSource() . '-' . $a->getStatus(),
          'source' => $a->getSource(),
          'custom_answers' => [],
          'custom_answers_display' => '',
        ];
      }
    );

    $presenter = $this->presenter($manager, $vendorPresentation);
    $vm = $presenter->buildEventViewModel($this->mockEvent(7, 'Sample'));

    $this->assertSame(4, $vm['readiness']['total']);
    // Active = registered + checked_in. Two STATUS_CONFIRMED rows and one
    // STATUS_CHECKED_IN row count toward active; the cancelled one does not.
    $this->assertSame(3, $vm['readiness']['active'], 'registered + checked_in count as active');
    $this->assertSame(1, $vm['readiness']['checked_in']);
    $this->assertSame(1, $vm['readiness']['cancelled']);
    $this->assertCount(1, $vm['groups']['rsvp']);
    $this->assertCount(1, $vm['groups']['manual']);
    $this->assertGreaterThanOrEqual(1, count($vm['groups']['tickets']));
  }

  /**
   * @covers ::buildEventViewModel
   */
  public function testSearchFilterNarrowsRows(): void {
    $rsvp = $this->mockAttendee(EventAttendee::SOURCE_RSVP, EventAttendee::STATUS_CONFIRMED);
    $manager = $this->createMock(AttendanceManagerInterface::class);
    $manager->method('getAttendeesForEvent')->willReturn([$rsvp]);

    $vendorPresentation = $this->createMock(VendorAttendeePresentationServiceInterface::class);
    $vendorPresentation->method('buildVendorRowFromEventAttendee')->willReturn([
      'full_name' => 'Anna Smith',
      'email' => 'anna@example.com',
      'phone' => '',
      'ticket_type' => '',
      'ticket_code' => '',
      'source' => EventAttendee::SOURCE_RSVP,
      'custom_answers' => [],
      'custom_answers_display' => '',
    ]);

    $presenter = $this->presenter($manager, $vendorPresentation);
    $vmHit = $presenter->buildEventViewModel($this->mockEvent(7, 'X'), ['search' => 'anna']);
    $this->assertCount(1, $vmHit['rows']);

    $vmMiss = $presenter->buildEventViewModel($this->mockEvent(7, 'X'), ['search' => 'zoe']);
    $this->assertCount(0, $vmMiss['rows']);
  }

  /**
   * @covers ::buildEventViewModel
   */
  public function testStateFilterNarrowsRows(): void {
    $confirmed = $this->mockAttendee(EventAttendee::SOURCE_TICKET, EventAttendee::STATUS_CONFIRMED);
    $checkedIn = $this->mockAttendee(EventAttendee::SOURCE_TICKET, EventAttendee::STATUS_CHECKED_IN);
    $manager = $this->createMock(AttendanceManagerInterface::class);
    $manager->method('getAttendeesForEvent')->willReturn([$confirmed, $checkedIn]);

    $vendorPresentation = $this->createMock(VendorAttendeePresentationServiceInterface::class);
    $vendorPresentation->method('buildVendorRowFromEventAttendee')->willReturnCallback(
      static fn (EventAttendee $a): array => [
        'full_name' => 'A',
        'email' => 'a@b.com',
        'phone' => '',
        'ticket_type' => '',
        'ticket_code' => 'C-' . $a->getStatus(),
        'source' => $a->getSource(),
        'custom_answers' => [],
        'custom_answers_display' => '',
      ]
    );

    $presenter = $this->presenter($manager, $vendorPresentation);
    $vm = $presenter->buildEventViewModel($this->mockEvent(7, 'X'), ['state' => 'checked_in']);
    $this->assertSame(1, count($vm['rows']));
    $this->assertSame('checked_in', $vm['rows'][0]['state']['value']);
  }

  private function presenter(
    AttendanceManagerInterface $manager,
    ?VendorAttendeePresentationServiceInterface $presentation = NULL,
  ): MelAttendeeOperationsPresenter {
    return new MelAttendeeOperationsPresenter(
      $manager,
      $presentation ?? $this->createMock(VendorAttendeePresentationServiceInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->getStringTranslationStub(),
    );
  }

  private function mockAttendee(string $source, string $status): EventAttendee {
    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getSource')->willReturn($source);
    $attendee->method('getStatus')->willReturn($status);
    $attendee->method('id')->willReturn(random_int(1, 100000));
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('getCreatedTime')->willReturn(0);
    return $attendee;
  }

  private function mockEvent(int $id, string $label): NodeInterface {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/events/' . $id);
    $event = $this->createMock(NodeInterface::class);
    $event->method('id')->willReturn((string) $id);
    $event->method('label')->willReturn($label);
    $event->method('toUrl')->willReturn($url);
    return $event;
  }

}
