<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
/**
 * Shared user base field definition for JSON notification preferences.
 */
final class NotificationPrefsFieldDefinition {

  public static function storage(): BaseFieldDefinition {
    return BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('MEL notification preferences (JSON)'))
      ->setDescription(new TranslatableMarkup('Per-category enable flags and surface preferences for MyEventLane notifications.'))
      ->setDefaultValue('')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);
  }

}
