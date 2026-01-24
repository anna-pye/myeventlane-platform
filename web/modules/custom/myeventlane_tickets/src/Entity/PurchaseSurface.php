<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Defines the Purchase Surface entity.
 *
 * Purchase Surfaces are embedded widgets (popup, embedded checkout, collection)
 * that vendors can embed on external sites to sell tickets.
 *
 * @ContentEntityType(
 *   id = "mel_purchase_surface",
 *   label = @Translation("Purchase Surface"),
 *   label_collection = @Translation("Purchase Surfaces"),
 *   label_singular = @Translation("purchase surface"),
 *   label_plural = @Translation("purchase surfaces"),
 *   label_count = @PluralTranslation(
 *     singular = "@count purchase surface",
 *     plural = "@count purchase surfaces"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "access" = "Drupal\myeventlane_tickets\PurchaseSurfaceAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_tickets\Form\PurchaseSurfaceForm",
 *       "add" = "Drupal\myeventlane_tickets\Form\PurchaseSurfaceForm",
 *       "edit" = "Drupal\myeventlane_tickets\Form\PurchaseSurfaceForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   },
 *   base_table = "mel_purchase_surface",
 *   data_table = "mel_purchase_surface_field_data",
 *   admin_permission = "administer all events tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label"
 *   },
 *   links = {
 *     "canonical" = "/vendor/events/{event}/tickets/widgets/{mel_purchase_surface}",
 *     "add-form" = "/vendor/events/{event}/tickets/widgets/add",
 *     "edit-form" = "/vendor/events/{event}/tickets/widgets/{mel_purchase_surface}/edit",
 *     "delete-form" = "/vendor/events/{event}/tickets/widgets/{mel_purchase_surface}/delete"
 *   }
 * )
 */
final class PurchaseSurface extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * Surface type constants.
   */
  public const TYPE_POPUP = 'popup';
  public const TYPE_EMBEDDED_CHECKOUT = 'embedded_checkout';
  public const TYPE_COLLECTION = 'collection';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Label field.
    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('A descriptive label for this widget.'))
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
      ->setDisplayConfigurable('view', TRUE);

    // Event reference.
    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event this widget belongs to.'))
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

    // Surface type.
    $fields['surface_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Surface Type'))
      ->setDescription(new TranslatableMarkup('The type of embedded widget.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::TYPE_POPUP)
      ->setSettings([
        'allowed_values' => [
          self::TYPE_POPUP => 'Popup',
          self::TYPE_EMBEDDED_CHECKOUT => 'Embedded Checkout',
          self::TYPE_COLLECTION => 'Collection',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Embed token (generated, read-only in forms).
    $fields['embed_token'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Embed Token'))
      ->setDescription(new TranslatableMarkup('Unique token for embedding this widget. Generated automatically.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 5,
        'settings' => [
          'placeholder' => 'Auto-generated',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->addConstraint('UniqueField', []);

    // Status.
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Whether this widget is active and can be embedded.'))
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the widget was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the widget was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Generate embed token if not set.
    if ($this->get('embed_token')->isEmpty() || empty($this->get('embed_token')->value)) {
      $token = bin2hex(random_bytes(32));
      $this->set('embed_token', $token);
    }
  }

  /**
   * Gets the label.
   */
  public function getLabel(): string {
    return $this->get('label')->value ?? '';
  }

  /**
   * Sets the label.
   */
  public function setLabel(string $label): static {
    $this->set('label', $label);
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
   * Gets the embed token.
   */
  public function getEmbedToken(): string {
    return $this->get('embed_token')->value ?? '';
  }

  /**
   * Gets the surface type.
   */
  public function getSurfaceType(): string {
    return $this->get('surface_type')->value ?? self::TYPE_POPUP;
  }

}
