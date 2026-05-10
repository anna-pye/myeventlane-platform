<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Immutable operational audit trail for ticket-backed redemptions.
 *
 * @ContentEntityType(
 *   id = "mel_redemption_log",
 *   label = @Translation("Redemption log"),
 *   label_collection = @Translation("Redemption logs"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\myeventlane_tickets\RedemptionLogAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "mel_redemption_log",
 *   admin_permission = "administer myeventlane tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "action_type"
 *   },
 *   links = {
 *     "collection" = "/admin/content/myeventlane/redemption-logs"
 *   }
 * )
 */
final class RedemptionLog extends ContentEntityBase {

  public const ACTION_ADMIT = 'admit';
  public const ACTION_COLLECT = 'collect';
  public const ACTION_VALIDATE = 'validate';
  public const ACTION_REDEEM = 'redeem';
  public const ACTION_VERIFY = 'verify';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['ticket_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Ticket'))
      ->setDescription(t('Ticket-backed entitlement this audit row belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'myeventlane_ticket');

    $fields['staff_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Staff user'))
      ->setDescription(t('User account that performed the operation.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'user');

    $fields['vendor_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Vendor'))
      ->setDescription(t('Vendor scope for the operational action, when available.'))
      ->setRequired(FALSE)
      ->setSetting('target_type', 'myeventlane_vendor');

    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setDescription(t('Event scope for the operation.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node');

    $fields['action_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Action type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::ACTION_ADMIT => 'Admit',
        self::ACTION_COLLECT => 'Collect',
        self::ACTION_VALIDATE => 'Validate',
        self::ACTION_REDEEM => 'Redeem',
        self::ACTION_VERIFY => 'Verify',
      ]);

    $fields['device_identifier'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Device identifier'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 128);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Notes'))
      ->setRequired(FALSE);

    $fields['metadata_json'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Metadata'))
      ->setDescription(t('Structured operational metadata for audit diagnostics.'))
      ->setRequired(FALSE);

    return $fields;
  }

}
