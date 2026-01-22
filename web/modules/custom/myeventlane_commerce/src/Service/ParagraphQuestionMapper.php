<?php

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 *
 */
class ParagraphQuestionMapper {

  /**
   *
   */
  public function buildElements(EntityInterface $event, array $defaults = []): array {
    $elements = [];
    if (!$event->hasField('field_attendee_questions')) {
      return $elements;
    }

    foreach ($event->get('field_attendee_questions')->referencedEntities() as $para) {
      $machine = $para->get('field_question_machine_name')->value ?? strtolower(preg_replace('/\s+/', '_', $para->label()));
      $type = $para->get('field_question_type')->value ?? 'textfield';
      $required = (bool) ($para->get('field_question_required')->value ?? FALSE);
      $title = $para->get('field_question_label')->value ?? $para->label();
      $help = $para->get('field_question_help')->value ?? '';

      switch ($type) {
        case 'select':
          $options = $this->explodeOptions($para->get('field_question_options')->value ?? '');
          $elements[$machine] = [
            '#type' => 'select',
            '#title' => $title,
            '#options' => $options,
            '#required' => $required,
            '#default_value' => $defaults[$machine] ?? NULL,
            '#description' => $help,
          ];
          break;

        case 'checkboxes':
          $options = $this->explodeOptions($para->get('field_question_options')->value ?? '');
          $elements[$machine] = [
            '#type' => 'checkboxes',
            '#title' => $title,
            '#options' => $options,
            '#required' => $required,
            '#default_value' => $defaults[$machine] ?? [],
            '#description' => $help,
          ];
          break;

        case 'textarea':
          $elements[$machine] = [
            '#type' => 'textarea',
            '#title' => $title,
            '#required' => $required,
            '#default_value' => $defaults[$machine] ?? '',
            '#description' => $help,
          ];
          break;

        default:
          $elements[$machine] = [
            '#type' => 'textfield',
            '#title' => $title,
            '#required' => $required,
            '#default_value' => $defaults[$machine] ?? '',
            '#description' => $help,
          ];
      }
    }

    return $elements;
  }

  /**
   *
   */
  protected function explodeOptions(string $raw): array {
    $out = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
      if ($line === '') {
        continue;
      }
      $out[$line] = $line;
    }
    return $out;
  }

}
