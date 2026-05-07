<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_attendees\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManagerInterface;
use Drupal\myeventlane_event_attendees\Service\MelAttendeeExportBuilder;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationServiceInterface;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_attendees\Service\MelAttendeeExportBuilder
 *
 * @group myeventlane_event_attendees
 */
final class MelAttendeeExportBuilderTest extends UnitTestCase {

  /**
   * @covers ::sanitizeCell
   */
  public function testFormulaInjectionPrefixesAreNeutralised(): void {
    $builder = $this->builder();
    foreach (['=cmd|', '+1+1', '-2', '@SUM', "\tcmd", "\rfoo"] as $payload) {
      $sanitised = $builder->sanitizeCell($payload);
      $this->assertSame("'" . $payload, $sanitised, sprintf('Payload "%s" should be neutralised.', $payload));
    }
  }

  /**
   * @covers ::sanitizeCell
   */
  public function testSafeCellsArePreserved(): void {
    $builder = $this->builder();
    $this->assertSame('Anna', $builder->sanitizeCell('Anna'));
    $this->assertSame('a@b.com', $builder->sanitizeCell('a@b.com'));
    $this->assertSame('Yes', $builder->sanitizeCell('Yes'));
    $this->assertSame('', $builder->sanitizeCell(''));
  }

  /**
   * @covers ::getHeaders
   */
  public function testHeadersAreCanonical(): void {
    $headers = $this->builder()->getHeaders();
    $this->assertSame([
      'Name',
      'Email',
      'Phone',
      'Source',
      'Ticket type',
      'Ticket code',
      'Custom answers',
      'Operational state',
      'Checked in',
      'Checked in at',
      'Registered',
    ], $headers);
  }

  /**
   * @covers ::resolveOperationalState
   */
  public function testOperationalStateMatchesEnumVocabulary(): void {
    $builder = $this->builder();
    $this->assertSame(
      MelAttendeeExportBuilder::STATE_CHECKED_IN,
      $builder->resolveOperationalState($this->mockAttendee(EventAttendee::STATUS_CHECKED_IN)),
    );
    $this->assertSame(
      MelAttendeeExportBuilder::STATE_CANCELLED,
      $builder->resolveOperationalState($this->mockAttendee(EventAttendee::STATUS_CANCELLED)),
    );
    $this->assertSame(
      MelAttendeeExportBuilder::STATE_REGISTERED,
      $builder->resolveOperationalState($this->mockAttendee(EventAttendee::STATUS_CONFIRMED)),
    );
    $this->assertSame(
      MelAttendeeExportBuilder::STATE_PENDING,
      $builder->resolveOperationalState($this->mockAttendee(EventAttendee::STATUS_WAITLIST)),
    );
    $this->assertSame(
      MelAttendeeExportBuilder::STATE_REFUNDED,
      $builder->resolveOperationalState($this->mockAttendee(EventAttendee::STATUS_CONFIRMED), $this->mockOrderItem('refunded')),
    );
    $this->assertSame(
      MelAttendeeExportBuilder::STATE_INVALID,
      $builder->resolveOperationalState($this->mockAttendee('garbage')),
    );
  }

  /**
   * @covers ::buildRow
   */
  public function testBuildRowComposesCanonicalShape(): void {
    $vendorPresentation = $this->createMock(VendorAttendeePresentationServiceInterface::class);
    $vendorPresentation->method('buildCsvExportRow')->willReturn([
      'name' => '=Hacker',
      'email' => 'a@b.com',
      'phone' => '+1234',
      'source' => 'ticket',
      'ticket_type' => 'General',
      'ticket_code' => 'ABC-1',
      'custom_answers' => 'Q1: Veg',
      'checked_in' => FALSE,
      'checked_in_at' => '',
    ]);

    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturn('2026-05-07 12:00:00');

    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CONFIRMED);
    $attendee->method('hasField')->willReturnMap([
      ['order_item', FALSE],
    ]);
    $attendee->method('getCreatedTime')->willReturn(1700000000);

    $builder = new MelAttendeeExportBuilder(
      $this->createMock(AttendanceManagerInterface::class),
      $vendorPresentation,
      $dateFormatter,
      $this->createMock(LoggerChannelInterface::class),
      $this->getStringTranslationStub(),
    );

    $row = $builder->buildRow($attendee);
    $this->assertCount(11, $row);
    $this->assertSame("'=Hacker", $row[0], 'Formula prefix in name should be neutralised.');
    $this->assertSame('a@b.com', $row[1]);
    // Phone starts with "+" — must be neutralised too.
    $this->assertSame("'+1234", $row[2]);
    $this->assertSame('Ticket purchase', $row[3]);
    $this->assertSame('General', $row[4]);
    $this->assertSame('ABC-1', $row[5]);
    $this->assertSame('Q1: Veg', $row[6]);
    $this->assertSame('Registered', $row[7]);
    $this->assertSame('No', $row[8]);
    $this->assertSame('', $row[9]);
    $this->assertSame('2026-05-07 12:00:00', $row[10]);
  }

  /**
   * @covers ::buildRow
   */
  public function testEmailObfuscation(): void {
    $vendorPresentation = $this->createMock(VendorAttendeePresentationServiceInterface::class);
    $vendorPresentation->method('buildCsvExportRow')->willReturn([
      'name' => 'Anna',
      'email' => 'anna@example.com',
      'phone' => '',
      'source' => 'ticket',
      'ticket_type' => '',
      'ticket_code' => '',
      'custom_answers' => '',
      'checked_in' => FALSE,
      'checked_in_at' => '',
    ]);

    $attendee = $this->createMock(EventAttendee::class);
    $attendee->method('getStatus')->willReturn(EventAttendee::STATUS_CONFIRMED);
    $attendee->method('hasField')->willReturn(FALSE);
    $attendee->method('getCreatedTime')->willReturn(0);

    $builder = new MelAttendeeExportBuilder(
      $this->createMock(AttendanceManagerInterface::class),
      $vendorPresentation,
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->getStringTranslationStub(),
    );

    $row = $builder->buildRow($attendee, TRUE);
    $this->assertSame('an***@example.com', $row[1]);
  }

  /**
   * @covers ::renderCsvString
   */
  public function testRenderCsvIncludesBomAndHeaders(): void {
    $rendered = $this->builder()->renderCsvString([]);
    $this->assertStringStartsWith(MelAttendeeExportBuilder::BOM, $rendered);
    $this->assertStringContainsString('Name,Email,Phone', $rendered);
  }

  /**
   * @covers ::buildFilename
   */
  public function testFilenameIsFilesystemSafe(): void {
    $event = $this->createMock(\Drupal\node\NodeInterface::class);
    $event->method('label')->willReturn('Café Saturday Night/Out!');
    $event->method('id')->willReturn('42');
    $name = $this->builder()->buildFilename($event, 'attendees');
    $this->assertMatchesRegularExpression('/^attendees-[a-z0-9-]+-\d{4}-\d{2}-\d{2}\.csv$/', $name);
    $this->assertStringNotContainsString('/', $name);
    $this->assertStringNotContainsString(' ', $name);
  }

  private function builder(): MelAttendeeExportBuilder {
    return new MelAttendeeExportBuilder(
      $this->createMock(AttendanceManagerInterface::class),
      $this->createMock(VendorAttendeePresentationServiceInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->getStringTranslationStub(),
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
