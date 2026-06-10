<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Service\EventReadinessService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventReadinessService
 *
 * @group myeventlane_event_studio
 */
final class EventReadinessServiceTest extends UnitTestCase {

  /**
   * @covers ::evaluate
   */
  public function testEvaluateReturnsResultForPaidEventWithoutTickets(): void {
    $service = EventStudioWorkspaceTestFactory::readinessService(
      $this->translator(),
      fn (string $class): object => $this->createMock($class),
    );

    $result = $service->evaluate($this->paidEvent(), $this->account());

    $this->assertInstanceOf(EventReadinessResult::class, $result);
    $this->assertFalse($result->ready);
    $this->assertContains('Add at least one active paid ticket.', $result->errors);
  }

  /**
   * @covers ::evaluate
   */
  public function testEvaluateNeverThrowsWithMinimalDependencies(): void {
    $service = EventStudioWorkspaceTestFactory::readinessService(
      $this->translator(),
      fn (string $class): object => $this->createMock($class),
    );

    $result = $service->evaluate($this->rsvpEvent(), $this->account());

    $this->assertInstanceOf(EventReadinessResult::class, $result);
    $this->assertIsBool($result->ready);
  }

  private function paidEvent(): \Drupal\node\NodeInterface {
    return $this->eventNode('paid');
  }

  private function rsvpEvent(): \Drupal\node\NodeInterface {
    return $this->eventNode('rsvp');
  }

  private function eventNode(string $event_type): \Drupal\node\NodeInterface {
    $start = $this->fieldWithValue('2026-07-01T10:00:00');
    $end = $this->fieldWithValue('2026-07-01T12:00:00');
    $type = $this->fieldWithValue($event_type);
    $image = $this->emptyField();
    $summary = $this->fieldWithValue('Summary');
    $donations = $this->emptyField();
    $accessibility = $this->emptyField();
    $capacity = $this->emptyField();
    $questions = $this->emptyField();
    $ticket_types = $this->emptyField();

    $node = $this->createMock(\Drupal\node\NodeInterface::class);
    $node->method('bundle')->willReturn('event');
    $node->method('id')->willReturn(42);
    $node->method('label')->willReturn('Summer market');
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturnCallback(static function (string $field) use (
      $start,
      $end,
      $type,
      $image,
      $summary,
      $donations,
      $accessibility,
      $capacity,
      $questions,
      $ticket_types,
    ): FieldItemListInterface {
      return match ($field) {
        'field_event_start' => $start,
        'field_event_end' => $end,
        'field_event_type' => $type,
        'field_event_image' => $image,
        'field_event_summary' => $summary,
        'field_enable_donations' => $donations,
        'field_accessibility_contact' => $accessibility,
        'field_capacity', 'field_event_capacity_total' => $capacity,
        'field_attendee_questions' => $questions,
        'field_ticket_types' => $ticket_types,
        default => throw new \InvalidArgumentException($field),
      };
    });

    return $node;
  }

  private function fieldWithValue(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('__get')->willReturnCallback(static fn (string $name): mixed => $name === 'value' ? $value : NULL);
    return $field;
  }

  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

  private function account(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('5');
    return $account;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
