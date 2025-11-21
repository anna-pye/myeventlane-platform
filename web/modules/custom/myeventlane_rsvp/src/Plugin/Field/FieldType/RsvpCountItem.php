<?php

namespace Drupal\myeventlane_rsvp\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * @FieldType(
 *   id = "rsvp_count",
 *   label = @Translation("RSVP Count (Computed)"),
 *   description = @Translation("Computed count of confirmed RSVPs."),
 *   no_ui = TRUE,
 * )
 */
class RsvpCountItem extends FieldItemBase {

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    return [];
  }

  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [];
  }

  public function isEmpty() {
    return TRUE;
  }
}