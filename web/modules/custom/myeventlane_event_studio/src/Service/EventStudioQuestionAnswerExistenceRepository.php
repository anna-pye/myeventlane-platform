<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregate existence checks for historical attendee question answers.
 */
class EventStudioQuestionAnswerExistenceRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  public function questionHasHistoricalAnswers(NodeInterface $event, ParagraphInterface $question): bool {
    $machine = $this->questionMachineName($question);
    $label = $this->questionLabel($question);
    if ($machine === '' && $label === '') {
      return FALSE;
    }

    try {
      $query = $this->database->select('paragraph__field_attendee_extra_field', 'answer');
      $query->range(0, 1);
      $query->fields('answer', ['entity_id']);
      $query->innerJoin('paragraph__field_attendee_questions', 'holder_questions', 'holder_questions.field_attendee_questions_target_id = answer.entity_id');
      $query->innerJoin('commerce_order_item__field_ticket_holder', 'holder_ref', 'holder_ref.field_ticket_holder_target_id = holder_questions.entity_id');
      $query->innerJoin('commerce_order_item__field_target_event', 'target_event', 'target_event.entity_id = holder_ref.entity_id');
      $query->condition('target_event.field_target_event_target_id', (int) $event->id());
      $query->isNotNull('answer.field_attendee_extra_field_value');
      $query->condition('answer.field_attendee_extra_field_value', '', '<>');

      $or = $query->orConditionGroup();
      if ($machine !== '') {
        $query->innerJoin('paragraph__field_question_machine_name', 'machine_name', 'machine_name.entity_id = answer.entity_id');
        $or->condition('machine_name.field_question_machine_name_value', $machine);
      }
      if ($label !== '') {
        $query->innerJoin('paragraph__field_question_label', 'question_label', 'question_label.entity_id = answer.entity_id');
        $or->condition('question_label.field_question_label_value', $label);
      }
      $query->condition($or);

      return (bool) $query->execute()?->fetchField();
    }
    catch (\Throwable $e) {
      $this->logger->error('Unable to verify historical attendee answers for question @question on event @event: @message', [
        '@question' => (string) ($question->id() ?? 'new'),
        '@event' => (string) ($event->id() ?? 'new'),
        '@message' => $e->getMessage(),
      ]);
      return TRUE;
    }
  }

  private function questionMachineName(ParagraphInterface $question): string {
    return $question->hasField('field_question_machine_name')
      ? trim((string) ($question->get('field_question_machine_name')->value ?? ''))
      : '';
  }

  private function questionLabel(ParagraphInterface $question): string {
    return $question->hasField('field_question_label')
      ? trim((string) ($question->get('field_question_label')->value ?? ''))
      : '';
  }

}
