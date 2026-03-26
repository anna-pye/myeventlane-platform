<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Legal Consent Event entity.
 *
 * Immutable append-only audit records for legal consent actions (RSVP,
 * checkout, registration). Humanitix-level audit trail.
 *
 * @ContentEntityType(
 *   id = "legal_consent_event",
 *   label = @Translation("Legal Consent Event"),
 *   base_table = "legal_consent_event",
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "storage_schema" = "Drupal\myeventlane_legal\Entity\LegalConsentEventStorageSchema",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   admin_permission = "administer legal consent events",
 *   translatable = FALSE,
 * )
 */
class LegalConsentEvent extends ContentEntityBase implements LegalConsentEventInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The user who gave consent (if logged in).'))
      ->setSetting('target_type', 'user')
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['email'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Email'))
      ->setDescription(t('Email address at time of consent.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Consent source: rsvp, checkout, or registration.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 32])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['source_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source entity type'))
      ->setDescription(t('Entity type of the source (e.g. rsvp_submission).'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 64])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['source_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Source entity ID'))
      ->setDescription(t('ID of the source entity.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['event_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event ID'))
      ->setDescription(t('Event node ID when consent relates to an RSVP or event-specific flow.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['terms_version'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Terms version'))
      ->setDescription(t('Terms of Service version accepted.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 64])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['privacy_version'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Privacy version'))
      ->setDescription(t('Privacy Policy version accepted.'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 64])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['refund_version'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Refund version'))
      ->setDescription(t('Refund policy version accepted (optional).'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 64])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['accepted'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Accepted'))
      ->setDescription(t('Whether consent was accepted.'))
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 8,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['accepted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Accepted at'))
      ->setDescription(t('Unix timestamp when consent was given.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 9,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['ip_address'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IP address'))
      ->setDescription(t('Client IP at time of consent.'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 45])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['user_agent'] = BaseFieldDefinition::create('string')
      ->setLabel(t('User agent'))
      ->setDescription(t('User agent at time of consent.'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['session_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Session ID'))
      ->setDescription(t('Session identifier (optional).'))
      ->setRequired(FALSE)
      ->setSettings(['max_length' => 255])
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * Legal consent events are immutable. Updates are not allowed.
   */
  public function preSave(EntityStorageInterface $storage): void {
    if (!$this->isNew()) {
      throw new \LogicException('Legal consent events are immutable.');
    }
    parent::preSave($storage);
  }

}
