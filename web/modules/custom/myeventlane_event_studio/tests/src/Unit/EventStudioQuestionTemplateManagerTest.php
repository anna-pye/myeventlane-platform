<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionAnswerExistenceRepository;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (!interface_exists(NodeInterface::class)) {
  eval('namespace Drupal\node; interface NodeInterface { public function hasField($field_name); public function get($field_name); }');
}
if (!interface_exists(ParagraphInterface::class)) {
  eval('namespace Drupal\paragraphs; interface ParagraphInterface { public function hasField($field_name); public function get($field_name); public function id(); public function bundle(); }');
}
if (!interface_exists(TicketTypeInterface::class)) {
  eval('namespace Drupal\mel_ticket\Entity; interface TicketTypeInterface { public function label(); public function hasField($field_name); public function get($field_name); public function isArchived(); }');
}
require_once dirname(__DIR__, 3) . '/src/Service/QuestionFieldTypeRegistry.php';
require_once dirname(__DIR__, 3) . '/src/Service/EventStudioQuestionAnswerExistenceRepository.php';
require_once dirname(__DIR__, 3) . '/src/Service/EventStudioQuestionTemplateManager.php';

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager
 *
 * @group myeventlane_event_studio
 */
final class EventStudioQuestionTemplateManagerTest extends TestCase {

  /**
   * @covers ::normalizeType
   */
  public function testNormalizeTypeMapsGovernedAliases(): void {
    $manager = $this->manager();

    $this->assertSame('textfield', $manager->normalizeType('text'));
    $this->assertSame('checkboxes', $manager->normalizeType('checkbox'));
    $this->assertSame('radios', $manager->normalizeType('radio'));
    $this->assertSame('number', $manager->normalizeType('number'));
    $this->assertSame('textfield', $manager->normalizeType('made_up'));
  }

  /**
   * @covers ::applicabilityOptions
   */
  public function testApplicabilityOptionsIncludePerOrderMetadataOption(): void {
    $manager = $this->manager();

    $this->assertArrayHasKey(EventStudioQuestionTemplateManager::APPLIES_PER_ORDER, $manager->applicabilityOptions());
  }

  /**
   * @covers ::normalizeOptions
   */
  public function testNormalizeOptionsTrimsAndDeduplicatesLines(): void {
    $manager = $this->manager();

    $this->assertSame(['VIP', 'General'], $manager->normalizeOptions(" VIP \n\nGeneral\nVIP"));
  }

  /**
   * @covers ::normalizeTicketTypeIds
   */
  public function testNormalizeTicketTypeIdsAcceptsCheckboxShapes(): void {
    $manager = $this->manager();

    $this->assertSame([10, 20], $manager->normalizeTicketTypeIds([
      10 => '10',
      20 => 20,
      30 => 0,
    ]));
  }

  /**
   * @covers ::validateImmutableQuestionMutation
   */
  public function testImmutableQuestionRejectsOptionValueChangesAfterAnswersExist(): void {
    $repository = $this->createMock(EventStudioQuestionAnswerExistenceRepository::class);
    $repository->method('questionHasHistoricalAnswers')->willReturn(TRUE);
    $manager = $this->manager($repository);

    $error = $manager->validateImmutableQuestionMutation(
      $this->createMock(NodeInterface::class),
      $this->paragraph([
        'field_question_type' => 'select',
        'field_question_required' => 1,
        'field_question_applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
        'field_question_machine_name' => 'meal',
        'field_question_options' => "Chicken\nVegetarian",
      ]),
      [
        'label' => 'Meal preference',
        'type' => 'select',
        'required' => TRUE,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
        'machine_name' => 'meal',
        'options' => "Chicken\nVegan",
      ],
      'Meal question',
    );

    $this->assertSame('Meal question already has attendee answers, so option values cannot be changed. Archive it and add a new question instead.', $error);
  }

