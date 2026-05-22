<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Psr\Log\LoggerInterface;

/**
 * Operational manager for Event Studio checkout question templates.
 */
final class EventStudioQuestionTemplateManager {

  use StringTranslationTrait;

  public const STATUS_ACTIVE = 'active';
  public const STATUS_ARCHIVED = 'archived';

  public const APPLIES_PER_TICKET = 'per_ticket';
  public const APPLIES_PER_TICKET_TYPE = 'per_ticket_type';
  public const APPLIES_PER_ORDER = 'per_order';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly QuestionFieldTypeRegistry $fieldTypeRegistry,
    private readonly EventStudioQuestionAnswerExistenceRepository $answerExistenceRepository,
  ) {}

  /**
   * Studio-governed field types exposed to vendors.
   *
   * @return array<string, string>
   */
  public function typeOptions(): array {
    return $this->fieldTypeRegistry->options(FALSE);
  }

  /**
   * @return array<string, string>
   */
  public function applicabilityOptions(): array {
    return [
      self::APPLIES_PER_TICKET => (string) $this->t('Per ticket'),
      self::APPLIES_PER_TICKET_TYPE => (string) $this->t('Per ticket type'),
      self::APPLIES_PER_ORDER => (string) $this->t('Per order'),
    ];
  }

  /**
   * Vendor-facing applicability labels for the Event Studio questions table.
   *
   * @return array<string, string>
   */
  public function workspaceApplicabilityLabels(): array {
    return [
      self::APPLIES_PER_TICKET => (string) $this->t('All ticket holders'),
      self::APPLIES_PER_TICKET_TYPE => (string) $this->t('Specific ticket types'),
      self::APPLIES_PER_ORDER => (string) $this->t('Per order'),
    ];
  }

  /**
   * Summarises legacy tier-level attendee question templates on ticket types.
   *
   * @return array{
   *   total_count: int,
   *   ticket_type_names: list<string>,
   *   questions_per_ticket_type: array<string, int>
   * }
   */
  public function findLegacyTierQuestionSummary(NodeInterface $event): array {
    $total_count = 0;
    $ticket_type_names = [];
    $questions_per_ticket_type = [];

    foreach ($this->loadEventTickets($event) as $ticket) {
      if (!$ticket->hasField('field_use_ticket_attendee_questions')
        || empty($ticket->get('field_use_ticket_attendee_questions')->value)) {
        continue;
      }
      if (!$ticket->hasField('field_attendee_questions')
        || $ticket->get('field_attendee_questions')->isEmpty()) {
        continue;
      }

      $count = 0;
      foreach ($ticket->get('field_attendee_questions')->referencedEntities() as $paragraph) {
        if ($paragraph instanceof ParagraphInterface && $paragraph->bundle() === 'attendee_extra_field') {
          $count++;
        }
      }
      if ($count < 1) {
        continue;
      }

      $total_count += $count;
      $name = $ticket->label();
      $ticket_type_names[] = $name;
      $questions_per_ticket_type[$name] = $count;
    }

    return [
      'total_count' => $total_count,
      'ticket_type_names' => $ticket_type_names,
      'questions_per_ticket_type' => $questions_per_ticket_type,
    ];
  }

  /**
   * @return array<string, string>
   */
  public function statusOptions(): array {
    return [
      self::STATUS_ACTIVE => (string) $this->t('Active'),
      self::STATUS_ARCHIVED => (string) $this->t('Archived'),
    ];
  }

  /**
   * @return array<int, string>
   */
  public function ticketOptions(NodeInterface $event): array {
    $options = [];
    foreach ($this->loadEventTickets($event) as $ticket) {
      $options[(int) $ticket->id()] = $ticket->label();
    }
    return $options;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function loadRows(NodeInterface $event): array {
    if (!$event->hasField('field_attendee_questions') || $event->get('field_attendee_questions')->isEmpty()) {
      return [];
    }

    $rows = [];
    foreach ($event->get('field_attendee_questions')->referencedEntities() as $delta => $paragraph) {
      if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_extra_field') {
        continue;
      }
      $rows[] = $this->rowFromParagraph($paragraph, (int) $delta);
    }
    return $rows;
  }

  /**
   * @param array<string, mixed> $row
   */
  public function validateRow(NodeInterface $event, array $row, string $context = 'question'): ?string {
    $label = trim((string) ($row['label'] ?? ''));
    if ($label === '') {
      return (string) $this->t('Each question needs a label.');
    }

    $type = $this->normalizeType((string) ($row['type'] ?? ''));
    if ($this->fieldTypeRegistry->isLegacy($type) || !array_key_exists($type, $this->typeOptions())) {
      return (string) $this->t('@question uses an unsupported type.', ['@question' => $context]);
    }

    $status = $this->normalizeStatus((string) ($row['status'] ?? self::STATUS_ACTIVE));
    $applicability = $this->normalizeApplicability((string) ($row['applicability'] ?? self::APPLIES_PER_TICKET));

    $options = $this->normalizeOptions((string) ($row['options'] ?? ''));
    if ($this->fieldTypeRegistry->requiresOptions($type) && $options === []) {
      return (string) $this->t('@question needs at least one option.', ['@question' => $context]);
    }
    if (!$this->fieldTypeRegistry->requiresOptions($type) && $options !== []) {
      return (string) $this->t('@question has options, but options are only supported for select, checkbox, and radio questions.', ['@question' => $context]);
    }

    $ticket_ids = $this->normalizeTicketTypeIds($row['ticket_type_ids'] ?? []);
    if ($applicability === self::APPLIES_PER_TICKET_TYPE) {
      if ($ticket_ids === []) {
        return (string) $this->t('@question must select at least one ticket type.', ['@question' => $context]);
      }
      $valid_ticket_ids = array_keys($this->ticketOptions($event));
      foreach ($ticket_ids as $ticket_id) {
        if (!in_array($ticket_id, $valid_ticket_ids, TRUE)) {
          return (string) $this->t('@question references a ticket type that does not belong to this event.', ['@question' => $context]);
        }
      }
    }

    return NULL;
  }

  /**
   * @param list<array<string, mixed>> $existingRows
   * @param array<string, mixed>|null $newRow
   *
   * @return list<string>
   */
  public function validateRows(NodeInterface $event, array $existingRows, ?array $newRow = NULL): array {
    $errors = [];
    $seen = [];
    foreach ($existingRows as $index => $row) {
      if (!is_array($row)) {
        $errors[] = (string) $this->t('Question row @row was invalid.', ['@row' => (string) ($index + 1)]);
        continue;
      }
      $context = (string) $this->t('Question @row', ['@row' => (string) ($index + 1)]);
      $error = $this->validateRow($event, $row, $context);
      if ($error !== NULL) {
        $errors[] = $error;
        continue;
      }
      $paragraph = $this->loadManagedParagraph($event, (int) ($row['id'] ?? 0));
      if ($paragraph instanceof ParagraphInterface) {
        $immutable_error = $this->validateImmutableQuestionMutation($event, $paragraph, $row, $context);
        if ($immutable_error !== NULL) {
          $errors[] = $immutable_error;
          continue;
        }
      }
      $status = $this->normalizeStatus((string) ($row['status'] ?? self::STATUS_ACTIVE));
      $label_key = mb_strtolower(trim((string) ($row['label'] ?? '')));
      if ($status === self::STATUS_ACTIVE && $label_key !== '') {
        if (isset($seen[$label_key])) {
          $errors[] = (string) $this->t('Active question labels must be unique: @label.', ['@label' => (string) $row['label']]);
        }
        $seen[$label_key] = TRUE;
      }
    }

    if ($newRow !== NULL && trim((string) ($newRow['label'] ?? '')) !== '') {
      $error = $this->validateRow($event, $newRow, (string) $this->t('New question'));
      if ($error !== NULL) {
        $errors[] = $error;
      }
      $status = $this->normalizeStatus((string) ($newRow['status'] ?? self::STATUS_ACTIVE));
      $label_key = mb_strtolower(trim((string) ($newRow['label'] ?? '')));
      if ($status === self::STATUS_ACTIVE && isset($seen[$label_key])) {
        $errors[] = (string) $this->t('Active question labels must be unique: @label.', ['@label' => (string) $newRow['label']]);
      }
    }

    return array_values(array_unique($errors));
  }

  /**
   * @param list<array<string, mixed>> $existingRows
   * @param array<string, mixed>|null $newRow
   *
   * @return list<string>
   */
  public function saveRows(NodeInterface $event, array $existingRows, ?array $newRow = NULL): array {
    if (!$event->hasField('field_attendee_questions')) {
      return [(string) $this->t('Attendee questions are not available on this event.')];
    }

    $errors = $this->validateRows($event, $existingRows, $newRow);
    if ($errors !== []) {
      return $errors;
    }

    $paragraphs = [];
    try {
      foreach ($existingRows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $paragraph = $this->loadManagedParagraph($event, (int) ($row['id'] ?? 0));
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $this->applyRowToParagraph($paragraph, $row);
        $paragraph->save();
        $paragraphs[] = [
          'paragraph' => $paragraph,
          'weight' => (int) ($row['weight'] ?? 0),
        ];
      }

      if ($newRow !== NULL && trim((string) ($newRow['label'] ?? '')) !== '') {
        $paragraph = Paragraph::create(['type' => 'attendee_extra_field']);
        $this->applyRowToParagraph($paragraph, $newRow);
        $paragraph->save();
        $paragraphs[] = [
          'paragraph' => $paragraph,
          'weight' => (int) ($newRow['weight'] ?? 999),
        ];
      }

      usort($paragraphs, static fn (array $a, array $b): int => ($a['weight'] <=> $b['weight']));
      $references = [];
      foreach ($paragraphs as $item) {
        $paragraph = $item['paragraph'];
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $references[] = [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ];
      }

      $event->set('field_attendee_questions', $references);
      if ($event->hasField('field_collect_per_ticket')) {
        $has_active_checkout_question = FALSE;
        foreach ($paragraphs as $item) {
          $paragraph = $item['paragraph'];
          if ($paragraph instanceof ParagraphInterface
            && $this->paragraphStatus($paragraph) === self::STATUS_ACTIVE
            && $this->paragraphApplicability($paragraph) !== self::APPLIES_PER_ORDER) {
            $has_active_checkout_question = TRUE;
            break;
          }
        }
        $event->set('field_collect_per_ticket', $has_active_checkout_question);
      }
      $event->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio question template save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return [(string) $this->t('Checkout questions could not be saved. Check the rows and try again.')];
    }

    return [];
  }

  public function normalizeType(string $type): string {
    return $this->fieldTypeRegistry->normalize($type);
  }

  public function normalizeStatus(string $status): string {
    return $status === self::STATUS_ARCHIVED ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE;
  }

  public function normalizeApplicability(string $applicability): string {
    return match ($applicability) {
      self::APPLIES_PER_TICKET_TYPE => self::APPLIES_PER_TICKET_TYPE,
      self::APPLIES_PER_ORDER => self::APPLIES_PER_ORDER,
      default => self::APPLIES_PER_TICKET,
    };
  }

  public function paragraphStatus(ParagraphInterface $paragraph): string {
    if ($paragraph->hasField('field_question_status') && !$paragraph->get('field_question_status')->isEmpty()) {
      return $this->normalizeStatus((string) $paragraph->get('field_question_status')->value);
    }
    return self::STATUS_ACTIVE;
  }

  public function paragraphApplicability(ParagraphInterface $paragraph): string {
    if ($paragraph->hasField('field_question_applicability') && !$paragraph->get('field_question_applicability')->isEmpty()) {
      return $this->normalizeApplicability((string) $paragraph->get('field_question_applicability')->value);
    }
    return self::APPLIES_PER_TICKET;
  }

  /**
   * @return list<int>
   */
  public function paragraphTicketTypeIds(ParagraphInterface $paragraph): array {
    if (!$paragraph->hasField('field_question_ticket_types') || $paragraph->get('field_question_ticket_types')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($paragraph->get('field_question_ticket_types')->getValue() as $item) {
      $target_id = isset($item['target_id']) ? (int) $item['target_id'] : 0;
      if ($target_id > 0) {
        $ids[] = $target_id;
      }
    }
    return array_values(array_unique($ids));
  }

  public function questionHasHistoricalAnswers(NodeInterface $event, ParagraphInterface $paragraph): bool {
    return $this->answerExistenceRepository->questionHasHistoricalAnswers($event, $paragraph);
  }

  /**
   * @param array<string, mixed> $newRow
   */
  public function validateImmutableQuestionMutation(NodeInterface $event, ParagraphInterface $paragraph, array $newRow, string $context = 'question'): ?string {
    if (!$this->questionHasHistoricalAnswers($event, $paragraph)) {
      return NULL;
    }

    $old_type = $paragraph->hasField('field_question_type') && !$paragraph->get('field_question_type')->isEmpty()
      ? $this->normalizeType((string) $paragraph->get('field_question_type')->value)
      : 'textfield';
    $new_type = $this->normalizeType((string) ($newRow['type'] ?? 'textfield'));
    if ($old_type !== $new_type) {
      return (string) $this->t('@question already has attendee answers, so its field type cannot be changed. Archive it and add a new question instead.', ['@question' => $context]);
    }

    $old_applicability = $this->paragraphApplicability($paragraph);
    $new_applicability = $this->normalizeApplicability((string) ($newRow['applicability'] ?? self::APPLIES_PER_TICKET));
    if ($old_applicability !== $new_applicability) {
      return (string) $this->t('@question already has attendee answers, so its ticket applicability cannot be changed. Archive it and add a new question instead.', ['@question' => $context]);
    }

    $old_required = $paragraph->hasField('field_question_required') && !empty($paragraph->get('field_question_required')->value);
    $new_required = !empty($newRow['required']);
    if ($old_required && !$new_required) {
      return (string) $this->t('@question already has attendee answers, so required validation cannot be removed. Archive it and add a new optional question instead.', ['@question' => $context]);
    }

    $old_machine = $paragraph->hasField('field_question_machine_name')
      ? trim((string) ($paragraph->get('field_question_machine_name')->value ?? ''))
      : '';
    $new_machine = trim((string) ($newRow['machine_name'] ?? ''));
    if ($new_machine === '') {
      $new_machine = $this->machineNameFromLabel(trim((string) ($newRow['label'] ?? '')));
    }
    if ($old_machine !== '' && $new_machine !== '' && $old_machine !== $new_machine) {
      return (string) $this->t('@question already has attendee answers, so its stable machine name cannot be changed.', ['@question' => $context]);
    }

    if ($this->fieldTypeRegistry->requiresOptions($old_type)) {
      $old_hash = $this->optionValueHash((string) ($paragraph->hasField('field_question_options') ? ($paragraph->get('field_question_options')->value ?? '') : ''));
      $new_hash = $this->optionValueHash((string) ($newRow['options'] ?? ''));
      if ($old_hash !== $new_hash) {
        return (string) $this->t('@question already has attendee answers, so option values cannot be changed. Archive it and add a new question instead.', ['@question' => $context]);
      }
    }

    $old_ticket_ids = $this->paragraphTicketTypeIds($paragraph);
    $new_ticket_ids = $this->normalizeTicketTypeIds($newRow['ticket_type_ids'] ?? []);
    if (!$this->ticketTypeIdSetsEqual($old_ticket_ids, $new_ticket_ids)) {
      return (string) $this->t('@question already has attendee answers, so its ticket type targeting cannot be changed. Archive it and add a new question instead.', ['@question' => $context]);
    }

    return NULL;
  }

  /**
   * @param list<int> $left
   * @param list<int> $right
   */
  public function ticketTypeIdSetsEqual(array $left, array $right): bool {
    $normalized_left = $this->normalizeTicketTypeIds($left);
    $normalized_right = $this->normalizeTicketTypeIds($right);
    sort($normalized_left);
    sort($normalized_right);
    return $normalized_left === $normalized_right;
  }

  /**
   * @return list<string>
   */
  public function normalizeOptions(string $raw): array {
    $lines = preg_split('/\R/', $raw) ?: [];
    $options = [];
    foreach ($lines as $line) {
      $option = trim($line);
      if ($option !== '') {
        $options[] = $option;
      }
    }
    return array_values(array_unique($options));
  }

  public function optionValueHash(string $raw): string {
    return hash('sha256', json_encode($this->normalizeOptions($raw), JSON_THROW_ON_ERROR));
  }

  /**
   * @return list<int>
   */
  public function normalizeTicketTypeIds(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $ids = [];
    foreach ($raw as $key => $value) {
      $candidate = is_numeric($value) ? (int) $value : (is_numeric($key) && $value ? (int) $key : 0);
      if ($candidate > 0) {
        $ids[] = $candidate;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * @return list<\Drupal\mel_ticket\Entity\TicketTypeInterface>
   */
  private function loadEventTickets(NodeInterface $event): array {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return [];
    }
    $tickets = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if ($ticket instanceof TicketTypeInterface && !$ticket->isArchived()) {
        $tickets[] = $ticket;
      }
    }
    return $tickets;
  }

  /**
   * @return array<string, mixed>
   */
  private function rowFromParagraph(ParagraphInterface $paragraph, int $delta): array {
    $type = $paragraph->hasField('field_question_type') && !$paragraph->get('field_question_type')->isEmpty()
      ? $this->normalizeType((string) $paragraph->get('field_question_type')->value)
      : 'textfield';
    $status = $this->paragraphStatus($paragraph);
    $applicability = $this->paragraphApplicability($paragraph);
    $ticket_type_ids = $this->paragraphTicketTypeIds($paragraph);
    $ticket_type_labels = [];
    if ($paragraph->hasField('field_question_ticket_types')) {
      foreach ($paragraph->get('field_question_ticket_types')->referencedEntities() as $ticket) {
        if ($ticket instanceof TicketTypeInterface) {
          $ticket_type_labels[] = $ticket->label();
        }
      }
    }

    return [
      'id' => (int) $paragraph->id(),
      'revision_id' => (int) $paragraph->getRevisionId(),
      'weight' => $delta,
      'label' => $paragraph->hasField('field_question_label') ? (string) ($paragraph->get('field_question_label')->value ?? '') : '',
      'type' => $type,
      'type_label' => $this->typeOptions()[$type] ?? $type,
      'required' => $paragraph->hasField('field_question_required') && !empty($paragraph->get('field_question_required')->value),
      'status' => $status,
      'applicability' => $applicability,
      'ticket_type_ids' => $ticket_type_ids,
      'ticket_type_labels' => $ticket_type_labels,
      'options' => $paragraph->hasField('field_question_options') ? (string) ($paragraph->get('field_question_options')->value ?? '') : '',
      'machine_name' => $paragraph->hasField('field_question_machine_name') ? (string) ($paragraph->get('field_question_machine_name')->value ?? '') : '',
    ];
  }

  /**
   * @param array<string, mixed> $row
   */
  private function applyRowToParagraph(ParagraphInterface $paragraph, array $row): void {
    $label = trim((string) ($row['label'] ?? ''));
    $type = $this->normalizeType((string) ($row['type'] ?? 'textfield'));
    $status = $this->normalizeStatus((string) ($row['status'] ?? self::STATUS_ACTIVE));
    $applicability = $this->normalizeApplicability((string) ($row['applicability'] ?? self::APPLIES_PER_TICKET));

    if ($paragraph->hasField('field_question_label')) {
      $paragraph->set('field_question_label', $label);
    }
    if ($paragraph->hasField('field_question_type')) {
      $paragraph->set('field_question_type', $type);
    }
    if ($paragraph->hasField('field_question_required')) {
      $paragraph->set('field_question_required', !empty($row['required']) ? 1 : 0);
    }
    if ($paragraph->hasField('field_question_status')) {
      $paragraph->set('field_question_status', $status);
    }
    if ($paragraph->hasField('field_question_applicability')) {
      $paragraph->set('field_question_applicability', $applicability);
    }
    if ($paragraph->hasField('field_question_options')) {
      $options = $this->normalizeOptions((string) ($row['options'] ?? ''));
      $paragraph->set('field_question_options', $options === [] ? NULL : ['value' => implode("\n", $options)]);
    }
    if ($paragraph->hasField('field_question_machine_name')) {
      $machine = trim((string) ($row['machine_name'] ?? ''));
      $paragraph->set('field_question_machine_name', $machine !== '' ? $machine : $this->machineNameFromLabel($label));
    }
    if ($paragraph->hasField('field_question_ticket_types')) {
      $ticket_ids = $this->normalizeTicketTypeIds($row['ticket_type_ids'] ?? []);
      $paragraph->set('field_question_ticket_types', array_map(static fn (int $id): array => ['target_id' => $id], $ticket_ids));
    }
  }

  private function loadManagedParagraph(NodeInterface $event, int $paragraphId): ?ParagraphInterface {
    if ($paragraphId < 1 || !$event->hasField('field_attendee_questions')) {
      return NULL;
    }
    foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
      if ($paragraph instanceof ParagraphInterface && (int) $paragraph->id() === $paragraphId) {
        return $paragraph;
      }
    }
    return NULL;
  }

  private function machineNameFromLabel(string $label): string {
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
    $slug = trim((string) preg_replace('/_+/', '_', $slug), '_');
    return $slug !== '' ? $slug : 'question_' . substr(hash('sha256', $label), 0, 10);
  }

}
