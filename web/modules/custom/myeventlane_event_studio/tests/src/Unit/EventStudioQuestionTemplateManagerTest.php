<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionAnswerExistenceRepository;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager;
use Drupal\myeventlane_event_studio\Service\QuestionFieldTypeRegistry;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (!interface_exists(NodeInterface::class)) {
  eval('namespace Drupal\node; interface NodeInterface {}');
}
if (!interface_exists(ParagraphInterface::class)) {
  eval('namespace Drupal\paragraphs; interface ParagraphInterface { public function hasField($field_name); public function get($field_name); public function id(); }');
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
   * @covers ::mergeBuilderItemOntoExistingRow
   */
  public function testMergeBuilderItemPreservesGovernanceWhenOmitted(): void {
    $manager = $this->manager();

    $merged = $manager->mergeBuilderItemOntoExistingRow([
      'id' => 42,
      'label' => 'Dietary needs',
      'type' => 'select',
      'required' => TRUE,
      'status' => EventStudioQuestionTemplateManager::STATUS_ACTIVE,
      'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
      'ticket_type_ids' => [10, 20],
      'options' => "Vegan\nGluten free",
      'machine_name' => 'dietary',
    ], [
      'label' => 'Dietary requirements',
      'type' => 'textarea',
      'required' => FALSE,
    ]);

    $this->assertSame('Dietary requirements', $merged['label']);
    $this->assertSame('textarea', $merged['type']);
    $this->assertFalse($merged['required']);
    $this->assertSame(EventStudioQuestionTemplateManager::STATUS_ACTIVE, $merged['status']);
    $this->assertSame(EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE, $merged['applicability']);
    $this->assertSame([10, 20], $merged['ticket_type_ids']);
    $this->assertSame("Vegan\nGluten free", $merged['options']);
    $this->assertSame('dietary', $merged['machine_name']);
  }

  /**
   * @covers ::composeRowsFromBuilderPayload
   */
  public function testComposeRowsFromBuilderPayloadPreservesMissingQuestions(): void {
    $manager = $this->manager();
    $existing = [
      [
        'id' => 10,
        'weight' => 0,
        'label' => 'Workspace only',
        'type' => 'textfield',
        'required' => FALSE,
        'status' => EventStudioQuestionTemplateManager::STATUS_ACTIVE,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
        'ticket_type_ids' => [],
        'options' => '',
        'machine_name' => 'workspace_only',
      ],
      [
        'id' => 11,
        'weight' => 1,
        'label' => 'Phone',
        'type' => 'tel',
        'required' => TRUE,
        'status' => EventStudioQuestionTemplateManager::STATUS_ACTIVE,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
        'ticket_type_ids' => [],
        'options' => '',
        'machine_name' => 'phone',
      ],
    ];
    $event = $this->createMock(NodeInterface::class);
    $rows = $manager->composeRowsFromBuilderPayload($event, [
      [
        'id' => 11,
        'label' => 'Mobile number',
        'type' => 'tel',
        'required' => TRUE,
      ],
    ], $existing);

    $this->assertCount(2, $rows);
    $this->assertSame(11, (int) $rows[0]['id']);
    $this->assertSame('Mobile number', $rows[0]['label']);
    $this->assertSame(10, (int) $rows[1]['id']);
    $this->assertSame('Workspace only', $rows[1]['label']);
  }

  /**
   * @covers ::composeRowsFromBuilderPayload
   */
  public function testComposeRowsFromBuilderPayloadMatchesByParagraphId(): void {
    $manager = $this->manager();
    $existing = [
      [
        'id' => 99,
        'weight' => 0,
        'label' => 'Old label',
        'type' => 'textfield',
        'required' => FALSE,
        'status' => EventStudioQuestionTemplateManager::STATUS_ARCHIVED,
        'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE,
        'ticket_type_ids' => [5],
        'options' => '',
        'machine_name' => 'stable_key',
      ],
    ];
    $event = $this->createMock(NodeInterface::class);
    $rows = $manager->composeRowsFromBuilderPayload($event, [
      [
        'id' => 99,
        'label' => 'Updated label',
        'type' => 'textfield',
        'required' => TRUE,
      ],
    ], $existing);

    $this->assertCount(1, $rows);
    $this->assertSame(99, (int) $rows[0]['id']);
    $this->assertSame('Updated label', $rows[0]['label']);
    $this->assertTrue($rows[0]['required']);
    $this->assertSame(EventStudioQuestionTemplateManager::STATUS_ARCHIVED, $rows[0]['status']);
    $this->assertSame(EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE, $rows[0]['applicability']);
    $this->assertSame([5], $rows[0]['ticket_type_ids']);
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
  private function paragraph(array $values): ParagraphInterface {
    $paragraph = $this->createMock(ParagraphInterface::class);
    $paragraph->method('hasField')->willReturnCallback(static fn (string $field): bool => array_key_exists($field, $values));
    $paragraph->method('get')->willReturnCallback(static function (string $field) use ($values): object {
      return new class($values[$field] ?? NULL) {
        public function __construct(public mixed $value) {}
        public function isEmpty(): bool {
          return $this->value === NULL || $this->value === '';
        }
      };
    });
    return $paragraph;
  }

  private function translator(): TranslationInterface {
    $translator = $this->createMock(TranslationInterface::class);
    $translator
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translator;
  }

}
