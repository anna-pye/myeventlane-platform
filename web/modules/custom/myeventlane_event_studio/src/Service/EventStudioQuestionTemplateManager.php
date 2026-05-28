<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_questions\Entity\VendorQuestionInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Psr\Log\LoggerInterface;

/**
 * Operational manager for Event Studio checkout question templates.
 */
final class EventStudioQuestionTemplateManager {

  use StringTranslationTrait;

  public const BUILDER_QUESTION_LIMIT = 5;

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
  /**
   * Publish-readiness findings for Event Studio checkout questions.
   *
   * @return list<array{severity: string, code: string, message: string}>
   */
  public function buildQuestionReadinessFindings(NodeInterface $event): array {
    $findings = [];
    $paid_event = $this->eventHasPaidBooking($event);

    $has_missing_ticket_types = FALSE;
    $has_per_order = FALSE;
    $has_locked_questions = FALSE;

    if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
      foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_extra_field') {
          continue;
        }
        if ($this->paragraphStatus($paragraph) === self::STATUS_ARCHIVED) {
          continue;
        }

        $applicability = $this->paragraphApplicability($paragraph);
        if ($paid_event
          && $applicability === self::APPLIES_PER_TICKET_TYPE
          && $this->paragraphTicketTypeIds($paragraph) === []) {
          $has_missing_ticket_types = TRUE;
        }
        if ($applicability === self::APPLIES_PER_ORDER) {
          $has_per_order = TRUE;
        }
        if ($this->questionHasHistoricalAnswers($event, $paragraph)) {
          $has_locked_questions = TRUE;
        }
      }
    }

    if ($has_missing_ticket_types) {
      $findings[] = [
        'severity' => 'blocker',
        'code' => 'ticket_question_missing_ticket_types',
        'message' => (string) $this->t('A ticket-specific checkout question has no ticket types selected.'),
      ];
    }

    if ($has_per_order) {
      $findings[] = [
        'severity' => 'warning',
        'code' => 'checkout_question_per_order_inactive',
        'message' => (string) $this->t('Per-order checkout questions are not active in checkout yet.'),
      ];
    }

    if ($has_locked_questions) {
      $findings[] = [
        'severity' => 'warning',
        'code' => 'checkout_question_historical_answers',
        'message' => (string) $this->t('Some checkout questions already have attendee answers. Archive them instead of changing type, options, or ticket targeting.'),
      ];
    }

    $legacy = $this->findLegacyTierQuestionSummary($event);
    if (($legacy['total_count'] ?? 0) > 0) {
      $findings[] = [
        'severity' => 'warning',
        'code' => 'legacy_ticket_type_questions',
        'message' => (string) $this->t('This event has ticket-level questions stored on ticket types. They still work at checkout, but new questions should be managed from Checkout questions.'),
      ];
    }

    return $findings;
  }

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
        $paragraph_id = (int) ($row['id'] ?? 0);
        if ($paragraph_id > 0) {
          $paragraph = $this->loadManagedParagraph($event, $paragraph_id);
          if (!$paragraph instanceof ParagraphInterface) {
            continue;
          }
        }
        else {
          if (trim((string) ($row['label'] ?? '')) === '') {
            continue;
          }
          $paragraph = Paragraph::create(['type' => 'attendee_extra_field']);
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

  /**
   * Persists builder/autosave attendee question JSON via merge + saveRows().
   *
   * Does not wipe field_attendee_questions. Questions missing from builder JSON
   * are preserved. Governance fields are only overwritten when builder sends them.
   *
   * @param list<array<string, mixed>> $builderItems
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Required when builder items reference vendor_question library entries.
   *
   * @return list<string>
   */
  public function saveBuilderPayload(NodeInterface $event, array $builderItems, ?AccountInterface $account = NULL): array {
    if (!$event->hasField('field_attendee_questions')) {
      return [];
    }

    $normalized = [];
    foreach ($builderItems as $index => $item) {
      if (!is_array($item)) {
        return [sprintf('Attendee question at index %d was invalid.', (int) $index)];
      }
      $vendor_qid = isset($item['vendor_question_id']) ? (int) $item['vendor_question_id'] : 0;
      if ($vendor_qid > 0) {
        $library_row = $this->builderRowFromVendorQuestion($vendor_qid, $account, (int) $index);
        if ($library_row === NULL) {
          continue;
        }
        $normalized[] = $library_row;
        continue;
      }
      $normalized[] = $item;
    }

    if (count($normalized) > self::BUILDER_QUESTION_LIMIT) {
      return [(string) $this->t('Add at most 5 attendee questions. More questions may reduce bookings.')];
    }

    $rows = $this->composeRowsFromBuilderPayload($event, $normalized);
    return $this->saveRows($event, $rows, NULL);
  }

  /**
   * Merges builder-controlled fields onto a stored workspace row.
   *
   * Preserves status, applicability, ticket_type_ids, and machine_name when the
   * builder payload omits them (typical for the lightweight JSON editor).
   *
   * @param array<string, mixed> $existingRow
   * @param array<string, mixed> $builderItem
   *
   * @return array<string, mixed>
   */
  public function mergeBuilderItemOntoExistingRow(array $existingRow, array $builderItem): array {
    $merged = $existingRow;

    $label = trim((string) ($builderItem['label'] ?? ''));
    if ($label !== '') {
      $merged['label'] = $label;
    }

    if (isset($builderItem['type']) && trim((string) $builderItem['type']) !== '') {
      $merged['type'] = $this->normalizeType((string) $builderItem['type']);
    }

    if (array_key_exists('required', $builderItem)) {
      $merged['required'] = !empty($builderItem['required']);
    }

    if (array_key_exists('options', $builderItem)) {
      if (is_array($builderItem['options'])) {
        $lines = [];
        foreach ($builderItem['options'] as $line) {
          $t = trim((string) $line);
          if ($t !== '') {
            $lines[] = $t;
          }
        }
        $merged['options'] = $lines === [] ? '' : implode("\n", $lines);
      }
      else {
        $merged['options'] = trim((string) $builderItem['options']);
      }
    }

    if (isset($builderItem['machine_name']) && trim((string) $builderItem['machine_name']) !== '') {
      $merged['machine_name'] = trim((string) $builderItem['machine_name']);
    }

    if (isset($builderItem['status']) && trim((string) $builderItem['status']) !== '') {
      $merged['status'] = $this->normalizeStatus((string) $builderItem['status']);
    }

    if (isset($builderItem['applicability']) && trim((string) $builderItem['applicability']) !== '') {
      $merged['applicability'] = $this->normalizeApplicability((string) $builderItem['applicability']);
    }

    if (isset($builderItem['ticket_type_ids']) && is_array($builderItem['ticket_type_ids'])) {
      $merged['ticket_type_ids'] = $this->normalizeTicketTypeIds($builderItem['ticket_type_ids']);
    }

    return $merged;
  }

  /**
   * Builds saveRows() input from builder JSON without dropping unstored questions.
   *
   * @param list<array<string, mixed>> $builderItems
   *
   * @return list<array<string, mixed>>
   */
  public function composeRowsFromBuilderPayload(NodeInterface $event, array $builderItems, ?array $existingRows = NULL): array {
    $existing_rows = $existingRows ?? $this->loadRows($event);
    $by_id = [];
    $by_machine = [];
    $by_label = [];
    foreach ($existing_rows as $row) {
      $id = (int) ($row['id'] ?? 0);
      if ($id < 1) {
        continue;
      }
      $by_id[$id] = $row;
      $machine = trim((string) ($row['machine_name'] ?? ''));
      if ($machine !== '') {
        $by_machine[mb_strtolower($machine)] = $row;
      }
      $label_key = mb_strtolower(trim((string) ($row['label'] ?? '')));
      if ($label_key !== '') {
        $by_label[$label_key] = $row;
      }
    }

    $matched_ids = [];
    $output = [];

    foreach ($builderItems as $index => $item) {
      if (!is_array($item)) {
        continue;
      }

      $match = NULL;
      $builder_id = (int) ($item['id'] ?? 0);
      if ($builder_id > 0 && isset($by_id[$builder_id]) && !isset($matched_ids[$builder_id])) {
        $match = $by_id[$builder_id];
      }

      if ($match === NULL) {
        $machine = trim((string) ($item['machine_name'] ?? ''));
        if ($machine !== '') {
          $machine_key = mb_strtolower($machine);
          if (isset($by_machine[$machine_key]) && !isset($matched_ids[(int) $by_machine[$machine_key]['id']])) {
            $match = $by_machine[$machine_key];
          }
        }
      }

      if ($match === NULL) {
        $label_key = mb_strtolower(trim((string) ($item['label'] ?? '')));
        if ($label_key !== '' && isset($by_label[$label_key]) && !isset($matched_ids[(int) $by_label[$label_key]['id']])) {
          $match = $by_label[$label_key];
        }
      }

      if ($match === NULL && isset($existing_rows[$index]) && !isset($matched_ids[(int) $existing_rows[$index]['id']])) {
        $match = $existing_rows[$index];
      }

      if ($match !== NULL) {
        $merged = $this->mergeBuilderItemOntoExistingRow($match, $item);
        $merged['weight'] = $index;
        $output[] = $merged;
        $matched_ids[(int) $merged['id']] = TRUE;
        continue;
      }

      $new_row = $this->builderItemToRow($item, $index);
      if ($new_row !== NULL) {
        $output[] = $new_row;
      }
    }

    foreach ($existing_rows as $row) {
      $id = (int) ($row['id'] ?? 0);
      if ($id > 0 && !isset($matched_ids[$id])) {
        $output[] = $row;
      }
    }

    usort($output, static fn (array $a, array $b): int => ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0)));

    return $output;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function builderItemToRow(array $item, int $weight): ?array {
    $label = trim((string) ($item['label'] ?? ''));
    if ($label === '') {
      return NULL;
    }

    $options = '';
    if (isset($item['options'])) {
      if (is_array($item['options'])) {
        $lines = [];
        foreach ($item['options'] as $line) {
          $t = trim((string) $line);
          if ($t !== '') {
            $lines[] = $t;
          }
        }
        $options = implode("\n", $lines);
      }
      else {
        $options = trim((string) $item['options']);
      }
    }

    $row = [
      'id' => 0,
      'weight' => $weight,
      'label' => $label,
      'type' => $this->normalizeType((string) ($item['type'] ?? 'textfield')),
      'required' => !empty($item['required']),
      'status' => isset($item['status']) && trim((string) $item['status']) !== ''
        ? $this->normalizeStatus((string) $item['status'])
        : self::STATUS_ACTIVE,
      'applicability' => isset($item['applicability']) && trim((string) $item['applicability']) !== ''
        ? $this->normalizeApplicability((string) $item['applicability'])
        : self::APPLIES_PER_TICKET,
      'ticket_type_ids' => isset($item['ticket_type_ids']) && is_array($item['ticket_type_ids'])
        ? $this->normalizeTicketTypeIds($item['ticket_type_ids'])
        : [],
      'options' => $options,
      'machine_name' => trim((string) ($item['machine_name'] ?? '')),
    ];

    if ($row['machine_name'] === '') {
      $row['machine_name'] = $this->machineNameFromLabel($label);
    }

    return $row;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function builderRowFromVendorQuestion(int $vendorQuestionId, ?AccountInterface $account, int $weight): ?array {
    if ($vendorQuestionId < 1 || $account === NULL) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('vendor_question');
    $template = $storage->load($vendorQuestionId);
    if (!$template instanceof VendorQuestionInterface || !$this->vendorQuestionAccessible($template, $account)) {
      return NULL;
    }

    $options = $template->getOptions();
    $option_lines = [];
    if (is_string($options) && $options !== '') {
      foreach (preg_split('/\R/', $options) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
          $option_lines[] = $line;
        }
      }
    }

    return $this->builderItemToRow([
      'label' => $template->getLabel(),
      'type' => $template->getQuestionType(),
      'required' => $template->isRequired(),
      'options' => $option_lines,
    ], $weight);
  }

  private function vendorQuestionAccessible(VendorQuestionInterface $question, AccountInterface $account): bool {
    if ($account->hasPermission('administer site configuration')) {
      return TRUE;
    }
    $store = $question->getStore();
    if ($store === NULL) {
      return FALSE;
    }
    $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')->loadByProperties([
      'uid' => (int) $account->id(),
    ]);
    $vendor = reset($vendors);
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return FALSE;
    }
    $vendor_store = $vendor->get('field_vendor_store')->entity;
    return $vendor_store && (int) $vendor_store->id() === (int) $store->id();
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
  private function eventHasPaidBooking(NodeInterface $event): bool {
    if (!$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return FALSE;
    }
    return in_array((string) $event->get('field_event_type')->value, ['paid', 'both'], TRUE);
  }

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
