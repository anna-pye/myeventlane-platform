<?php

namespace Drupal\myeventlane_views\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Base class for Views fields that safely handle null values.
 *
 * This field handler ensures null values are converted to empty strings
 * before being passed to Html::escape() to prevent TypeError exceptions.
 */
abstract class SafeFieldBase extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function renderText($alter) {
    // Ensure all replacement values in the alter array are strings, not null.
    if (isset($alter['replacements']) && is_array($alter['replacements'])) {
      foreach ($alter['replacements'] as $key => $value) {
        if ($value === NULL) {
          $alter['replacements'][$key] = '';
        }
      }
    }
    // Also check the text itself if it's an array.
    if (isset($alter['text']) && is_array($alter['text'])) {
      foreach ($alter['text'] as $key => $value) {
        if ($value === NULL) {
          $alter['text'][$key] = '';
        }
      }
    }
    return parent::renderText($alter);
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $values, $field = NULL) {
    $value = parent::getValue($values, $field);
    // Convert null to empty string to prevent Html::escape() errors.
    return $value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function advancedRender(ResultRow $values) {
    $value = parent::advancedRender($values);
    // Ensure the final rendered value is never null.
    if ($value === NULL) {
      return '';
    }
    return $value;
  }

}
