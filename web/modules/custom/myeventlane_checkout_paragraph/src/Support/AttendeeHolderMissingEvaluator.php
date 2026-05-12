<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Support;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\myeventlane_checkout_paragraph\CheckoutAttendeeSchemaInspectorInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Computes missing required attendee answers for ticket holders.
 *
 * Shared by {@see \Drupal\myeventlane_checkout_flow\Service\MelCheckoutAttendeeRequirementResolver}
 * and {@see \Drupal\myeventlane_checkout_paragraph\Plugin\Commerce\CheckoutPane\TicketHolderParagraphPane}
 * so checkout validation, resolver truth, and presentation stay aligned.
 */
final class AttendeeHolderMissingEvaluator {

  /**
   * Order-wide unique missing summaries (resolver contract).
   *
   * @return list<string>
   */
  public static function missingSummariesForOrder(OrderInterface $order, CheckoutAttendeeSchemaInspectorInterface $schema): array {
    $missing = [];
    foreach ($order->getItems() as $itemIndex => $order_item) {
      if (!$schema->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      $quantity = (int) $order_item->getQuantity();
      for ($delta = 0; $delta < $quantity; $delta++) {
        foreach (static::missingForHolder($order_item, $delta, $schema, (int) $itemIndex) as $m) {
          $missing[] = $m;
        }
      }
    }
    return array_values(array_unique($missing));
  }

  /**
   * Missing required field summaries for one holder on an order item.
   *
   * @return list<string>
   */
  public static function missingForHolder(OrderItemInterface $order_item, int $delta, CheckoutAttendeeSchemaInspectorInterface $schema, int $itemIndex): array {
    if (!$schema->shouldCollectTicketHolders($order_item)) {
      return [];
    }
    $quantity = (int) $order_item->getQuantity();
    if ($delta < 0 || $delta >= $quantity) {
      return ['identity'];
    }
    $holders = $order_item->get('field_ticket_holder')->referencedEntities();
    $templates = $schema->getMergedQuestionTemplatesForOrderItem($order_item);
    $holder = $holders[$delta] ?? NULL;
    if (!$holder instanceof ParagraphInterface) {
      $missing = ['identity'];
      foreach (static::questionLabels($templates) as $label) {
        $missing[] = $label;
      }
      return $missing;
    }

    $missing = [];
    foreach (['field_first_name' => 'first_name', 'field_last_name' => 'last_name', 'field_email' => 'email'] as $field => $slug) {
      $val = $holder->hasField($field) ? trim((string) ($holder->get($field)->value ?? '')) : '';
      if ($val === '') {
        $missing[] = $slug;
      }
    }

    $question_sources = ($holder->hasField('field_attendee_questions') && !$holder->get('field_attendee_questions')->isEmpty())
      ? $holder->get('field_attendee_questions')->referencedEntities()
      : $templates;

    foreach ($question_sources as $q_index => $question) {
      if (!$question instanceof ParagraphInterface) {
        continue;
      }
      if (!static::questionIsRequired($question)) {
        continue;
      }
      $answered = TRUE;
      if ($question->hasField('field_attendee_extra_field')) {
        $answered = !$question->get('field_attendee_extra_field')->isEmpty()
          && !static::isEmptySubmittedAnswer($question->get('field_attendee_extra_field')->value);
      }
      else {
        $answered = FALSE;
      }
      if (!$answered) {
        $missing[] = static::questionFingerprint($question, $itemIndex, $delta, (int) $q_index);
      }
    }

    return $missing;
  }

  /**
   * @param \Drupal\paragraphs\ParagraphInterface[] $questions
   *
   * @return list<string>
   */
  private static function questionLabels(array $questions): array {
    $out = [];
    foreach ($questions as $q) {
      if ($q instanceof ParagraphInterface && static::questionIsRequired($q)) {
        $out[] = static::questionFingerprint($q, 0, 0, 0);
      }
    }
    return $out;
  }

  private static function questionFingerprint(ParagraphInterface $question, int $itemIndex, int $delta, int $q_index): string {
    $label = $question->hasField('field_question_label')
      ? trim((string) ($question->get('field_question_label')->value ?? ''))
      : '';
    if ($label !== '') {
      return 'question:' . $label;
    }
    return 'question_slot:' . $itemIndex . ':' . $delta . ':' . $q_index;
  }

  private static function isEmptySubmittedAnswer(mixed $value): bool {
    if ($value === NULL || $value === FALSE) {
      return TRUE;
    }
    if (is_array($value)) {
      foreach ($value as $item) {
        if ($item !== NULL && $item !== FALSE && $item !== 0 && $item !== '0' && $item !== '') {
          return FALSE;
        }
      }
      return TRUE;
    }
    return is_string($value) ? trim((string) $value) === '' : FALSE;
  }

  private static function questionIsRequired(ParagraphInterface $question): bool {
    return $question->hasField('field_question_required')
      && !empty($question->get('field_question_required')->value);
  }

}