  /**
   * @covers ::validateImmutableQuestionMutation
   */
  public function testImmutableQuestionAllowsLabelCleanupAfterAnswersExist(): void {
    $repository = $this->createMock(EventStudioQuestionAnswerExistenceRepository::class);
    $repository->method('questionHasHistoricalAnswers')->willReturn(TRUE);
    $manager = $this->manager($repository);

    $error = $manager->validateImmutableQuestionMutation(
      $this->createMock(NodeInterface::class),
      $this->paragraph([
        'field_question_label' => 'Meal',
        'field_question_type' => 'select',
        'field_question_required' => 1,
        'field_question_applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
        'field_question_machine_name' => 'meal',
        'field_question_options' => "Chicken\nVegetarian",
      ]),
      [
        'label' => 'Meal preference',
        'type' => 'select',
        'required' => TRUE,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
        'machine_name' => 'meal',
        'options' => "Chicken\nVegetarian",
      ],
      'Meal question',
    );

    $this->assertNull($error);
  }

  /**
   * @covers ::workspaceApplicabilityLabels
   */
  public function testWorkspaceApplicabilityLabelsUseVendorFacingCopy(): void {
    $manager = $this->manager();
    $labels = $manager->workspaceApplicabilityLabels();

    $this->assertSame('All ticket holders', $labels[EventStudioQuestionTemplateManager::APPLIES_PER_TICKET]);
    $this->assertSame('Specific ticket types', $labels[EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE]);
  }

  /**
   * @covers ::validateRow
   */
  public function testValidateRowRequiresTicketTypesForPerTicketTypeApplicability(): void {
    $manager = $this->manager();

    $error = $manager->validateRow(
      $this->createMock(NodeInterface::class),
      [
        'label' => 'VIP meal choice',
        'type' => 'textfield',
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
        'ticket_type_ids' => [],
      ],
      'VIP question',
    );

    $this->assertSame('VIP question must select at least one ticket type.', $error);
  }

  /**
   * @covers ::validateImmutableQuestionMutation
   */
  public function testImmutableQuestionRejectsTicketTypeTargetingChangesAfterAnswersExist(): void {
    $repository = $this->createMock(EventStudioQuestionAnswerExistenceRepository::class);
    $repository->method('questionHasHistoricalAnswers')->willReturn(TRUE);
    $manager = $this->manager($repository);

    $error = $manager->validateImmutableQuestionMutation(
      $this->createMock(NodeInterface::class),
      $this->paragraph([
        'field_question_type' => 'textfield',
        'field_question_required' => 1,
        'field_question_applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
        'field_question_ticket_types' => [
          ['target_id' => 10],
          ['target_id' => 20],
        ],
      ], TRUE),
      [
        'label' => 'VIP meal choice',
        'type' => 'textfield',
        'required' => TRUE,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
        'ticket_type_ids' => [10],
      ],
      'VIP question',
    );

    $this->assertSame('VIP question already has attendee answers, so its ticket type targeting cannot be changed. Archive it and add a new question instead.', $error);
  }

  /**
   * @covers ::ticketTypeIdSetsEqual
   * @covers ::validateImmutableQuestionMutation
   */
  public function testImmutableQuestionAllowsTicketTypeOrderChangesAfterAnswersExist(): void {
    $repository = $this->createMock(EventStudioQuestionAnswerExistenceRepository::class);
    $repository->method('questionHasHistoricalAnswers')->willReturn(TRUE);
    $manager = $this->manager($repository);

    $this->assertTrue($manager->ticketTypeIdSetsEqual([20, 10], [10, 20]));

    $error = $manager->validateImmutableQuestionMutation(
      $this->createMock(NodeInterface::class),
      $this->paragraph([
        'field_question_type' => 'textfield',
        'field_question_required' => 1,
        'field_question_applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
        'field_question_ticket_types' => [
          ['target_id' => 20],
          ['target_id' => 10],
        ],
      ], TRUE),
      [
        'label' => 'VIP meal choice',
        'type' => 'textfield',
        'required' => TRUE,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
        'ticket_type_ids' => [10 => '10', 20 => '20'],
      ],
      'VIP question',
    );

    $this->assertNull($error);
  }

  /**
   * @covers ::findLegacyTierQuestionSummary
   */
  public function testFindLegacyTierQuestionSummaryReturnsCountAndTicketNames(): void {
    $manager = $this->manager();
    $event = $this->eventWithLegacyTierQuestions();

    $summary = $manager->findLegacyTierQuestionSummary($event);

    $this->assertSame(2, $summary['total_count']);
    $this->assertSame(['VIP', 'General Admission'], $summary['ticket_type_names']);
    $this->assertSame(['VIP' => 1, 'General Admission' => 1], $summary['questions_per_ticket_type']);
  }

