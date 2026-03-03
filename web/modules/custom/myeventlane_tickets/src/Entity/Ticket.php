<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Issued ticket entity (one per entitlement).
 *
 * @ContentEntityType(
 *   id = "myeventlane_ticket",
 *   label = @Translation("Ticket"),
 *   label_collection = @Translation("Tickets"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   },
 *   base_table = "myeventlane_ticket",
 *   data_table = "myeventlane_ticket_field_data",
 *   admin_permission = "administer myeventlane tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "ticket_code",
 *     "owner" = "purchaser_uid"
 *   },
 *   links = {
 *     "collection" = "/admin/content/myeventlane/tickets"
 *   }
 * )
 */
final class Ticket extends ContentEntityBase {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const STATUS_ISSUED_UNASSIGNED = 'issued_unassigned';
  public const STATUS_ASSIGNED = 'assigned';
  public const STATUS_CHECKED_IN = 'checked_in';
  public const STATUS_REFUNDED = 'refunded';
  public const STATUS_VOID = 'void';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Ticket code used for QR/PDF lookup. Must be unique + unguessable.
    $fields['ticket_code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Ticket code'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 0])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE);

    // Event node reference.
    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 1])
      ->setDisplayConfigurable('view', TRUE);

    // Commerce references (nullable for RSVP if you later link RSVP to tickets).
    $fields['order_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Order'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_order');

    $fields['order_item_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Order item'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_order_item');

    $fields['purchased_entity'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Purchased variation'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'commerce_product_variation');

    // Optional pointer to the paragraph ticket type config on the event.
    $fields['ticket_type_config'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Ticket type config (paragraph)'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'paragraph');

    // Purchaser = entity owner.
    $fields['purchaser_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Purchaser'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user');

    // Holder identity: required before check-in and before PDF access if you want.
    $fields['holder_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Holder name'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 255);

    $fields['holder_email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Holder email'))
      ->setRequired(FALSE);

    // Status lifecycle.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::STATUS_ISSUED_UNASSIGNED => 'Issued (unassigned)',
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_CHECKED_IN => 'Checked in',
        self::STATUS_REFUNDED => 'Refunded',
        self::STATUS_VOID => 'Void',
      ])
      ->setDefaultValue(self::STATUS_ISSUED_UNASSIGNED);

    $fields['checked_in_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Checked in at'))
      ->setRequired(FALSE);

    $fields['checked_in_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Checked in by'))
      ->setDescription(t('User account that performed ticket check-in.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
