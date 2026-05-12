<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Builds RSVP inputs from event-level attendee question templates.
 *
 * Templates are {@see \Drupal\paragraphs\ParagraphInterface} items in
 * node.event.field_attendee_questions (bundle attendee_extra_field). Saved
 * answers use the same structured extra_data shape as paid checkout (label +
 * value per stable machine key), aligned with OrderCompletedSubscriber.
 */
final class EventAttendeeQuestionCaptureService {

  use StringTranslationTrait;

  /**
   * Loads question template paragraphs from the event node.
   *
   * @return list<EntityInterface>
   */
  public function getTemplatesFromEvent(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      return [];
    }
    if (!$event->hasField('field_attendee_questions') || $event->get('field_attendee_questions')->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
      if ($paragraph instanceof EntityInterface
        && $paragraph->getEntityTypeId() === 'paragraph'
        && $paragraph->bundle() === 'attendee_extra_field'
        && $this->templateIsActiveForRsvp($paragraph)) {
        $out[] = $paragraph;
      }
    }
    return $out;
  }

  /**
   * Form API fragment under #tree parent key mel_rsvp_event_questions.
   *
   * @param list<EntityInterface> $templates
   *
   * @return array<string, mixed>
   */
  public function buildFormElements(array $templates): array {
    if ($templates === []) {
      return [];
    }
    $elements = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-rsvp-event-questions']],
    ];
    foreach ($templates as $i => $paragraph) {
      $key = 'q_' . $i;
      $label = $paragraph->hasField('field_question_label')
        ? trim((string) ($paragraph->get('field_question_label')->value ?? ''))
        : '';
      if ($label === '') {
        $label = (string) $this->t('Question @n', ['@n' => (string) ($i + 1)]);
      }
      $required = $paragraph->hasField('field_question_required')
        && !empty($paragraph->get('field_question_required')->value);
      $type = $paragraph->hasField('field_question_type')
        ? (string) ($paragraph->get('field_question_type')->value ?? 'textfield')
        : 'textfield';
      $help = $paragraph->hasField('field_question_help')
        ? trim((string) ($paragraph->get('field_question_help')->value ?? ''))
        : '';
      $optionsText = $paragraph->hasField('field_question_options')
        ? trim((string) ($paragraph->get('field_question_options')->value ?? ''))
        : '';
      $opts = $this->parseOptionsLines($optionsText);

      $description = $help !== '' ? $help : NULL;

      $elements[$key] = match ($type) {
        'textarea' => [
          '#type' => 'textarea',
          '#title' => $label,
          '#required' => $required,
          '#description' => $description,
        ],
        'select' => [
          '#type' => 'select',
          '#title' => $label,
          '#options' => ['' => (string) $this->t('- Select -')] + $opts,
          '#required' => $required,
          '#description' => $description,
        ],
        'checkboxes' => [
          '#type' => 'checkboxes',
          '#title' => $label,
          '#options' => $opts !== [] ? $opts : [(string) $this->t('Option') => (string) $this->t('Option')],
          '#required' => $required,
          '#description' => $description,
        ],
        'radios' => [
          '#type' => 'radios',
          '#title' => $label,
          '#options' => $opts !== [] ? $opts : [(string) $this->t('Option') => (string) $this->t('Option')],
          '#required' => $required,
          '#description' => $description,
        ],
        'email' => [
          '#type' => 'email',
          '#title' => $label,
          '#required' => $required,
          '#description' => $description,
        ],
        'number' => [
          '#type' => 'number',
          '#title' => $label,
          '#required' => $required,
          '#description' => $description,
        ],
        'tel' => [
          '#type' => 'tel',
          '#title' => $label,
          '#required' => $required,
          '#description' => $description,
        ],
        default => [
          '#type' => 'textfield',
          '#title' => $label,
          '#required' => $required,
          '#description' => $description,
        ],
      };
    }
    return $elements;
  }

  /**
   * @param list<EntityInterface> $templates
   * @param array<string, mixed> $values
   *   Values under mel_rsvp_event_questions (e.g. q_0 => answer).
   */
  public function validateRequired(array $templates, array $values, FormStateInterface $form_state): void {
    foreach ($templates as $i => $paragraph) {
      $required = $paragraph->hasField('field_question_required')
        && !empty($paragraph->get('field_question_required')->value);
      if (!$required) {
        continue;
      }
      $key = 'q_' . $i;
      $raw = $values[$key] ?? NULL;
      if ($this->isEmptyAnswer($paragraph, $raw)) {
        $form_state->setErrorByName('mel_rsvp_event_questions][' . $key, (string) $this->t('This question is required.'));
      }
    }
  }

  /**
   * @param list<EntityInterface> $templates
   * @param array<string, mixed> $values
   *
   * @return array<string, array{label: string, value: string}>
   */
  public function buildStructuredExtraData(array $templates, array $values): array {
    $extra = [];
    $usedKeys = [];
    foreach ($templates as $i => $paragraph) {
      $key = 'q_' . $i;
      $raw = $values[$key] ?? NULL;
      $valueString = $this->normalizeAnswerValue($paragraph, $raw);
      if ($valueString === '') {
        continue;
      }
      $label = $paragraph->hasField('field_question_label')
        ? trim((string) ($paragraph->get('field_question_label')->value ?? ''))
        : '';
      $mapKey = $this->buildStableMapKey($paragraph, $i, $usedKeys);
      $displayLabel = $label !== '' ? $label : $mapKey;
      $extra[$mapKey] = [
        'label' => $displayLabel,
        'value' => $valueString,
      ];
    }
    return $extra;
  }

  /**
   * @return array<string, string>
   */
  private function parseOptionsLines(string $text): array {
    $lines = preg_split('/\R/', $text) ?: [];
    $opts = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $opts[$line] = $line;
    }
    return $opts;
  }

  private function isEmptyAnswer(EntityInterface $paragraph, mixed $raw): bool {
    $type = $paragraph->hasField('field_question_type')
      ? (string) ($paragraph->get('field_question_type')->value ?? '')
      : '';
    if ($type === 'checkboxes') {
      if (!is_array($raw)) {
        return TRUE;
      }
      foreach ($raw as $v) {
        if ($v !== 0 && $v !== FALSE && $v !== '' && $v !== NULL) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return $raw === NULL || $raw === '' || $raw === [];
  }

  private function normalizeAnswerValue(EntityInterface $paragraph, mixed $raw): string {
    if ($raw === NULL) {
      return '';
    }
    if (is_bool($raw)) {
      return $raw ? '1' : '';
    }
    $type = $paragraph->hasField('field_question_type')
      ? (string) ($paragraph->get('field_question_type')->value ?? '')
      : '';
    if (is_array($raw)) {
      if ($type !== 'checkboxes') {
        return '';
      }
      $filtered = array_filter($raw, static fn ($v) => $v !== 0 && $v !== FALSE && $v !== '' && $v !== NULL);
      if ($filtered === []) {
        return '';
      }
      $parts = [];
      foreach ($filtered as $v) {
        $parts[] = trim((string) $v);
      }
      return implode(', ', array_filter($parts));
    }
    return trim((string) $raw);
  }

  /**
   * @param array<string, bool> $usedKeys
   */
  private function buildStableMapKey(EntityInterface $question, int $qIndex, array &$usedKeys): string {
    $base = 'question_' . $qIndex;
    if ($question->hasField('field_question_machine_name')) {
      $raw = trim((string) ($question->get('field_question_machine_name')->value ?? ''));
      if ($raw !== '') {
        $sanitized = strtolower((string) (preg_replace('/[^a-z0-9_]+/i', '_', $raw) ?? ''));
        $sanitized = trim((string) preg_replace('/_+/', '_', $sanitized), '_');
        if ($sanitized !== '') {
          $base = $sanitized;
        }
      }
    }
    $candidate = $base;
    $suffix = 0;
    while (isset($usedKeys[$candidate])) {
      $suffix++;
      $candidate = $base . '_' . $suffix;
    }
    $usedKeys[$candidate] = TRUE;
    return $candidate;
  }

  private function templateIsActiveForRsvp(EntityInterface $paragraph): bool {
    if ($paragraph->hasField('field_question_status')
      && !$paragraph->get('field_question_status')->isEmpty()
      && (string) $paragraph->get('field_question_status')->value === 'archived') {
      return FALSE;
    }
    if ($paragraph->hasField('field_question_applicability') && !$paragraph->get('field_question_applicability')->isEmpty()) {
      $applicability = (string) $paragraph->get('field_question_applicability')->value;
      return $applicability === 'per_ticket';
    }
    return TRUE;
  }

}
