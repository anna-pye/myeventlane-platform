<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Defines the Ticket Group entity.
 *
 * Ticket Groups allow vendors to organize ticket products into logical groups
 * with ordering and presentation control.
 *
 * @ContentEntityType(
 *   id = "mel_ticket_group",
 *   label = @Translation("Ticket Group"),
 *   label_collection = @Translation("Ticket Groups"),
 *   label_singular = @Translation("ticket group"),
 *   label_plural = @Translation("ticket groups"),
 *   label_count = @PluralTranslation(
 *     singular = "@count ticket group",
 *     plural = "@count ticket groups"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "access" = "Drupal\myeventlane_tickets\TicketGroupAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_tickets\Form\TicketGroupForm",
 *       "add" = "Drupal\myeventlane_tickets\Form\TicketGroupForm",
 *       "edit" = "Drupal\myeventlane_tickets\Form\TicketGroupForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     }
 *   },
 *   base_table = "mel_ticket_group",
 *   data_table = "mel_ticket_group_field_data",
 *   admin_permission = "administer all events tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name"
 *   },
 *   links = {
 *     "canonical" = "/vendor/events/{event}/tickets/groups/{mel_ticket_group}",
 *     "add-form" = "/vendor/events/{event}/tickets/groups/add",
 *     "edit-form" = "/vendor/events/{event}/tickets/groups/{mel_ticket_group}/edit",
 *     "delete-form" = "/vendor/events/{event}/tickets/groups/{mel_ticket_group}/delete"
 *   }
 * )
 */
final class TicketGroup extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Name field (label).
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('The name of the ticket group.'))
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

    // Description field.
    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('Optional description for this ticket group.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'text_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Weight field for ordering.
    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDescription(new TranslatableMarkup('The weight of this group for ordering. Lower numbers appear first.'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
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

    // Event reference.
    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event this ticket group belongs to.'))
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

    // Ticket product references (multiple).
    $fields['ticket_products'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Ticket Products'))
      ->setDescription(new TranslatableMarkup('The ticket products in this group.'))
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

    // Enabled status.
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enabled'))
      ->setDescription(new TranslatableMarkup('Whether this ticket group is enabled and visible.'))
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 15,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the ticket group was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the ticket group was last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * Adds the event route parameter for link templates that include {event}.
   */
  protected function urlRouteParameters($rel): array {
    $params = parent::urlRouteParameters($rel);
    $event_id = $this->getEventId();
    if ($event_id !== NULL) {
      $params['event'] = $event_id;
    }
    return $params;
  }

  /**
   * Gets the ticket group name.
   */
  public function getName(): string {
    return $this->get('name')->value ?? '';
  }

  /**
   * Sets the ticket group name.
   */
  public function setName(string $name): static {
    $this->set('name', $name);
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

}
