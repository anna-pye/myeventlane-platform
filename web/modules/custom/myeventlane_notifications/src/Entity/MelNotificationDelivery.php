<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_notifications\NotificationSurface;

/**
 * Per-user delivery row for a notification.
 *
 * A unique database constraint on (notification_id, recipient_uid) is applied
 * via myeventlane_notifications_apply_delivery_schema() to prevent duplicate
 * deliveries under retries and concurrent workers.
 *
 * @ContentEntityType(
 *   id = "mel_notification_delivery",
 *   label = @Translation("MEL notification delivery"),
 *   label_collection = @Translation("MEL notification deliveries"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\myeventlane_notifications\Entity\MelNotificationDeliveryAccessControlHandler",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder"
 *   },
 *   base_table = "mel_notification_delivery",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "id"
 *   },
 *   links = {
 *     "canonical" = "/myeventlane/notifications/delivery/{mel_notification_delivery}"
 *   }
 * )
 */
final class MelNotificationDelivery extends ContentEntityBase {

  public const STATUS_PENDING = 'pending';
  public const STATUS_SENT = 'sent';
  public const STATUS_READ = 'read';
  public const STATUS_FAILED = 'failed';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['notification_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Notification'))
      ->setSetting('target_type', 'mel_notification')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['recipient_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_PENDING)
      ->setSetting('allowed_values', [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_SENT => 'Sent',
        self::STATUS_READ => 'Read',
        self::STATUS_FAILED => 'Failed',
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['delivered_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Delivered at'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['read_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Read at'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['clicked_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Action clicked at'))
      ->setDescription(new TranslatableMarkup('Set when the user follows the notification action link.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['suppressed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Suppressed from UI'))
      ->setDescription(new TranslatableMarkup('When true, this delivery is hidden from bell, inbox, and toasts (user preference).'))
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['grouped'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Grouped delivery'))
      ->setDescription(new TranslatableMarkup('True when the source notification had a non-empty group key at dispatch.'))
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $surface_values = [];
    foreach (NotificationSurface::allowed() as $key) {
      $surface_values[$key] = $key;
    }

    $fields['surface'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('UI surface'))
      ->setDescription(new TranslatableMarkup('Controls toast vs bell emphasis for this delivery (decision engine).'))
      ->setRequired(TRUE)
      ->setDefaultValue(NotificationSurface::TOAST_INBOX)
      ->setSetting('allowed_values', $surface_values)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

}
