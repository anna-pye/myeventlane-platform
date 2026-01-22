<?php

declare(strict_types=1);

namespace Drupal\mel_tickets\Entity;

use Drupal\node\NodeInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Access Code entity.
 *
 * Access Codes allow vendors to create hidden ticket products that require
 * a code to access during checkout.
 *
 * @ContentEntityType(
 *   id = "mel_access_code",
 *   label = @Translation("Access Code"),
 *   label_collection = @Translation("Access Codes"),
 *   label_singular = @Translation("access code"),
 *   label_plural = @Translation("access codes"),
 *   label_count = @PluralTranslation(
 *     singular = "@count access code",
 *     plural = "@count access codes"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "access" = "Drupal\mel_tickets\AccessCodeAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\mel_tickets\Form\AccessCodeForm",
 *       "add" = "Drupal\mel_tickets\Form\AccessCodeForm",
 *       "edit" = "Drupal\mel_tickets\Form\AccessCodeForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   },
 *   base_table = "mel_access_code",
 *   data_table = "mel_access_code_field_data",
 *   admin_permission = "administer all events tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "code"
 *   },
 *   links = {
 *     "canonical" = "/vendor/events/{event}/tickets/access-codes/{mel_access_code}",
 *     "add-form" = "/vendor/events/{event}/tickets/access-codes/add",
 *     "edit-form" = "/vendor/events/{event}/tickets/access-codes/{mel_access_code}/edit",
 *     "delete-form" = "/vendor/events/{event}/tickets/access-codes/{mel_access_code}/delete"
 *   }
 * )
 */
final class AccessCode extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * Status constants.
   */
  public const STATUS_ACTIVE = 'active';
  public const STATUS_INACTIVE = 'inactive';
  public const STATUS_EXPIRED = 'expired';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Code field (label).
    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setDescription(new TranslatableMarkup('The access code string. Must be unique per event.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->addConstraint('UniqueField', []);

    // Event reference.
    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event this access code belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => ['event' => 'event'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Allowed ticket product references (multiple).
    $fields['allowed_ticket_products'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Allowed Ticket Products'))
      ->setDescription(new TranslatableMarkup('The ticket products this access code can unlock.'))
      ->setRequired(FALSE)
      ->setCardinality(-1)
      ->setSetting('target_type', 'commerce_product')
      ->setSetting('handler', 'default:commerce_product')
      ->setSetting('handler_settings', [
        'target_bundles' => ['ticket' => 'ticket'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete_tags',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Usage limit.
    $fields['usage_limit'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Usage Limit'))
      ->setDescription(new TranslatableMarkup('Maximum number of times this code can be used. Leave empty for unlimited.'))
      ->setRequired(FALSE)
      ->setSettings([
        'min' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Usage count.
    $fields['usage_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Usage Count'))
      ->setDescription(new TranslatableMarkup('Number of times this code has been used.'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setReadOnly(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Status.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('The status of this access code.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_ACTIVE)
      ->setSettings([
        'allowed_values' => [
          self::STATUS_ACTIVE => 'Active',
          self::STATUS_INACTIVE => 'Inactive',
          self::STATUS_EXPIRED => 'Expired',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 15,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Expires date/time.
    $fields['expires'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Expires'))
      ->setDescription(new TranslatableMarkup('Optional expiration date and time for this access code.'))
      ->setRequired(FALSE)
      ->setSettings([
        'datetime_type' => 'datetime',
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'datetime_default',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the access code was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 25,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the access code was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Gets the access code string.
   */
  public function getCode(): string {
    return $this->get('code')->value ?? '';
  }

  /**
   * Sets the access code string.
   */
  public function setCode(string $code): static {
    $this->set('code', $code);
    return $this;
  }

  /**
   * Gets the event node.
   */
  public function getEvent(): ?NodeInterface {
    return $this->get('event')->entity;
  }

  /**
   * Gets the event ID.
   */
  public function getEventId(): ?int {
    $value = $this->get('event')->target_id;
    return $value ? (int) $value : NULL;
  }

  /**
   * Increments the usage count.
   */
  public function incrementUsage(): static {
    $count = (int) $this->get('usage_count')->value;
    $this->set('usage_count', $count + 1);
    return $this;
  }

  /**
   * Checks if the access code is expired.
   */
  public function isExpired(): bool {
    if ($this->get('expires')->isEmpty()) {
      return FALSE;
    }
    $expires = strtotime($this->get('expires')->value);
    return $expires && $expires < time();
  }

  /**
   * Checks if the access code has reached its usage limit.
   */
  public function hasReachedLimit(): bool {
    if ($this->get('usage_limit')->isEmpty()) {
      return FALSE;
    }
    $limit = (int) $this->get('usage_limit')->value;
    $count = (int) $this->get('usage_count')->value;
    return $limit > 0 && $count >= $limit;
  }

}
