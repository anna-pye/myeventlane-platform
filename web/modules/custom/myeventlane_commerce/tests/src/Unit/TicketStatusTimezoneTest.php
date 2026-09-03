<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketStatusEvaluator;
use Drupal\myeventlane_commerce\Service\TicketVariationSoldService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\TicketStatusEvaluator
 * @group myeventlane_commerce
 */
final class TicketStatusTimezoneTest extends UnitTestCase {

  /**
   * @covers ::evaluate
   */
  public function testSaleEndsAtTheEventsLocalWallClockInstant(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2026-12-10T09:00:00Z'));
    $sold = (new \ReflectionClass(TicketVariationSoldService::class))->newInstanceWithoutConstructor();

    $this->assertSame(
      TicketStatusEvaluator::STATUS_ENDED,
      TicketStatusEvaluator::evaluate($this->ticket(), $sold, $time, $this->event()),
    );
  }

  /**
   * Creates an active RSVP ticket with a sale end value.
   */
  private function ticket(): TicketTypeInterface {
    $status = $this->valueList('1');
    $saleStart = $this->emptyList();
    $saleEnd = $this->valueList('2026-12-10T18:00:00', TRUE);
    $ticket = $this->createMock(TicketTypeInterface::class);
    $ticket->method('isArchived')->willReturn(FALSE);
    $ticket->method('isPublished')->willReturn(TRUE);
    $ticket->method('getTicketKind')->willReturn('rsvp');
    $ticket->method('get')->willReturnCallback(static fn (string $field) => match ($field) {
      'status' => $status,
      'sale_start' => $saleStart,
      'sale_end' => $saleEnd,
      default => throw new \LogicException('Unexpected field ' . $field),
    });
    return $ticket;
  }

  /**
   * Creates a Brisbane event mock.
   */
  private function event(): NodeInterface {
    $timezone = $this->valueList('Australia/Brisbane');
    $location = $this->emptyList();
    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array(
        $field,
        ['field_series_timezone', 'field_location'],
        TRUE,
      ),
    );
    $event->method('get')->willReturnCallback(
      static fn (string $field) => $field === 'field_series_timezone'
        ? $timezone
        : $location,
    );
    return $event;
  }

  /**
   * Creates a populated field list and optional first item.
   */
  private function valueList(string $value, bool $withFirst = FALSE): FieldItemListInterface {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->method('__get')->with('value')->willReturn($value);
    $list->method('getValue')->willReturn([['value' => $value]]);
    if ($withFirst) {
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('__get')->with('value')->willReturn($value);
      $list->method('first')->willReturn($item);
    }
    return $list;
  }

  /**
   * Creates an empty field item list mock.
   */
  private function emptyList(): FieldItemListInterface {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(TRUE);
    return $list;
  }

}
