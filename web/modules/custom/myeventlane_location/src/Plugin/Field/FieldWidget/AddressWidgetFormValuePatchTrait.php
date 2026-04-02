<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Normalizes address field POST data for extract/massage.
 *
 * The Address module's massageFormValues() assumes every delta includes an
 * "address" subtree. Hidden widgets (#access FALSE) or partial POST data can
 * omit it. This trait avoids calling that unsafe code path and duplicates its
 * intent with null-safe access.
 */
trait AddressWidgetFormValuePatchTrait {

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state): void {
    $field_name = $this->fieldDefinition->getName();
    $path = array_merge($form['#parents'], [$field_name]);
    $key_exists = NULL;
    $values = NestedArray::getValue($form_state->getValues(), $path, $key_exists);
    if ($key_exists && is_array($values)) {
      $patched = $this->patchSubmittedFieldValues($values, $items);
      NestedArray::setValue($form_state->getValues(), $path, $patched);
    }
    parent::extractFormValues($items, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    if (isset($values['widget']) && is_array($values['widget'])) {
      $values = $values['widget'];
    }
    foreach (array_keys($values) as $key) {
      if (!is_numeric($key)) {
        unset($values[$key]);
      }
    }

    // Same as AddressDefaultWidget::massageFormValues() but without undefined
    // index notices when "address" is missing.
    $new_values = [];
    foreach ($values as $delta => $value) {
      if (!is_array($value)) {
        continue;
      }
      $new_values[$delta] = $value['address'] ?? ['country_code' => ''];
    }

    return $new_values;
  }

  /**
   * Ensures each numeric delta has an "address" key before core extraction.
   *
   * @param array<string, mixed> $values
   *   Submitted values for this field (may include a "widget" wrapper).
   *
   * @return array<string, mixed>
   *   Patched values.
   */
  private function patchSubmittedFieldValues(array $values, FieldItemListInterface $items): array {
    if (isset($values['widget']) && is_array($values['widget'])) {
      foreach ($values['widget'] as $delta => $item) {
        if (!is_numeric($delta) || !is_array($item)) {
          continue;
        }
        if (!array_key_exists('address', $item)) {
          $values['widget'][$delta] = $this->deltaWithAddress($item, (int) $delta, $items);
        }
      }
      return $values;
    }

    foreach ($values as $delta => $item) {
      if (!is_numeric($delta) || !is_array($item)) {
        continue;
      }
      if (!array_key_exists('address', $item)) {
        $values[$delta] = $this->deltaWithAddress($item, (int) $delta, $items);
      }
    }

    return $values;
  }

  /**
   * @param array<string, mixed> $item
   *   Single delta widget values.
   *
   * @return array<string, mixed>
   */
  private function deltaWithAddress(array $item, int $delta, FieldItemListInterface $items): array {
    if (!$items->isEmpty() && $items->offsetExists($delta)) {
      $field_item = $items->get($delta);
      if ($field_item !== NULL && !$field_item->isEmpty()) {
        $item['address'] = $field_item->toArray();
        return $item;
      }
    }

    $item['address'] = ['country_code' => ''];
    return $item;
  }

}
