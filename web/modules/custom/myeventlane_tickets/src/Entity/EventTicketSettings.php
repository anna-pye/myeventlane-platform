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
 * Defines the Event Ticket Settings entity.
 *
 * Stores per-event ticket configuration settings. Only one settings entity
 * should exist per event (enforced via unique constraint on event field).
 *
 * @ContentEntityType(
 *   id = "mel_event_ticket_settings",
 *   label = @Translation("Event Ticket Settings"),
 *   label_collection = @Translation("Event Ticket Settings"),
 *   label_singular = @Translation("event ticket settings"),
 *   label_plural = @Translation("event ticket settings"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\myeventlane_tickets\Form\EventTicketSettingsForm",
 *       "edit" = "Drupal\myeventlane_tickets\Form\EventTicketSettingsForm"
 *     },
 *     "access" = "Drupal\myeventlane_tickets\EventTicketSettingsAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "mel_event_ticket_settings",
 *   data_table = "mel_event_ticket_settings_field_data",
 *   admin_permission = "administer all events tickets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "edit-form" = "/vendor/events/{event}/tickets/settings"
 *   }
 * )
 */
final class EventTicketSettings extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Event reference (unique per event).
    $fields['event'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Event'))
      ->setDescription(new TranslatableMarkup('The event these settings belong to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => ['event' => 'event'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -10,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->addConstraint('UniqueField', []);

    // Show tickets left.
    $fields['show_tickets_left'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show Tickets Left'))
      ->setDescription(new TranslatableMarkup('Whether to display the number of tickets remaining.'))
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Show prices before tax.
    $fields['show_prices_before_tax'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show Prices Before Tax'))
      ->setDescription(new TranslatableMarkup('Whether to display prices before tax is added.'))
      ->setRequired(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Minimum per order.
    $fields['min_per_order'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Minimum Per Order'))
      ->setDescription(new TranslatableMarkup('Minimum number of tickets required per order.'))
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

    // Maximum per order.
    $fields['max_per_order'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Maximum Per Order'))
      ->setDescription(new TranslatableMarkup('Maximum number of tickets allowed per order.'))
      ->setRequired(FALSE)
      ->setSettings([
        'min' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 15,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Enable unique answers.
    $fields['enable_unique_answers'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enable Unique Answers'))
      ->setDescription(new TranslatableMarkup('Whether to require unique answers for checkout questions per ticket.'))
      ->setRequired(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Status (enabled/disabled).
    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Whether these settings are active.'))
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 25,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 25,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the settings were created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the settings were last updated.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
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
