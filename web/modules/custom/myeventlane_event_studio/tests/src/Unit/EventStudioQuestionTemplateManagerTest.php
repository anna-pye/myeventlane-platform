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
