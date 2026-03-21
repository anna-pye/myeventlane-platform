<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Helper class for altering ticket type paragraph forms.
 *
 * MIGRATION_ONLY: ticket_type_config paragraph ticketing removed from config.
 * This alter targets field_ticket_label_mode, field_ticket_sales_start, etc.
 * Kept for legacy paragraph data; harmless no-op when those fields are absent.
 */
final class TicketTypeFormAlter {

  /**
   * Maximum safe recursion depth for form traversal.
   */
  private const MAX_RECURSION_DEPTH = 50;

  /**
   * Alters the ticket type paragraph form to add conditional fields.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function alterForm(array &$form, FormStateInterface $form_state): void {
    // Fix datetime fields that have array values instead of strings.
    // This can happen when paragraphs are created or loaded with corrupted data.
    self::fixDatetimeFieldValues($form, $form_state);

    // For inline entity forms, the field names might be nested.
    // Try multiple selector patterns to handle different form contexts.
    $labelModeSelectors = [
      ':input[name*="[field_ticket_label_mode]"][name*="[value]"]',
      ':input[name*="field_ticket_label_mode"][name*="value"]',
      ':input[name="field_ticket_label_mode[0][value]"]',
    ];

    // Show preset label only when mode is 'preset'.
    if (isset($form['field_ticket_label_preset'])) {
      $form['field_ticket_label_preset']['#states'] = [
        'visible' => [
          $labelModeSelectors[0] => ['value' => 'preset'],
        ],
        'required' => [
          $labelModeSelectors[0] => ['value' => 'preset'],
        ],
      ];
    }

    // Show custom label only when mode is 'custom'.
    if (isset($form['field_ticket_label_custom'])) {
      $form['field_ticket_label_custom']['#states'] = [
        'visible' => [
          $labelModeSelectors[0] => ['value' => 'custom'],
        ],
        'required' => [
          $labelModeSelectors[0] => ['value' => 'custom'],
        ],
      ];
    }
  }

  /**
   * Fixes datetime fields that have array values instead of strings.
   *
   * This prevents the error: "DateTime::createFromFormat(): Argument #2 ($datetime)
   * must be of type string, array given".
   *
   * Note: Entity-level fixes are handled by hook_entity_load() and
   * hook_field_widget_form_alter(). This method handles form-level fixes.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private static function fixDatetimeFieldValues(array &$form, FormStateInterface $form_state): void {
    // Fix datetime fields in nested paragraph forms (inline entity forms).
    // Recursively search for datetime field widgets and fix their default values.
    self::fixDatetimeFieldsRecursive($form);
  }

  /**
   * Recursively fixes datetime field widgets in the form structure.
   *
   * @param array &$form
   *   The form array to process recursively.
   */
  private static function fixDatetimeFieldsRecursive(array &$form, int $depth = 0): void {
    if ($depth > self::MAX_RECURSION_DEPTH) {
      return;
    }

    foreach ($form as $key => &$element) {
      // Only recurse through real child elements; Drupal metadata keys start with '#'.
      if (is_string($key) && str_starts_with($key, '#')) {
        continue;
      }

      if (!is_array($element)) {
        continue;
      }

      // Check if this is a datetime field widget.
      if (isset($element['#field_name']) && in_array($element['#field_name'], ['field_ticket_sales_start', 'field_ticket_sales_end'], TRUE)) {
        // Fix widget default values if they're arrays.
        if (isset($element['widget']) && is_array($element['widget'])) {
          foreach ($element['widget'] as $delta => &$widget_item) {
            if (is_numeric($delta) && is_array($widget_item)) {
              // Fix value field if it's an array.
              if (isset($widget_item['value']) && is_array($widget_item['value'])) {
                $widget_item['value'] = ['#default_value' => NULL];
              }
              // Fix date field if it's an array.
              if (isset($widget_item['value']['date']) && is_array($widget_item['value']['date'])) {
                $widget_item['value']['date'] = ['#default_value' => NULL];
              }
            }
          }
        }
      }

      // Recursively process child elements.
      self::fixDatetimeFieldsRecursive($element, $depth + 1);
    }
    unset($element);
  }

}