  private function manager(?EventStudioQuestionAnswerExistenceRepository $repository = NULL): EventStudioQuestionTemplateManager {
    $registry = new QuestionFieldTypeRegistry();
    $registry->setStringTranslation($this->translator());

    $manager = new EventStudioQuestionTemplateManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $registry,
      $repository ?? $this->createMock(EventStudioQuestionAnswerExistenceRepository::class),
    );
    $manager->setStringTranslation($this->translator());
    return $manager;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function paragraph(array $values, bool $ticketTypesUseGetValue = FALSE): ParagraphInterface {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $paragraph->method('hasField')->willReturnCallback(static fn (string $field): bool => array_key_exists($field, $values));
    $paragraph->method('get')->willReturnCallback(static function (string $field) use ($values, $ticketTypesUseGetValue): object {
      $raw = $values[$field] ?? NULL;
      if ($ticketTypesUseGetValue && $field === 'field_question_ticket_types') {
        return new class($raw) {
          public function __construct(public mixed $value) {}
          public function isEmpty(): bool {
            return $this->value === NULL || $this->value === [];
          }
          /**
           * @return list<array{target_id: int}>
           */
          public function getValue(): array {
            return is_array($this->value) ? $this->value : [];
          }
        };
      }
      return new class($raw) {
        public function __construct(public mixed $value) {}
        public function isEmpty(): bool {
          return $this->value === NULL || $this->value === '';
        }
      };
    });
    return $paragraph;
  }

  private function eventWithLegacyTierQuestions(): NodeInterface {
    $vip_question = $this->createMock(ParagraphInterface::class);
    $vip_question->method('bundle')->willReturn('attendee_extra_field');
    $ga_question = $this->createMock(ParagraphInterface::class);
    $ga_question->method('bundle')->willReturn('attendee_extra_field');

    $vip = $this->ticketTypeWithLegacyQuestions('VIP', [$vip_question]);
    $ga = $this->ticketTypeWithLegacyQuestions('General Admission', [$ga_question]);
    $disabled = $this->ticketTypeWithLegacyQuestions('Staff', [], FALSE);

    $event = $this->createMock(NodeInterface::class);
    $event->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'field_ticket_types');
    $event->method('get')->willReturnCallback(static function (string $field) use ($vip, $ga, $disabled): object {
      return new class([$vip, $ga, $disabled]) {
        /**
         * @param list<\Drupal\mel_ticket\Entity\TicketTypeInterface> $tickets
         */
        public function __construct(private readonly array $tickets) {}
        public function isEmpty(): bool {
          return $this->tickets === [];
        }
        /**
         * @return list<\Drupal\mel_ticket\Entity\TicketTypeInterface>
         */
        public function referencedEntities(): array {
          return $this->tickets;
        }
      };
    });
    return $event;
  }

  /**
   * @param list<\Drupal\paragraphs\ParagraphInterface> $questions
   */
  private function ticketTypeWithLegacyQuestions(string $label, array $questions, bool $enabled = TRUE): TicketTypeInterface {
    $ticket = $this->createMock(TicketTypeInterface::class);
    $ticket->method('label')->willReturn($label);
    $ticket->method('isArchived')->willReturn(FALSE);
    $ticket->method('hasField')->willReturn(TRUE);
    $ticket->method('get')->willReturnCallback(static function (string $field) use ($enabled, $questions): object {
      if ($field === 'field_use_ticket_attendee_questions') {
        return new class($enabled) {
          public function __construct(public bool $value) {}
          public function isEmpty(): bool {
            return FALSE;
          }
        };
      }
      return new class($questions) {
        /**
         * @param list<\Drupal\paragraphs\ParagraphInterface> $questions
         */
        public function __construct(private readonly array $questions) {}
        public function isEmpty(): bool {
          return $this->questions === [];
        }
        /**
         * @return list<\Drupal\paragraphs\ParagraphInterface>
         */
        public function referencedEntities(): array {
          return $this->questions;
        }
      };
    });
    return $ticket;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
