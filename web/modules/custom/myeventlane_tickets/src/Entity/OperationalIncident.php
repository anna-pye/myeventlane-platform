<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Staff operational coordination record (not entitlement truth).
 *
 * @ContentEntityType(
 *   id = "mel_operational_incident",
 *   label = @Translation("Operational incident"),
 *   label_collection = @Translation("Operational incidents"),
 *   label_singular = @Translation("operational incident"),
 *   label_plural = @Translation("operational incidents"),
 *   label_count = @PluralTranslation(
 *     singular = "@count operational incident",
 *     plural = "@count operational incidents"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\myeventlane_tickets\Access\OperationalIncidentEntityAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "mel_operational_incident",
 *   data_table = "mel_operational_incident_field_data",
 *   admin_permission = "manage mel operational incidents",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "incident_id"
 *   },
 *   links = {
 *     "collection" = "/admin/mel/operations/incidents"
 *   }
 * )
 */
final class OperationalIncident extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['incident_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Incident correlation id'))
      ->setDescription(new TranslatableMarkup('Stable machine-safe correlation token for this coordination row.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->addConstraint('UniqueField', []);

    $fields['incident_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Incident type'))
      ->setDescription(new TranslatableMarkup('Normalized incident type token.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['severity'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Severity'))
      ->setDescription(new TranslatableMarkup('Coordination severity token: low, moderate, elevated, severe, critical.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDefaultValue('low');

    $fields['lifecycle_state'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Lifecycle state'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDefaultValue('detected');

    $fields['acknowledgement_state'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Acknowledgement state'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDefaultValue('unassigned');

    $fields['escalation_state'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Escalation state'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDefaultValue('none');

    $fields['assigned_uid'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Assigned user id'))
      ->setDescription(new TranslatableMarkup('Operational owner uid; no profile or email is stored on this entity.'))
      ->setRequired(FALSE);

    $fields['event_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Event node id'))
      ->setRequired(FALSE);

    $fields['order_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Commerce order id'))
      ->setRequired(FALSE);

    $fields['ticket_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Ticket entity id'))
      ->setRequired(FALSE);

    $fields['operational_snapshot'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Operational snapshot'))
      ->setDescription(new TranslatableMarkup('JSON snapshot of sanitized operational projections only (no QR, replay, or PII).'))
      ->setRequired(FALSE);

    $fields['suppression_reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Suppression reason'))
      ->setDescription(new TranslatableMarkup('Required coordination rationale when lifecycle is suppressed; staff text without purchaser PII.'))
      ->setRequired(FALSE);

    $fields['workflow_metadata'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Workflow metadata'))
      ->setDescription(new TranslatableMarkup('JSON object of machine-safe coordination metadata (scalar values preferred).'))
      ->setRequired(FALSE);

    $fields['operational_descriptors'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Operational descriptors'))
      ->setDescription(new TranslatableMarkup('JSON array of machine tokens describing operational context.'))
      ->setRequired(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    $fields['resolved_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Resolved at'))
      ->setRequired(FALSE);

    $fields['resolution_notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Resolution notes'))
      ->setDescription(new TranslatableMarkup('Staff coordination notes; must not contain purchaser PII.'))
      ->setRequired(FALSE);

    return $fields;
  }

}
